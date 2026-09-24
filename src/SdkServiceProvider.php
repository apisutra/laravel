<?php

declare(strict_types=1);

namespace ApiSutra\Laravel;

use ApiSutra\Contracts\Interfaces\Core\ClientResolverInterface;
use ApiSutra\Contracts\Interfaces\Core\MultiServiceClientInterface;
use ApiSutra\Contracts\Interfaces\Core\TransportInterface;
use ApiSutra\Laravel\Contracts\RequestFactoryInterface;
use ApiSutra\Laravel\Contracts\ClientResponseAdapterInterface;
use ApiSutra\Core\AbstractRequest;
use ApiSutra\Resolver\ClassMapProvider;
use ApiSutra\Resolver\ClientDiscoveryCache;
use ApiSutra\Resolver\ClientDiscoveryService;
use ApiSutra\Resolver\ClientRegistry;
use ApiSutra\Resolver\ClientResolver;
use ApiSutra\Resolver\RequestNamespaceDetector;
use ApiSutra\Resolver\RequestScanner;
use ApiSutra\Resolver\ServiceRegistrar;
use Illuminate\Support\ServiceProvider;
use ApiSutra\Support\ContainerProviderRegistry;
use ApiSutra\Transport\DefaultTransportFactory;
use ApiSutra\Laravel\Testing\TestingManager;
use ApiSutra\Laravel\Container\CurrentContainerProvider;
use ApiSutra\Contracts\Interfaces\Diagnostics\ExecutionObserverInterface;
use ApiSutra\Laravel\Observability\ObservationManager;
use ApiSutra\Laravel\Lifecycle\LifecycleRegistration;
use ApiSutra\Laravel\Console\AboutRegistration;
use Psr\SimpleCache\CacheInterface;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use ApiSutra\Laravel\Console\MakeClient;
use ApiSutra\Laravel\Console\MakeRequest;
use ApiSutra\Laravel\Console\MakeDto;

/**
 * ServiceProvider пакета ApiSutra.
 *
 * Регистрирует сервисы discovery, реестр клиентов и авто‑резолвинг запросов,
 * чтобы запросы могли работать через DI в контроллерах.
 */
final class SdkServiceProvider extends ServiceProvider
{
    /**
     * Зарегистрировать зависимости пакета и hooks для DI.
     */
    public function register(): void
    {
        ContainerProviderRegistry::setDefaultResolver((new CurrentContainerProvider($this->app))->resolve(...));

        // Маркер принадлежит контейнеру приложения, а не глобальному процессу worker.
        $marker = self::class . '.registered';
        if ($this->app->bound($marker)) {
            return;
        }
        $this->app->instance($marker, true);
        if ($this->app instanceof Application) {
            $this->app->booting(fn () => (new LifecycleRegistration($this->app))->register());
        }
        if ($this->app->bound('config')) {
            $config = $this->app->make('config');
            $defaults = require dirname(__DIR__) . '/config/apisutra.php';
            $config->set('apisutra.integration', array_replace_recursive($defaults['integration'], $config->get('apisutra.integration', [])));
        }
        $this->app->bindIf(ObservationManager::class, static function ($app): ObservationManager {
            $key = ObservationManager::class . '.state';
            $manager = $app->bound($key) ? $app->make($key) : null;
            if (!$manager instanceof ObservationManager || !$manager->belongsTo($app)) {
                $manager = new ObservationManager($app);
                $app->instance($key, $manager);
            }
            return $manager;
        });
        $this->app->bindIf(ExecutionObserverInterface::class, static fn ($app) => $app->make(ObservationManager::class)->observer());
        $this->app->singletonIf(TestingManager::class, static fn ($app): TestingManager => new TestingManager($app));
        $this->app->singletonIf(ClientConfigFactory::class, static function ($app): ClientConfigFactory {
            return new ClientConfigFactory(new LaravelContainerProvider($app));
        });
        $this->app->singletonIf(RequestFactoryInterface::class, function () {
            return new RequestFactory();
        });
        $this->app->singletonIf(ClientResponseAdapterInterface::class, function () {
            return new ClientResponseAdapter();
        });
        $this->app->singletonIf(ClientRegistry::class, function () {
            return new ClientRegistry();
        });
        $this->app->singletonIf(ClassMapProvider::class, function () {
            return new ClassMapProvider();
        });
        $this->app->singletonIf(RequestScanner::class, function ($app) {
            return new RequestScanner($app->make(ClassMapProvider::class));
        });
        $this->app->singletonIf(RequestNamespaceDetector::class, function ($app) {
            return new RequestNamespaceDetector($app->make(RequestScanner::class));
        });
        $this->app->singletonIf(ClientDiscoveryCache::class, function () {
            $store = null;
            if ($this->app->bound(CacheInterface::class)) {
                $store = $this->app->make(CacheInterface::class);
            }
            return new ClientDiscoveryCache($store);
        });
        $this->app->singletonIf(ClientDiscoveryService::class, function ($app) {
            return new ClientDiscoveryService(
                $app->make(ClientRegistry::class),
                $app->make(RequestNamespaceDetector::class),
                $app->make(ClientDiscoveryCache::class),
            );
        });
        $this->app->singletonIf(ClientResolverInterface::class, function ($app) {
            return new ClientResolver($app->make(ClientRegistry::class));
        });
        $this->app->singletonIf(ServiceRegistrar::class, function ($app) {
            return new ServiceRegistrar(
                $app->make(ClientRegistry::class),
                $app->make(RequestNamespaceDetector::class),
            );
        });
        $this->registerDefaultTransport();

        $this->app->resolving(MultiServiceClientInterface::class, function (MultiServiceClientInterface $object, $app): void {

            $registrar = $app->make(ServiceRegistrar::class);
            if ($registrar instanceof ServiceRegistrar) {
                $registrar->register($object->services());
            }
        });

        $this->app->resolving(AbstractRequest::class, function (AbstractRequest $object, $app): void {

            $this->resolveClient($object, $app);
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(dirname(__DIR__) . '/resources/lang', 'apisutra');
        AboutRegistration::register();
        if (class_exists(Command::class)) {
            $this->commands([MakeClient::class, MakeRequest::class, MakeDto::class]);
        }
        (new LifecycleRegistration($this->app))->register();
        if ($this->app instanceof Application) {
            $this->publishes([dirname(__DIR__) . '/config/apisutra.php' => $this->app->configPath('apisutra.php')], 'apisutra-config');
        }
    }

    /**
     * Привязать клиента к запросу, если он ещё не установлен.
     */
    private function resolveClient(AbstractRequest $request, mixed $app): void
    {
        if (!$request->hasClient() && is_object($app) && method_exists($app, 'make')) {
            $resolver = $app->make(ClientResolverInterface::class);
            if ($resolver instanceof ClientResolverInterface) {
                $request->setClient($resolver->resolve($request));
            }
        }
    }

    private function registerDefaultTransport(): void
    {
        if ($this->app->bound(TransportInterface::class)) {
            return;
        }

        $this->app->singletonIf(TransportInterface::class, function ($app) {
            return (new DefaultTransportFactory())->create(new LaravelContainerProvider($app));
        });
    }
}
