<?php

declare(strict_types=1);

use ApiSutra\Config\ClientConfig;
use ApiSutra\Config\RetryConfig;
use ApiSutra\Config\OAuth2Config;
use ApiSutra\Auth\OAuth2\OAuth2Authenticator;
use ApiSutra\Auth\OAuth2\Internal\TokenRequest;
use ApiSutra\Exceptions\Transport\ConnectionException;
use ApiSutra\Execution\Async\AsyncRuntime;
use GuzzleHttp\Promise\CancellationException;
use ApiSutra\Laravel\Tests\Stubs\Queue\QuotaBackend;
use ApiSutra\Timing\CooperativeSleeper;
use ApiSutra\Exceptions\Execution\PoolConsumptionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Carbon;
use Revolt\EventLoop;
use ApiSutra\Config\RateLimitConfig;
use ApiSutra\Config\ResultExceptionConfig;
use ApiSutra\Contracts\Interfaces\Errors\ExecutionExceptionFactoryInterface;
use ApiSutra\Laravel\Queue\ReleaseOnSdkThrottle;
use ApiSutra\Laravel\Tests\Stubs\Queue\WorkerEnvironment;
use ApiSutra\Laravel\Tests\Stubs\Queue\QueueOperation;
use ApiSutra\Laravel\Tests\Stubs\Requests\DiRequest;
use ApiSutra\Laravel\Tests\Stubs\TestClient;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Resolver\ClientRegistry;
use ApiSutra\Transport\MockTransport;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Cache\CacheServiceProvider;
use ApiSutra\Laravel\Observability\ObservationManager;
use ApiSutra\Exceptions\Configuration\ConfigurationException;
use Illuminate\Translation\TranslationServiceProvider;
use Illuminate\Filesystem\Filesystem;

it('отклоняет job без release до handle с сообщением на языке приложения', function (string $locale, string $message): void {
    $environment = new WorkerEnvironment();
    $environment->app['config']->set('app.locale', $locale);
    $environment->app['config']->set('app.fallback_locale', 'en');
    $environment->app->instance('files', new Filesystem());
    $environment->app->register(TranslationServiceProvider::class);
    $called = false;
    $privateRelease = new class {
        private function release(): void {}
    };
    foreach ([new stdClass(), $privateRelease] as $job) {
        expect(fn () => new ReleaseOnSdkThrottle()->handle($job, static function () use (&$called): void {
            $called = true;
        }))->toThrow(ConfigurationException::class, $message);
    }
    expect($called)->toBeFalse();
})->with([
    ['en', 'requires a public release() method'],
    ['ru', 'требует публичный метод release()'],
]);

it('останавливает обычный handle до маркера при проигнорированном raw, минуя фабрику', function (bool $throw, string $mode): void {
    $environment = new WorkerEnvironment();
    $factory = $this->createMock(ExecutionExceptionFactoryInterface::class);
    $factory->expects($this->never())->method('make')->willReturnCallback(static fn () => match ($mode) {
        'null' => null,
        'throws' => throw new LogicException('factory'),
        default => new RuntimeException('custom'),
    });
    $transport = new MockTransport();
    $transport->fake([DiRequest::class => MockResponse::success()]);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example', throwOnErrors: $throw,
        rateLimit: new RateLimitConfig(limit: 1, period: 30), resultExceptions: new ResultExceptionConfig(exceptionFactory: $factory)), $transport);
    $client->send(new DiRequest());
    $after = 0;
    $environment->app->instance('job.operation', static function () use ($client, &$after): void {
        $client->send(new DiRequest())->raw();
        $after++;
    });
    $job = $environment->queue->job(new QueueOperation([new ReleaseOnSdkThrottle()]));
    $environment->worker->process('memory', $job, new WorkerOptions());
    expect($after)->toBe(0)->and($job->delays)->toHaveCount(1)->and($job->delays[0])->toBeGreaterThanOrEqual(29)
        ->and($job->hasFailed())->toBeFalse()->and($job->attempts())->toBe(1);
    $transport->assertSent(DiRequest::class, times: 1);
})->with([false, true])->with(['returns', 'null', 'throws']);

it('не связывает release с Event::fake или переполнением наблюдения', function (bool $fakeEvents): void {
    $environment = new WorkerEnvironment();
    $environment->app['config']->set('apisutra.integration.observation', ['enabled' => true, 'max_snapshots' => 1]);
    if ($fakeEvents) {
        $environment->app->register(CacheServiceProvider::class);
        Event::fake();
    }
    $manager = $environment->app->make(ObservationManager::class);
    $manager->begin('test');
    $backend = new QuotaBackend(grants: 2);
    $transport = new MockTransport();
    $transport->fake([DiRequest::class => MockResponse::success()]);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example', rateLimit: new RateLimitConfig(), rateLimitBackend: $backend), $transport);
    $client->send(new DiRequest());
    $client->send(new DiRequest());
    $after = 0;
    $environment->app->instance('job.operation', static function () use ($client, &$after): void {
        $client->sendAsync(new DiRequest())->wait()->raw();
        $after++;
    });
    $job = $environment->queue->job(new QueueOperation([new ReleaseOnSdkThrottle()]));
    $environment->worker->process('memory', $job, new WorkerOptions());
    expect($after)->toBe(0)->and($job->delays)->toHaveCount(1)->and($manager->isIdle())->toBeTrue();
    $manager->close();
})->with([false, true]);

it('дренирует начатое окно и доставляет оплаченные ответы перед единственным release', function (string $terminal, bool $handlerFails): void {
    $environment = new WorkerEnvironment();
    $backend = new QuotaBackend(grants: 2, delayMs: 1050);
    $transport = new MockTransport();
    $active = 0;
    $transport->fake([DiRequest::class => static function () use (&$active): MockResponse {
        $active++;
        try {
            (new CooperativeSleeper())->sleepMs(70);
            return MockResponse::success();
        } finally { $active--; }
    }]);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example', rateLimit: new RateLimitConfig(), rateLimitBackend: $backend), $transport);
    $environment->app->make(ClientRegistry::class)->register($client, 'ApiSutra\\Laravel\\Tests\\Stubs\\Requests');
    $delivered = 0;
    $after = 0;
    $environment->app->instance('job.operation', static function () use ($client, $terminal, $handlerFails, &$after, &$delivered): void {
        $pool = $client->pool(array_map(static fn () => new DiRequest(), range(1, 20)), 5)
            ->withResponseHandler(static function () use (&$delivered, $handlerFails): void {
                $delivered++;
                if ($handlerFails) { throw new RuntimeException('handler'); }
            });
        $value = $pool->$terminal();
        if (str_ends_with($terminal, 'Async')) { $value->wait(); }
        $after++;
    });
    $before = EventLoop::getIdentifiers();
    $job = $environment->queue->job(new QueueOperation([new ReleaseOnSdkThrottle()]));
    try {
        $environment->worker->process('memory', $job, new WorkerOptions());
    } catch (Throwable $exception) {
        if (!$handlerFails) { throw $exception; }
        expect($exception instanceof PoolConsumptionException ? $exception->getPrevious()?->getMessage() : $exception->getMessage())->toBe('handler');
    }
    expect($after)->toBe(0)->and($active)->toBe(0)->and($backend->calls)->toBe(3)
        ->and($delivered)->toBe($handlerFails ? 1 : 2)->and(EventLoop::getIdentifiers())->toBe($before);
    if ($handlerFails) {
        expect($job->hasFailed())->toBeTrue()->and($job->delays)->toBe([]);
    } else {
        expect($job->delays)->toBe([1]);
    }
})->with(['send', 'consume', 'sendAsync', 'consumeAsync'])->with([false, true]);

it('уважает Laravel attempts, retryUntil и maxExceptions, не сокращая запрет', function (): void {
    $environment = new WorkerEnvironment();
    $backend = new QuotaBackend(delayMs: 30500);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example', rateLimit: new RateLimitConfig(), rateLimitBackend: $backend), new MockTransport());
    $environment->app->instance('job.operation', static fn () => $client->sendAsync(new DiRequest())->wait()->raw());
    $command = new QueueOperation([new ReleaseOnSdkThrottle()]);
    $command->until = time() + 5;
    $job = $environment->queue->job($command);
    $job->attempt = 2;
    $environment->worker->process('memory', $job, new WorkerOptions());
    expect($job->delays)->toBe([31])->and($job->attempts())->toBe(2)->and($job->hasFailed())->toBeFalse()
        ->and($job->retryUntil())->toBe($command->until);
    $expired = $environment->queue->job($command);
    Carbon::setTestNow(Carbon::now()->addSeconds(6));
    try {
        $environment->worker->process('memory', $expired, new WorkerOptions());
    } catch (MaxAttemptsExceededException) {
    } finally { Carbon::setTestNow(); }
    expect($expired->hasFailed())->toBeTrue()->and($expired->delays)->toBe([])->and($backend->calls)->toBe(1);
});

it('не считает проглоченный приложением сигнал успехом job', function (): void {
    $environment = new WorkerEnvironment();
    $backend = new QuotaBackend();
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example', rateLimit: new RateLimitConfig(), rateLimitBackend: $backend), new MockTransport());
    $after = 0;
    $environment->app->instance('job.operation', static function () use ($client, &$after): void {
        try { $client->send(new DiRequest()); } catch (Throwable) { }
        $after++;
    });
    $job = $environment->queue->job(new QueueOperation([new ReleaseOnSdkThrottle()]));
    $environment->worker->process('memory', $job, new WorkerOptions());
    expect($after)->toBe(1)->and($job->delays)->toHaveCount(1);
});

it('различает локальный отказ и другие исходы, включая отказ после retry и auth', function (string $scenario): void {
    $environment = new WorkerEnvironment();
    $backend = new QuotaBackend(grants: in_array($scenario, ['retry', 'auth_after'], true) ? 1 : 0);
    if ($scenario === 'backend') { $backend->failure = new RuntimeException('redis down'); }
    $transport = new MockTransport();
    $transport->fake([
        DiRequest::class => static function () use ($scenario): MockResponse {
            if ($scenario === 'network') { throw new ConnectionException('connection lost'); }
            if ($scenario === 'deadline') { (new CooperativeSleeper())->sleepMs(8); }
            if ($scenario === 'cancel') { (new CooperativeSleeper())->sleepMs(1000); }
            return in_array($scenario, ['bare429', 'cooldown'], true) ? MockResponse::rateLimited(10)
                : ($scenario === 'retry' ? MockResponse::serverError() : MockResponse::success());
        },
        TokenRequest::class => MockResponse::success(['access_token' => 'token', 'token_type' => 'Bearer']),
    ]);
    $quota = in_array($scenario, ['quota', 'retry', 'auth_before', 'auth_after', 'backend'], true);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example',
        rateLimit: $quota ? new RateLimitConfig() : null, rateLimitBackend: $quota ? $backend : null,
        auth: str_starts_with($scenario, 'auth') ? OAuth2Authenticator::clientCredentials(new OAuth2Config('https://id.test/token', 'client', 'secret')) : null,
        retry: new RetryConfig(attempts: $scenario === 'retry' ? 2 : 1, baseDelay: 0, jitter: false, totalTimeoutMs: $scenario === 'deadline' ? 2 : null)), $transport);
    $after = 0;
    $environment->app->instance('job.operation', static function () use ($client, $scenario, &$after): void {
        if ($scenario === 'cancel') {
            $pending = $client->sendAsync(new DiRequest());
            $pending->cancel();
            try { $pending->wait(); } finally {
                // Cleanup отмены обслуживает цикл приложения, как и без middleware.
                (new AsyncRuntime())->start(static fn () => (new CooperativeSleeper())->sleepMs(1))->wait();
            }
        } else {
            $client->send(new DiRequest())->raw();
            if ($scenario === 'cooldown') { $client->send(new DiRequest())->raw(); }
        }
        $after++;
    });
    $job = $environment->queue->job(new QueueOperation([new ReleaseOnSdkThrottle()]));
    try { $environment->worker->process('memory', $job, new WorkerOptions()); } catch (CancellationException) {
        expect($scenario)->toBe('cancel');
    }
    $release = in_array($scenario, ['quota', 'retry', 'auth_before', 'auth_after', 'cooldown'], true);
    expect($job->delays)->toHaveCount($release ? 1 : 0)->and($after)->toBe($release || $scenario === 'cancel' ? 0 : 1);
    if (in_array($scenario, ['retry', 'auth_after', 'cooldown'], true)) { expect($transport->getRecorded())->toHaveCount(1); }
    if ($scenario === 'auth_before') { expect($transport->getRecorded())->toBe([]); }
})->with(['quota', 'retry', 'auth_before', 'auth_after', 'cooldown', 'bare429', 'backend', 'deadline', 'network', 'cancel']);
