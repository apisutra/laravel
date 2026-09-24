<?php

declare(strict_types=1);

use ApiSutra\Config\CacheConfig;
use ApiSutra\Config\HydrationConfig;
use ApiSutra\Config\ResultExceptionConfig;
use ApiSutra\Enums\Configuration\Environment;
use ApiSutra\Exceptions\Configuration\ConfigurationException;
use ApiSutra\Laravel\ClientConfigFactory;
use ApiSutra\Laravel\LaravelContainerProvider;
use ApiSutra\Laravel\SdkServiceProvider;
use ApiSutra\Config\LocalizationConfig;
use ApiSutra\RateLimiting\Backends\LocalRateLimitBackend;
use ApiSutra\RateLimiting\Cooldown\Backends\LocalCooldownBackend;
use ApiSutra\Support\ContainerProviderRegistry;
use ApiSutra\Support\NullContainerProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

it('читает только своё приложение при другом глобальном контейнере', function (mixed $debug, bool $hasConfig): void {
    $previous = Container::getInstance();
    $a = new Application();
    $a->detectEnvironment(static fn (): string => 'testing');
    if ($hasConfig) {
        $a->instance('config', new Repository(['app' => ['debug' => $debug]]));
    }
    $factory = new ClientConfigFactory(new LaravelContainerProvider($a));
    $b = new Application();
    $b->instance('config', new Repository(['app' => ['debug' => true]]));
    $b->detectEnvironment(static fn (): string => 'local');
    try {
        $config = $factory->make(['baseUrl' => 'https://fixture.test']);
        expect($config->debug)->toBe($hasConfig && is_bool($debug) ? $debug : false)
            ->and($config->environment)->toBe(Environment::Testing)
            ->and($config->containerProvider)->toBeNull();
        expect((new LaravelContainerProvider($a))->isDebug())->toBe($hasConfig && is_bool($debug) ? $debug : null);
    } finally {
        Container::setInstance($previous);
    }
})->with([[false, true], [true, true], [null, true], ['true', true], [null, false]]);

it('не встраивает Application и сохраняет сериализацию простого конфига', function (bool $explicitNull): void {
    $app = new Application();
    $app->detectEnvironment(static fn (): string => 'production');
    $app->instance('config', new Repository(['app' => ['debug' => true]]));
    $app->bind('fixture.closure', static fn (): object => new stdClass());
    (new SdkServiceProvider($app))->register();
    $factory = $app->make(ClientConfigFactory::class);
    $config = $factory->make(['baseUrl' => 'https://fixture.test', ...($explicitNull ? ['containerProvider' => null] : [])]);
    expect($config->containerProvider)->toBeNull()
        ->and(unserialize(serialize($config)))->toEqual($config);
})->with([false, true]);

it('использует defaults приложения для null в debug и environment', function (array $overrides): void {
    $app = new Application();
    $app->detectEnvironment(static fn (): string => 'staging');
    $app->instance('config', new Repository(['app' => ['debug' => true]]));
    $factory = new ClientConfigFactory(new LaravelContainerProvider($app));
    $config = $factory->make(['baseUrl' => 'https://fixture.test', ...$overrides]);
    expect($config->debug)->toBeTrue()
        ->and($config->environment)->toBe(Environment::Staging)
        ->and($config->containerProvider)->toBeNull();
})->with([
    'debug' => [['debug' => null]],
    'environment' => [['environment' => null]],
    'both' => [['debug' => null, 'environment' => null]],
]);

it('отдаёт ошибку ядра для отсутствующего или null baseUrl', function (array $overrides): void {
    $factory = new ClientConfigFactory(new NullContainerProvider());
    expect(fn () => $factory->make(['localization' => 'ru', ...$overrides]))
        ->toThrow(ConfigurationException::class, 'ClientConfig.baseUrl не должен быть пустым');
})->with(['missing' => [[]], 'null' => [['baseUrl' => null]]]);

it('сохраняет все переданные блоки, null, false и пользовательские сообщения', function (): void {
    $app = new Application();
    $app->detectEnvironment(static fn (): string => 'production');
    $app->instance('config', new Repository(['app' => ['debug' => true]]));
    $factory = new ClientConfigFactory(new LaravelContainerProvider($app));
    $localization = new LocalizationConfig('ru', messages: ['ru' => ['fixture.key' => 'Пример']]);
    $blocks = [
        'cacheConfig' => new CacheConfig(), 'hydration' => new HydrationConfig(),
        'resultExceptions' => new ResultExceptionConfig(mismatchMessage: 'Пример'),
        'rateLimitBackend' => new LocalRateLimitBackend(), 'includeClientQuota' => false,
        'localization' => $localization, 'debug' => false, 'environment' => Environment::Staging,
    ];
    $config = $factory->make(['baseUrl' => 'https://fixture.test', ...$blocks]);
    foreach ($blocks as $key => $value) {
        expect($config->{$key})->toBe($value);
    }
    foreach (['cacheConfig', 'hydration', 'resultExceptions', 'rateLimitBackend'] as $key) {
        expect($factory->make(['baseUrl' => 'https://fixture.test', ...$blocks, $key => null])->{$key})->toBeNull();
    }
    expect($factory->make(['baseUrl' => 'https://fixture.test', 'localization' => 'ru'])->localization->locale)->toBe('ru')
        ->and(fn () => $factory->make(['baseUrl' => '', 'localization' => $localization]))
        ->toThrow(ConfigurationException::class, 'ClientConfig.baseUrl не должен быть пустым');
    foreach (['cache', 'cacheStore', 'unknown'] as $key) {
        expect(fn () => $factory->make(['baseUrl' => 'https://fixture.test', $key => null]))->toThrow(Error::class);
    }
});

it('явный provider задаёт defaults и сохраняется, Null отключает контейнер', function (): void {
    $a = new Application();
    $a->instance('config', new Repository(['app' => ['debug' => true]]));
    $factory = new ClientConfigFactory(new LaravelContainerProvider($a));
    $b = new Application();
    $b->instance('config', new Repository(['app' => ['debug' => false]]));
    $b->detectEnvironment(static fn (): string => 'staging');
    $provider = new LaravelContainerProvider($b);
    $config = $factory->make(['baseUrl' => 'https://fixture.test', 'containerProvider' => $provider]);
    expect($config->containerProvider)->toBe($provider)->and($config->debug)->toBeFalse()
        ->and($config->environment)->toBe(Environment::Staging);
    $null = new NullContainerProvider();
    $config = $factory->make(['baseUrl' => 'https://fixture.test', 'containerProvider' => $null]);
    expect($config->containerProvider)->toBe($null)->and($config->debug)->toBeFalse()
        ->and($config->environment)->toBe(Environment::Production);
});

it('поздняя регистрация default не замещает explicit и заменяет приложение A на B', function (): void {
    $a = new Container();
    $b = new Container();
    $serviceA = new stdClass();
    $serviceB = new stdClass();
    $a->instance('fixture', $serviceA);
    $b->instance('fixture', $serviceB);
    expect(ContainerProviderRegistry::resolve())->toBeInstanceOf(NullContainerProvider::class);
    (new SdkServiceProvider($a))->register();
    $providerA = ContainerProviderRegistry::resolve();
    expect($providerA->make('fixture'))->toBe($serviceA);
    (new SdkServiceProvider($b))->register();
    expect(ContainerProviderRegistry::resolve()->make('fixture'))->toBe($serviceB)
        ->and(ContainerProviderRegistry::resolve($providerA)->make('fixture'))->toBe($serviceA);
    $explicit = new NullContainerProvider();
    ContainerProviderRegistry::set($explicit);
    (new SdkServiceProvider($a))->register();
    expect(ContainerProviderRegistry::resolve())->toBe($explicit);
});

it('сохраняет binding фабрики конфигурации приложения', function (): void {
    $app = new Container();
    $factory = new ClientConfigFactory(new NullContainerProvider());
    $app->instance(ClientConfigFactory::class, $factory);
    (new SdkServiceProvider($app))->register();
    expect($app->make(ClientConfigFactory::class))->toBe($factory);
});

it('передаёт cooldown backend как есть и сохраняет zero-config', function (): void {
    $factory = new ClientConfigFactory(new NullContainerProvider());
    expect($factory->make(['baseUrl' => 'https://fixture.test'])->cooldownBackend)->toBeNull();
    $backend = new LocalCooldownBackend();
    $config = $factory->make(['baseUrl' => 'https://fixture.test', 'cooldownBackend' => $backend]);
    expect($config->cooldownBackend)->toBe($backend)->and($config->with(debug: true)->cooldownBackend)->toBe($backend);
});
