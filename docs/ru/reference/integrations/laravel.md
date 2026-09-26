<!-- languages --> <a href="../../../en/reference/integrations/laravel.md">English</a> · <a href="laravel.md">Русский</a> <!-- /languages -->
# Интеграция с Laravel <a id="section-1"></a>

Требуются Laravel 13.12+, PHP 8.4+ и `apisutra/php:^0.1 || ^0.2`. Установите `apisutra/laravel`: он подключается через package discovery. Обязательных config-файла и публикации нет.

Пакет поставляется с `SdkServiceProvider`, который подключает DI,
auto-discovery, RequestFactory и адаптер ответов. В Laravel provider подключается
автоматически через package discovery; публиковать конфиг не требуется. При отключённом
Laravel discovery добавьте `SdkServiceProvider::class` в `bootstrap/providers.php`.
Ядро без Laravel продолжает работать через явный SDK-клиент и транспорт.

Готовый SDK поставляет свой provider: он собирает клиента из конфигурации приложения
и регистрирует namespace запросов. Автор SDK задаёт протокол, defaults и правила
авторизации; приложение предоставляет credentials и overrides.
[Подключить готовый SDK](../../guides/integration/laravel.md) ·
[Добавить интеграцию в свой SDK](../../guides/sdk/laravel.md).

## Регистрация и переопределение клиента <a id="section-2"></a>

Ниже контракт [Records SDK](https://github.com/apisutra/php/blob/master/docs/ru/examples/sdk.md); другие SDK должны указать свои гарантии регистрации.

- Default-клиент создаётся лениво через `singletonIf`; готовый instance и binding
  приложения сохраняются. Регистрация namespace отделена от фабрики клиента.
- Можно первым получить клиента либо запрос через `makeWith(GetRecordRequest::class,
  ['id' => 7])`. Обязательные параметры конструктора задаёт вызывающий код.
- Общий `resolving` callback SDK выполняется до типизированного callback адаптера:
  запрос без клиента получает зарегистрированного исполнителя. Явный клиент сохраняется.
- Hooks подключаются в `boot`. `callAfterResolving` учитывает уже созданный клиент;
  повторное подключение provider не дублирует hooks.
- Клиент и транспорт переопределяются до первого использования: до package providers
  или в `register()` приложения после них. Отдельный транспорт SDK передаётся в его
  собственном binding клиента. Изменение config не перестраивает готовый singleton.
- Для нескольких SDK используются разные типы, ключи config и namespace запросов.
  Самый длинный namespace имеет приоритет; конфликт владельцев даёт
  `ConfigurationException`. Скрытого замещения через `force` нет.

`ServiceRegistrar` использует явные `requestNamespaces()`, если клиент реализует
`RequestNamespaceProviderInterface`; иначе применяется существующий detector.
Успешная регистрация запоминается в экземпляре registrar. Один живой клиент можно
зарегистрировать в независимых реестрах. После ошибки повтор разрешён: при частичном
выполнении успешные привязки сохраняются, исправленная декларация добавляет недостающие.
Ранее занятый чужим клиентом namespace остаётся конфликтом. Сам
`ClientRegistry::register()` запрещает дубликаты без явного `force`.

## Что регистрируется <a id="section-3"></a>

- `ApiSutra\Laravel\ClientConfigFactory`
- `RequestFactoryInterface`
- `ClientResponseAdapterInterface`
- `ClientRegistry` / `ClientResolver`
- `ClientDiscoveryService`
- `ServiceRegistrar` (для мультисервисных клиентов)
- default `TransportInterface` (если не задан)
- [TestingManager](testing.md) и [ObservationManager / ExecutionObserverInterface](observability.md); явные bindings сохраняются.

## DI запросов <a id="section-4"></a>

Обычный DI сохраняет значения SDK-запроса и привязывает клиента, если он ещё не задан.
Входящий `Illuminate\Http\Request` не читается: `limit=500` в браузере не заменяет
`limit=20`, заданный приложением. В Artisan и заданиях действуют те же правила.
Запросы с обязательными параметрами конструктора создавайте явно или задавайте
обычный Laravel binding.

### Явное заполнение из HTTP-входа <a id="section-5"></a>

Для заполнения запроса из входных HTTP-данных явно вызовите `RequestFactoryInterface`.
В примере `GetUser` и `UserClient` — классы SDK приложения.

```php
use App\Sdk\Users\GetUser;
use App\Sdk\Users\UserClient;
use ApiSutra\Laravel\Contracts\RequestFactoryInterface;
use Illuminate\Http\Request;

final class UsersController
{
    public function show(Request $incoming, RequestFactoryInterface $factory, UserClient $client): mixed
    {
        $request = $factory->make(GetUser::class, $incoming);
        return $client->send($request)->dataOrFail();
    }
}
```

## RequestFactory и payload‑ключи <a id="section-6"></a>
Отсутствующие входные поля сохраняют defaults свойств и конструктора. Явный `null`
передаётся объявленному полю; PHP отклоняет его для non-nullable полей и аргументов конструктора.
Источник, выбранный атрибутом, имеет приоритет: отсутствующий query-параметр не
подменяется одноимённым полем body. Отсутствующий вложенный путь также сохраняет
default; существующее конечное поле с `null` передаёт этот null.

Загруженные файлы сохраняют исходные имена. Преобразование открывает собственный поток
без чтения всего файла в память; уже переданные объекты `FileInput` используются напрямую.

`RequestFactory` умеет принимать:
- `Illuminate\Http\Request`
- массив с ключами: `route`, `query`, `body`, `headers`, `files`

```php
use ApiSutra\Laravel\RequestFactory\PayloadKeys;

$payload = [
    PayloadKeys::ROUTE => ['id' => 10],
    PayloadKeys::QUERY => ['page' => 1],
    PayloadKeys::BODY => ['name' => 'Alice'],
    PayloadKeys::HEADERS => ['X-Trace' => '1'],
    PayloadKeys::FILES => [],
];
```

## Auto‑discovery клиентов <a id="section-7"></a>
```php
use ApiSutra\Resolver\ClientDiscoveryService;

$discovery = app(ClientDiscoveryService::class);
$discovery->registerAuto($client);
```

## Мультисервисные клиенты <a id="section-8"></a>
Если из контейнера резолвится объект, реализующий `MultiServiceClientInterface`,
`SdkServiceProvider` автоматически вызывает `ServiceRegistrar` и регистрирует
namespace‑ы всех сервис‑клиентов.

## ClientResponseAdapter <a id="section-9"></a>
Адаптер превращает `ClientResponse` в `SymfonyResponse`:
```php
use ApiSutra\Response\ClientResponseFactoryInterface;
use ApiSutra\Laravel\Contracts\ClientResponseAdapterInterface;

$clientResponse = $factory->make($handle->resolved());
$response = $adapter->toResponse($clientResponse);
```

Для файловых ответов адаптер формирует `Content-Disposition` через Symfony,
экранируя кавычки и кодируя Unicode-имена с ASCII fallback. Явно переданный
заголовок имеет приоритет независимо от регистра его имени. Разделители пути `/`
и `\` заменяются на `_` в формируемых именах. Каждый не-ASCII символ заменяется одним
`_` в ASCII fallback; Unicode-имя сохраняется в `filename*`.

## ClientConfigFactory <a id="section-10"></a>

```php
use ApiSutra\Laravel\ClientConfigFactory;

$config = app(ClientConfigFactory::class)->make([
    'baseUrl' => config('services.api.base_url'),
]);
```

Необязательная фабрика возвращает `ClientConfig` ядра. Явные overrides имеют приоритет;
остальные debug/environment читаются из провайдера своего приложения. Явный
`containerProvider` задаёт эти defaults вместо него. Чужое глобальное приложение
не читается. Неизвестное окружение означает production, отсутствие debug — false.

Для `debug` и `environment` явный null также выбирает defaults приложения;
явный false для `debug` сохраняется. Отсутствующий, null или пустой `baseUrl` вызывает
локализованный `ConfigurationException` ядра. Остальные overrides, включая допустимые
null в необязательных блоках конфигурации, передаются без изменений.

Без override или с явным null `containerProvider` остаётся null. При выполнении
конфиг использует текущий registry; чтение defaults не удерживает Application
в конфиге. Для отключения контейнера передайте `NullContainerProvider`, для привязки
к конкретному контейнеру — явный provider. Все config-блоки и LocalizationConfig
передаются без потерь. Создавайте объекты в provider/factory, не в кешируемом Laravel
config. Можно также создавать `new ClientConfig(...)` напрямую.

`LaravelContainerProvider` принимает и standalone Illuminate Container без Laravel
Application. Передайте его в `containerProvider` клиента либо зарегистрируйте через
`ContainerProviderRegistry::set($provider)` для всего приложения.

## Конфигурация SDK <a id="section-11"></a>

Для SDK рекомендуется ключ `apisutra.<provider>.*` и необязательная публикация в
`config/apisutra/<provider>.php`. Без публикации используются defaults provider.
Объекты ClientConfig/auth/cache создаются в фабрике; config-файлы содержат
сериализуемые значения. `env()` читается только при загрузке конфигурации.

В Records используется `apisutra.records.*`:

| Ключ | Environment | Default и поведение |
| --- | --- | --- |
| base_url | RECORDS_BASE_URL | https://records.example.test; непустая строка |
| timeout | RECORDS_TIMEOUT | 30 секунд; целое число >= 0, допустима строковая запись целого из env |
| token | RECORDS_TOKEN | null; непустая строка включает BearerAuthenticator, пустая означает отсутствие auth |

Ошибочные типы дают `ConfigurationException` при создании клиента, до HTTP; значение
токена в сообщение не включается. Учебный API допускает отсутствие токена; требования
реального API определяет SDK. Общая фабрика сохраняет DTO-политики; Laravel-фабрика
читает debug/environment из переданного приложения и задаёт явный containerProvider.

`php artisan vendor:publish --tag=records-config` создаёт конфигурационный файл;
повтор без `--force` сохраняет пользовательские изменения. Частичный override
одного параметра сохраняет defaults остальных. После изменения env/config при
включённом кеше требуется `php artisan config:cache`; готовый singleton сохраняет
свою конфигурацию до создания нового экземпляра.

### Расположение конфигурации <a id="section-12"></a>

Records SDK читает `apisutra.records.base_url` из `config/apisutra/records.php`.
После изменения конфигурации пересоберите кеш конфигурации приложения.
Поля ClientConfig сохраняют свои имена; фабрика SDK преобразует настройки
приложения в эти поля.

## Отключённый package discovery <a id="section-13"></a>

Если приложение отключило discovery, зарегистрируйте `SdkServiceProvider` ApiSutra
и provider своего SDK в `bootstrap/providers.php`. В Records это
`Example\Records\Laravel\DemoServiceProvider`. Для локального SDK внутри приложения
регистрация его собственного provider также выполняется явно.

## Жизненный цикл <a id="section-14"></a>

Default-клиент Records — singleton контейнера с постоянными настройками приложения.
Bootstrap, `package:discover` и `config:cache` не создают его и не выполняют HTTP.
Разные приложения получают независимые default-клиенты и реестры; SDK-фабрика
передаёт свой LaravelContainerProvider явно. Интеграция регистрирует default в registry ядра; явный provider имеет приоритет.
Новый default сразу заменяет предыдущий. Клиент с null использует текущий registry,
без автоматической изоляции приложений или tenant.

Каждому вызову создавайте новый запрос. Для разных аккаунтов одного SDK выбирайте
клиента явно: namespace-регистрация выбирает одного default-исполнителя. Singleton
не должен захватывать credentials текущего HTTP-пользователя. Scoped-клиенты,
смена tenant и hot reload экземпляров требуют отдельной организации приложения.

## Transport по умолчанию <a id="section-15"></a>
Если `TransportInterface` не забинден, сервис‑провайдер пытается:
1) найти PSR‑18 + PSR‑17 в контейнере
2) создать штатный `GuzzleHttpClient` с cURL handler и PSR-17 фабрики
Если это невозможно — будет `ConfigurationException`.

Штатная сборка требует `guzzlehttp/guzzle` и `ext-curl`, автоматически применяет
таймауты 30/10 секунд. Пользовательские bindings сохраняют приоритет. Переданный
PSR-клиент должен реализовать `HttpClientOptionsInterface` для SDK-лимитов; иначе
отправка с ненулевыми дефолтами вернёт `configuration_error` до HTTP. Настройка и
режим без SDK-лимитов — в [руководстве транспорта](https://github.com/apisutra/php/blob/master/docs/ru/reference/execution/transport.md).

Штатная сборка также поддерживает конкурентные sendAsync и pool при cURL AsynchDNS.
Явный синхронный PSR binding сохраняет приоритет: send работает, async возвращает
configuration_error. Для async адаптер реализует AsyncHttpClientInterface, либо
приложение связывает HttpTransport::createDefault(). Новые настройки Laravel
не нужны; Revolt приходит с зависимостью ядра.

Для больших источников тот же клиент поддерживает [pool consume/consumeAsync](https://github.com/apisutra/php/blob/master/docs/ru/reference/execution/pool-consumption.md) без настроек Laravel. В job дождитесь consumeAsync до выхода из handle: это не fire-and-forget. Сохранение данных и checkpoint остаются в приложении.

## Валидация и несколько клиентов <a id="section-16"></a>

При доступном Laravel `validator` проверки `#[Validate]` подключаются автоматически,
без дополнительных настроек клиента. Если клиенту явно передан `containerProvider`,
используется фабрика из этого provider; другие клиенты и глобальный bootstrap её
не подменяют. При объявленных правилах недоступная фабрика даёт `configuration_error`
до HTTP. Запросы без правил и custom preflight не требуют Illuminate Validation.
[Приоритеты, ручная проверка DTO](https://github.com/apisutra/php/blob/master/docs/ru/reference/client/validation.md#section-5).

## Проверенные сценарии <a id="section-17"></a>

Laravel 13 / PHP 8.4+: HTTP, Artisan, lifecycle очереди, DI и config:cache.
[Тестирование](testing.md) описывает строгий SDK fake; Laravel Http::fake() не перехватывает SDK.
Jobs передают данные и получают клиент в handle; сериализация клиента не гарантируется.
Полная поддержка Octane и автоматическая изоляция tenants в контракт не входят.

## Общие квоты между workers <a id="section-18"></a>

Для атомарного учёта используйте выделенное именованное phpredis-соединение и
собирайте backend в ServiceProvider. [Готовый рецепт](https://github.com/apisutra/php/blob/master/docs/ru/reference/integrations/redis.md#section-5).
Наличие Laravel и Redis не включает его автоматически.

При null provider `Validator::useFactory()` сохраняет приоритет перед Laravel validator.

[OAuth2: пользовательские подключения и jobs](../../guides/integration/oauth2.md): grants ядра, хранение и координация приложения.

## Серверный cooldown и срок жизни клиента <a id="cooldown"></a>

ClientConfigFactory передаёт CooldownConfig ядра без изменений; отдельные Laravel-настройки,
Redis или привязка очереди не нужны. Без переопределений локальный cooldown после 429 включён.
[Контракт ядра](https://github.com/apisutra/php/blob/master/docs/ru/reference/execution/cooldown.md).

```php
use ApiSutra\Config\CooldownConfig;
use ApiSutra\Config\RetryConfig;
use ApiSutra\Laravel\ClientConfigFactory;

$config = $app->make(ClientConfigFactory::class)->make([
    'baseUrl' => 'https://api.example',
    'cooldown' => new CooldownConfig(group: 'reports'),
    'retry' => new RetryConfig(attempts: 1, totalTimeoutMs: 60_000),
]);
```

По умолчанию cooldown локален: новый клиент, bootstrap или сброс scoped binding начинают заново. Само слово singleton не гарантирует общность между
HTTP-запросами или процессами; Redis backend квот этого не меняет. Не удерживайте process-wide
клиент с credentials текущего пользователя.

Если вызов не может ждать, действующий запрет возвращает rate_limited с reason
server_cooldown_active и retryAfterMs. Job может обработать результат и запланировать повтор
средствами приложения, учитывая повторные побочные эффекты; SDK не ставит jobs обратно в очередь.
Для ожидающего импорта задайте конечный totalTimeoutMs каждому элементу или один общий внешний
deadline. Без бюджета автоматическое дополнительное ожидание ограничено 1000 ms.
Внутри job обязательно дождитесь sendAsync; это не fire-and-forget.

[Общий cooldown через Redis](cooldown.md#shared-cooldown): подключение, области и цена I/O.

## Конкурентная пагинация <a id="pagination"></a>

ClientConfigFactory передаёт PaginationRule без изменений; request-first DI использует то же правило.
Задайте `paginationRule: PaginationRule::all(concurrency: 3)` в конфиге ядра или вызовите
`$request->paginate()->withConcurrency(3)->all()`. Async — через `rules(...)->sendAsync()->wait()`.
Нужен конкурентный транспорт: явный sync-only PSR-18 binding сохраняет обычный send,
но отклоняет конкурентный обход до HTTP. Jobs, воркеры очереди и новые bindings не нужны.
У каждого обхода своё состояние и общий срок; работа на следующий запрос не откладывается.
Дождитесь async-результата до выхода из контроллера/job. Поддержка Octane/Swoole этим не обещается.
[Границы, cursor, порядок, память и сроки](https://github.com/apisutra/php/blob/master/docs/ru/reference/execution/pagination.md#section-21).

[Пагинация и фабрики DTO](pagination.md).

## Возможности <a id="capabilities"></a>

[Строгий fake](testing.md) · [События и текущее приложение](observability.md) ·
[Middleware очереди](queue.md) · [Artisan](generation.md).
