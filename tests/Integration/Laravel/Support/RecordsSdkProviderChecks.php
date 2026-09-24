<?php

declare(strict_types=1);

namespace Integration;

use ApiSutra\Config\ClientConfig;
use ApiSutra\Contracts\Interfaces\Core\TransportInterface;
use ApiSutra\Enums\Configuration\Environment;
use ApiSutra\Exceptions\Configuration\ConfigurationException;
use ApiSutra\Laravel\SdkServiceProvider;
use ApiSutra\Resolver\ClientRegistry;
use Example\Records\Config\ClientConfigFactory;
use Example\Records\DemoClient;
use Example\Records\Laravel\DemoServiceProvider;
use Example\Records\Laravel\LaravelClientConfigFactory;
use Example\Records\Resources\Records\Get\GetRecordRequest;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

final class RecordsSdkProviderChecks
{
    private static function application(): Application
    {
        $app = new Application(dirname(__DIR__));
        $app->instance('config', new Repository(['app' => ['debug' => false]]));
        $app->detectEnvironment(static fn (): string => 'testing');
        $app->instance(TransportInterface::class, RecordsSdkChecks::transport());
        return $app;
    }

    public static function run(): void
    {
        self::registration();
        self::ownership();
        self::configuration();
        self::independentApplications();
        echo "Records SDK: registration, overrides, configuration and application lifetime — OK.\n";
    }

    private static function registration(): void
    {
        foreach ([false, true] as $reverse) {
            foreach (['default', 'instance', 'early-binding', 'late-binding'] as $mode) {
                $app = self::application();
                $customTransport = RecordsSdkChecks::transport();
                $custom = new DemoClient(ClientConfigFactory::create('https://override.example.test'), $customTransport);
                if ($mode === 'instance') {
                    $app->instance(DemoClient::class, $custom);
                } elseif ($mode === 'early-binding') {
                    $app->singleton(DemoClient::class, static fn (): DemoClient => $custom);
                }
                foreach ($reverse ? [DemoServiceProvider::class, SdkServiceProvider::class] : [SdkServiceProvider::class, DemoServiceProvider::class] as $provider) {
                    $app->register($provider);
                }
                if ($mode === 'late-binding') {
                    $app->singleton(DemoClient::class, static fn (): DemoClient => $custom);
                }
                $app->boot();
                RecordsSdkChecks::check($mode === 'instance' || !$app->resolved(DemoClient::class), 'Клиент создан при bootstrap');
                $request = $app->makeWith(GetRecordRequest::class, ['id' => 7]);
                $client = $app->make(DemoClient::class);
                RecordsSdkChecks::check($request->getClient() === $client && $request->id === 7, 'Нарушена регистрация запроса первым');
                RecordsSdkChecks::check($mode === 'default' || $client === $custom, 'Потерян binding/instance приложения');
                RecordsSdkChecks::check($request->send()->dataOrFail()->id === 7, 'Пользовательский клиент не выполнил запрос');
                if ($mode !== 'default') {
                    RecordsSdkChecks::check(count($customTransport->getRecorded()) === 1, 'Потерян отдельный транспорт SDK');
                }
                $app->alias(DemoClient::class, 'records');
                RecordsSdkChecks::check($app->make('records') === $client, 'Alias создал другой клиент');
                $app->register(DemoServiceProvider::class, force: true);
                $again = $app->makeWith(GetRecordRequest::class, ['id' => 8]);
                RecordsSdkChecks::check($again->getClient() === $client, 'Повторный provider нарушил регистрацию');
                $explicit = (new GetRecordRequest(9))->setClient($custom);
                $execution = $explicit->withCache(42);
                $options = $explicit->getOptions();
                $app->bind(GetRecordRequest::class, static fn (): GetRecordRequest => $explicit);
                RecordsSdkChecks::check($app->make(GetRecordRequest::class) === $explicit && $explicit->getOptions() === $options && $explicit->getClient() === $custom, 'DI изменил готовый запрос');
                RecordsSdkChecks::check($execution->getRequest() === $explicit && $execution->getOptions()->getCacheOverride()->ttl === 42, 'DI изменил runtime-исполнение');
            }
        }

        // Готовый запрос вообще не нуждается в default-клиенте и его конфигурации.
        $request = (new GetRecordRequest(7))->setClient($custom);
        $app = self::application();
        $app->register(SdkServiceProvider::class);
        $app->register(DemoServiceProvider::class);
        $app->boot();
        $app['config']->set('apisutra.records.base_url', []);
        $app->bind(GetRecordRequest::class, static fn (): GetRecordRequest => $request);
        RecordsSdkChecks::check($app->make(GetRecordRequest::class) === $request && !$app->resolved(DemoClient::class), 'Явный клиент вызвал ненужную default-фабрику');
    }

    private static function configuration(): void
    {
        foreach (['base_url', 'timeout', 'token'] as $field) {
            foreach ([[], new ClientConfig(baseUrl: 'https://invalid.example.test'), true] as $invalid) {
                $app = self::application();
                $app->register(DemoServiceProvider::class);
                $app['config']->set('apisutra.records.' . $field, $invalid);
                try {
                    LaravelClientConfigFactory::create($app);
                    RecordsSdkChecks::check(false, 'Некорректная конфигурация принята');
                } catch (ConfigurationException $error) {
                    RecordsSdkChecks::check(str_contains($error->getMessage(), 'apisutra.records.' . $field), 'Ошибка не указывает параметр');
                    RecordsSdkChecks::check($app->make(TransportInterface::class)->getRecorded() === [], 'Ошибка настроек выполнила HTTP');
                }
            }
        }
        foreach ([null, '', 'synthetic-sdk-token'] as $token) {
            $app = self::application();
            $app->register(SdkServiceProvider::class);
            $app->register(DemoServiceProvider::class);
            $app->boot();
            $app['config']->set('apisutra.records.token', $token);
            $app['config']->set('apisutra.records.timeout', '7');
            // Переопределение общего транспорта после providers, до первого клиента.
            $transport = RecordsSdkChecks::transport();
            $app->instance(TransportInterface::class, $transport);
            $client = $app->make(DemoClient::class);
            RecordsSdkChecks::check($client->getConfig()->timeout === 7 && $client->getConfig()->baseUrl === ClientConfigFactory::DEFAULT_BASE_URL, 'Частичный override потерял defaults');
            $record = $client->records()->get(7)->send()->dataOrFail();
            RecordsSdkChecks::check($record->_extra === ['new_field' => false], 'Потеряны правила DTO');
            $authorization = $transport->getRecorded()[0]->headers['Authorization'] ?? null;
            RecordsSdkChecks::check($authorization === ($token ? 'Bearer ' . $token : null), 'Неверная авторизация SDK');
            $app['config']->set('apisutra.records.timeout', 2);
            RecordsSdkChecks::check($app->make(DemoClient::class)->getConfig()->timeout === 7, 'Singleton перестроен после изменения config');
        }
    }

    private static function ownership(): void
    {
        $app = self::application();
        $app->register(SdkServiceProvider::class);
        $app->register(DemoServiceProvider::class);
        $app->boot();
        $registry = $app->make(ClientRegistry::class);
        $nested = new DemoClient(ClientConfigFactory::create('https://nested.example.test'), RecordsSdkChecks::transport());
        $registry->register($nested, DemoClient::REQUEST_NAMESPACE . '\\Records\\Get');
        $request = $app->makeWith(GetRecordRequest::class, ['id' => 7]);
        RecordsSdkChecks::check($request->getClient() === $nested, 'Потерян приоритет вложенного namespace');

        $app = self::application();
        $app->register(SdkServiceProvider::class);
        $app->register(DemoServiceProvider::class);
        $app->boot();
        $app->make(ClientRegistry::class)->register($nested, DemoClient::REQUEST_NAMESPACE);
        try {
            $app->make(DemoClient::class);
            RecordsSdkChecks::check(false, 'Конфликт namespace скрыт');
        } catch (ConfigurationException) {
            RecordsSdkChecks::check($app->make(ClientRegistry::class)->resolve(GetRecordRequest::class) === $nested, 'Чужой владелец перезаписан');
        }
    }

    private static function independentApplications(): void
    {
        $first = self::application();
        $second = self::application();
        foreach ([$first, $second] as $index => $app) {
            $app->register(SdkServiceProvider::class);
            $app->register(DemoServiceProvider::class);
            $app['config']->set('apisutra.records.base_url', 'https://app-' . $index . '.example.test');
            $app['config']->set('app.debug', (bool) $index);
            $app->detectEnvironment(static fn (): string => $index === 0 ? 'local' : 'testing');
            $app->boot();
        }
        $one = $first->make(DemoClient::class);
        $two = $second->make(DemoClient::class);
        RecordsSdkChecks::check($one !== $two && $one->getConfig()->environment === Environment::Local && !$one->getConfig()->debug && $two->getConfig()->debug, 'Перепутаны настройки приложений');
        foreach ([$first, $second] as $index => $app) {
            $client = $app->make(DemoClient::class);
            RecordsSdkChecks::check($client->getConfig()->containerProvider === null, 'Конфигурация закрепила контейнер приложения');
            $request = $app->makeWith(GetRecordRequest::class, ['id' => 7]);
            RecordsSdkChecks::check($request->getClient() === $client, 'Перепутаны реестры приложений');
            $request->send()->dataOrFail();
            RecordsSdkChecks::check(str_starts_with($app->make(TransportInterface::class)->getRecorded()[0]->url, 'https://app-' . $index . '.example.test/'), 'Выбран транспорт или URL другого приложения');
        }
    }
}
