<?php

declare(strict_types=1);

use ApiSutra\Config\ClientConfig;
use ApiSutra\Execution\Async\AsyncRuntime;
use ApiSutra\Laravel\LaravelContainerProvider;
use ApiSutra\Laravel\SdkServiceProvider;
use ApiSutra\Laravel\Tests\Stubs\Requests\DiRequest;
use ApiSutra\Laravel\Tests\Stubs\TestClient;
use ApiSutra\Support\ContainerProviderRegistry;
use ApiSutra\Testing\MockResponse;
use ApiSutra\Timing\CooperativeSleeper;
use ApiSutra\Transport\MockTransport;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

it('выбирает текущий клон приложения, сохраняя явно переданный provider', function (): void {
    $a = new Application(sys_get_temp_dir());
    $a->register(SdkServiceProvider::class);
    $a->scoped('fixture', static fn (Container $app): object => (object) ['owner' => spl_object_id($app)]);
    $explicit = new LaravelContainerProvider($a);
    $b = clone $a;
    Container::setInstance($b);
    expect(ContainerProviderRegistry::resolve()->make('fixture')->owner)->toBe(spl_object_id($b))
        ->and(ContainerProviderRegistry::resolve($explicit)->make('fixture')->owner)->toBe(spl_object_id($a));
});

it('фиксирует provider при переключениях Fiber и наследует вложенную задачу', function (): void {
    $a = new Application(sys_get_temp_dir());
    $a->register(SdkServiceProvider::class);
    $a->instance('fixture', (object) ['owner' => 'A']);
    $b = new Application(sys_get_temp_dir());
    $b->register(SdkServiceProvider::class);
    $b->instance('fixture', (object) ['owner' => 'B']);
    $seen = [];
    $transport = new MockTransport();
    $transport->fake([DiRequest::class => static function () use (&$seen): MockResponse {
        $before = ContainerProviderRegistry::resolve()->make('fixture')->owner;
        (new CooperativeSleeper())->sleepMs(2);
        $nested = (new AsyncRuntime())->start(static fn (): string => ContainerProviderRegistry::resolve()->make('fixture')->owner)->wait();
        $seen[] = [$before, ContainerProviderRegistry::resolve()->make('fixture')->owner, $nested];
        return MockResponse::success();
    }]);
    $client = new TestClient(new ClientConfig(baseUrl: 'https://test.example'), $transport);
    Container::setInstance($a);
    $one = $client->sendAsync(new DiRequest());
    Container::setInstance($b);
    $two = $client->sendAsync(new DiRequest());
    $one->wait();
    $two->wait();
    expect($seen)->toContain(['A', 'A', 'A'])->toContain(['B', 'B', 'B'])
        ->and(ContainerProviderRegistry::resolve()->make('fixture')->owner)->toBe('B');
});

it('восстанавливает вложенную область после исключения', function (): void {
    $a = new Container();
    $a->instance('fixture', (object) ['owner' => 'A']);
    $b = new Container();
    $b->instance('fixture', (object) ['owner' => 'B']);
    ContainerProviderRegistry::setDefault(new LaravelContainerProvider($a));
    expect(fn () => ContainerProviderRegistry::withProvider(new LaravelContainerProvider($b), static function (): void {
        expect(ContainerProviderRegistry::resolve()->make('fixture')->owner)->toBe('B');
        throw new RuntimeException('leave');
    }))->toThrow(RuntimeException::class, 'leave');
    expect(ContainerProviderRegistry::resolve()->make('fixture')->owner)->toBe('A');
});
