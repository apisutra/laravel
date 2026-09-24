<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Config\CooldownConfig;
use ApiSutra\RateLimiting\Cooldown\Backends\LocalCooldownBackend;
use ApiSutra\RateLimiting\Cooldown\Backends\PhpRedisCooldownBackend;
use ApiSutra\Enums\RateLimiting\RateLimitBehavior;
use ApiSutra\Laravel\ClientConfigFactory;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Timing\ExecutionDeadline;
use ApiSutra\Transport\MockTransport;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Integration\First\Client;
use Integration\First\ItemsRequest;

final class CooldownChecks
{
    public static function shared(Application $app, string $group, bool $publish): void
    {
        $backend = $app->make(PhpRedisCooldownBackend::class);
        $config = $app->make(ClientConfigFactory::class)->make([
            'baseUrl' => 'https://fixture.test', 'cooldownBackend' => $backend,
            'cooldown' => new CooldownConfig(group: $group, behavior: RateLimitBehavior::Throw),
        ]);
        $transport = new MockTransport();
        $transport->fake(['*' => $publish ? MockResponse::rateLimited(30) : MockResponse::success()]);
        $result = new Client($config, $transport)->send(new ItemsRequest())->raw();
        check($publish ? $result->response?->status === 429 : ($result->errors->first()?->context['reason'] ?? null) === 'server_cooldown_active', 'Redis cooldown потерян между bootstrap');
        check(count($transport->getRecorded()) === ($publish ? 1 : 0), 'Неверное число HTTP при общем cooldown');
    }

    public static function run(Application $app): void
    {
        $factory = $app->make(ClientConfigFactory::class);
        $defaults = $factory->make(['baseUrl' => 'https://fixture.test']);
        check($defaults->cooldown->enabled && $defaults->cooldown->maxAdditionalWaitMs === null, 'Фабрика потеряла default cooldown');
        $rule = new CooldownConfig(group: 'reports', behavior: RateLimitBehavior::Throw);
        $config = $factory->make(['baseUrl' => 'https://fixture.test', 'cooldown' => $rule]);
        check($config->cooldown === $rule, 'Фабрика потеряла явное правило cooldown');
        $clock = new CooldownClock();
        $transport = new MockTransport();
        $transport->fake(['*' => MockResponse::sequence([MockResponse::rateLimited(30), MockResponse::success()])]);
        $client = new Client($config, $transport, sleeper: $clock, clock: $clock);
        $app->instance('cooldown.client', $client);
        $client->send(new ItemsRequest());
        $app['router']->get('/cooldown-probe', static function () use ($app): JsonResponse {
            $result = $app->make('cooldown.client')->send(new ItemsRequest())->raw();
            return new JsonResponse(['reason' => $result->errors->first()?->context['reason']]);
        });
        $kernel = $app->make(Kernel::class);
        $incoming = Request::create('/cooldown-probe');
        $response = $kernel->handle($incoming);
        check(json_decode($response->getContent(), true) === ['reason' => 'server_cooldown_active'], 'HTTP route потерял локальный запрет');
        $kernel->terminate($incoming, $response);
        check(count($transport->getRecorded()) === 1 && $app->make('cooldown.client') === $client, 'Отказ вызвал HTTP или пересоздал клиент');
        $request = (new ItemsRequest())->withCooldown(new CooldownConfig(group: 'reports'))
            ->withDeadline(ExecutionDeadline::afterMs(60_000, $clock));
        check($client->sendAsync($request)->wait()->raw()->isSuccess() && $clock->monotonicMs() === 30_000, 'Async Wait не применил общий budget');
        $transport->fake(['*' => MockResponse::rateLimited(30)]);
        $client->send(new ItemsRequest());
        $transport->fake(['*' => MockResponse::success()]);
        $fresh = new Client($config, $transport, sleeper: $clock, clock: $clock);
        check($fresh->send(new ItemsRequest())->raw()->isSuccess(), 'Новый клиент унаследовал чужое состояние');
        $backend = new LocalCooldownBackend($clock);
        $sharedConfig = $factory->make(['baseUrl' => 'https://fixture.test', 'cooldownBackend' => $backend, 'cooldown' => $rule]);
        $transport->fake(['*' => MockResponse::rateLimited(30)]);
        new Client($sharedConfig, $transport, sleeper: $clock, clock: $clock)->send(new ItemsRequest());
        $result = new Client($sharedConfig, $transport, sleeper: $clock, clock: $clock)->send(new ItemsRequest())->raw();
        check(($result->errors->first()?->context['reason'] ?? null) === 'server_cooldown_active', 'Фабрика потеряла общий backend');
    }
}
