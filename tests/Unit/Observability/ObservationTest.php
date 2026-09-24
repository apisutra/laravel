<?php

declare(strict_types=1);

use Example\ApiSutra\SdkMetricsListener;

require_once dirname(__DIR__, 3) . '/docs/example/SdkMetricsListener.php';

use ApiSutra\Config\ClientConfig;
use ApiSutra\Laravel\Tests\Stubs\ObservationClock;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Cache\ArrayStore;
use ApiSutra\Config\RetryConfig;
use ApiSutra\Diagnostics\ExecutionSnapshot;
use ApiSutra\Execution\Async\AsyncRuntime;
use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use ApiSutra\Laravel\Observability\Events\TelemetryDropped;
use ApiSutra\Laravel\Observability\ObservationArea;
use ApiSutra\Laravel\Observability\ObservationManager;
use ApiSutra\Laravel\Observability\ObservationOptions;
use ApiSutra\Laravel\SdkServiceProvider;
use ApiSutra\Laravel\Tests\Stubs\Requests\DiRequest;
use ApiSutra\Laravel\Tests\Stubs\TestClient;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Timing\CooperativeSleeper;
use ApiSutra\Transport\MockTransport;
use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use GuzzleHttp\Promise\CancellationException;

it('доставляет безопасный итог после SDK и не дублирует flush', function (): void {
    $app = new Application(sys_get_temp_dir());
    $app->instance('env', 'testing');
    $app->instance('config', new Repository(['apisutra' => ['integration' => ['observation' => ['enabled' => true, 'details' => true]]]]));
    $app->register(EventServiceProvider::class);
    $app->register(SdkServiceProvider::class);
    $events = [];
    $app['events']->listen(ExecutionCompleted::class, static function ($event) use (&$events): void { $events[] = $event; });
    $manager = $app->make(ObservationManager::class);
    $manager->begin('test');
    $transport = new MockTransport();
    $transport->fake([DiRequest::class => static function (): MockResponse {
        (new CooperativeSleeper())->sleepMs(2);
        return MockResponse::success(['access_token' => 'secret']);
    }]);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example', diagnosticLabel: 'catalog'), $transport);
    expect($client->sendAsync(new DiRequest())->wait()->raw()->isSuccess())->toBeTrue()->and($events)->toBe([]);
    expect($manager->flush())->toBeTrue()->and($events)->toHaveCount(1);
    $data = $events[0]->snapshot->data;
    expect($data['attemptCount'])->toBe(1)->and($data['httpDurationMs'])->toBeGreaterThanOrEqual(1)
        ->and($data['clientLabel'])->toBe('catalog')->and($data['attempts'])->toHaveCount(1)
        ->and(json_encode($data))->not->toContain('secret')->not->toContain('access_token');
    $manager->end('test');
    expect($events)->toHaveCount(1);
});

it('ранний terminal отмены не разрешает доставку до cleanup', function (): void {
    $app = new Application(sys_get_temp_dir());
    $app->instance('config', new Repository(['apisutra' => ['integration' => ['observation' => ['enabled' => true]]]]));
    $app->register(EventServiceProvider::class);
    $app->register(SdkServiceProvider::class);
    $events = [];
    $app['events']->listen(ExecutionCompleted::class, static function ($event) use (&$events): void { $events[] = $event; });
    $manager = $app->make(ObservationManager::class);
    $manager->begin('test');
    $transport = new MockTransport();
    $transport->fake([DiRequest::class => static function (): MockResponse {
        (new CooperativeSleeper())->sleepMs(500);
        return MockResponse::success();
    }]);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example'), $transport);
    $pending = $client->sendAsync(new DiRequest());
    $pending->cancel();
    expect($manager->flush())->toBeFalse()->and($events)->toBe([]);
    try { $pending->wait(); } catch (CancellationException) { }
    // Уже отклонённый Promise не обязан прокручивать cleanup чужого цикла.
    (new AsyncRuntime())->start(static function (): void { (new CooperativeSleeper())->sleepMs(1); })->wait();
    $sibling = $client->beginFakeSession();
    $sibling->close();
    $manager->end('test');
    expect($events)->toHaveCount(1)->and($events[0]->snapshot->data['reason'])->toBe('execution_cancelled');
});

it('выключает автоматическое наблюдение в testing без области и не держит данные следующего теста', function (): void {
    $app = new Application(sys_get_temp_dir());
    $app->instance('env', 'testing');
    $app->instance('config', new Repository());
    $app->register(SdkServiceProvider::class);
    $manager = $app->make(ObservationManager::class);
    $manager->begin('test');
    expect($manager->observer())->toBeNull()->and($manager->flush())->toBeFalse();
    $app['config']->set('apisutra.integration.observation.enabled', true);
    expect($manager->observer())->toBeNull();
    $manager->begin('test');
    expect($manager->observer())->not->toBeNull();
    $manager->close();
    expect($manager->observer())->toBeNull();
});

it('ограничивает память и экспорт, сохраняя одну сводку вместо отброшенных снимков', function (): void {
    $area = new ObservationArea(new ObservationOptions(maxSnapshots: 2, maxBytes: 512, perWindow: 2));
    $snapshot = new ExecutionSnapshot(['status' => 'success', 'parentExecutionId' => null]);
    for ($i = 0; $i < 1000; $i++) { $area->accept($snapshot, []); }
    $events = [];
    $dispatch = static function (object $event) use (&$events): void { $events[] = $event; };
    expect($area->flush($dispatch))->toBeTrue()->and($events)->toHaveCount(3)
        ->and($events[2])->toBeInstanceOf(TelemetryDropped::class)->and($events[2]->count)->toBe(998);
    $area->accept($snapshot, []);
    $area->flush($dispatch);
    expect($events)->toHaveCount(3);
    $area->close();
});

it('возобновляет окно без таймера и ограничивает байты независимо от числа', function (): void {
    $clock = new ObservationClock();
    $area = new ObservationArea(new ObservationOptions(maxSnapshots: 256, maxBytes: 128, perWindow: 2), $clock);
    $events = [];
    $dispatch = static function (object $event) use (&$events): void { $events[] = $event; };
    $small = new ExecutionSnapshot(['status' => 'success', 'parentExecutionId' => null]);
    $large = new ExecutionSnapshot($small->data + ['operation' => str_repeat('x', 256)]);
    $area->accept($large, []);
    $area->accept($small, []);
    $area->flush($dispatch);
    expect($events)->toHaveCount(2)->and($events[1]->count)->toBe(1);
    $area->accept($small, []);
    $area->flush($dispatch);
    $area->accept($small, []);
    $area->flush($dispatch);
    expect($events)->toHaveCount(3);
    $clock->ms += 60000;
    $area->accept($small, []);
    $area->flush($dispatch);
    expect($events)->toHaveCount(5)->and($events[4]->count)->toBe(1);
});

it('сохраняет Context на входе и не наследует новую область после закрытия старой', function (): void {
    $app = new Application(sys_get_temp_dir());
    $app->instance('config', new Repository(['apisutra' => ['integration' => ['observation' => ['enabled' => true]]]]));
    $app->register(EventServiceProvider::class);
    $app->register(SdkServiceProvider::class);
    $context = new ContextRepository($app['events']);
    $app->instance(ContextRepository::class, $context);
    $manager = $app->make(ObservationManager::class);
    $events = [];
    $metrics = new CacheRepository(new ArrayStore());
    $app['events']->listen(ExecutionCompleted::class, static function ($event) use (&$events, $metrics): void {
        $events[] = $event;
        (new SdkMetricsListener($metrics))->handle($event);
    });
    $transport = new MockTransport();
    $transport->fake([DiRequest::class => static function (): MockResponse {
        (new CooperativeSleeper())->sleepMs(1);
        return MockResponse::success();
    }]);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example'), $transport);
    $manager->begin('old');
    $context->add('request_id', 'old');
    $first = $client->sendAsync(new DiRequest());
    expect($manager->end('old'))->toBeFalse();
    $manager->begin('new');
    $context->add('request_id', 'new');
    $second = $client->sendAsync(new DiRequest());
    expect($manager->flush())->toBeFalse();
    $first->wait();
    $second->wait();
    $manager->end('new');
    expect($events)->toHaveCount(1)->and($events[0]->correlation['request_id'])->toBe('new')
        ->and($metrics->get('sdk.completed.success'))->toBe(1);
});
