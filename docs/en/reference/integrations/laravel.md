<!-- languages --> <a href="laravel.md">English</a> · <a href="../../../ru/reference/integrations/laravel.md">Русский</a> <!-- /languages -->
# Laravel integration <a id="section-1"></a>

Requires Laravel 13.12+, PHP 8.4+ and `apisutra/php:^0.1 || ^0.2`. Install `apisutra/laravel`; it supplies package discovery. No mandatory configuration file or publication is required.

The package includes `SdkServiceProvider`, which connects DI, auto-discovery,
RequestFactory, and the response adapter. Laravel loads the provider automatically
through package discovery; publishing configuration is unnecessary. If Laravel
discovery is disabled, add `SdkServiceProvider::class` to `bootstrap/providers.php`.
Without Laravel, the core continues to work with an explicit SDK client and transport.

A ready-made SDK supplies its own provider: it constructs the client from application
configuration and registers request namespaces. The SDK author defines the protocol,
defaults, and authentication rules; the application supplies credentials and overrides.
[Use an existing SDK](../../guides/integration/laravel.md) ·
[Add integration to your SDK](../../guides/sdk/laravel.md).

## Registering and overriding a client <a id="section-2"></a>

The shipped [Records SDK](https://github.com/apisutra/php/blob/master/docs/en/examples/sdk.md) supports the rules below; other SDKs must document their own registration guarantees.

- The default client is created lazily through `singletonIf`; existing application
  instances and bindings are preserved. Namespace registration is separate from the client factory.
- You can resolve the client first or resolve a request with `makeWith(GetRecordRequest::class,
  ['id' => 7])`. The caller supplies required constructor parameters.
- The SDK's general `resolving` callback runs before the adapter's typed callback:
  a request without a client receives the registered executor. An explicit client is preserved.
- Hooks attach in `boot`. `callAfterResolving` handles a client already created;
  registering the provider again does not duplicate hooks.
- Override the client and transport before first use: before package providers, or
  afterwards in the application's `register()`. Pass an SDK-specific transport in
  that SDK's own client binding. Changing config does not rebuild an existing singleton.
- Multiple SDKs use different types, config keys, and request namespaces. The longest
  namespace wins; conflicting owners cause `ConfigurationException`. There is no
  implicit replacement through `force`.

`ServiceRegistrar` uses explicit `requestNamespaces()` when the client implements
`RequestNamespaceProviderInterface`; otherwise it uses the existing detector.
Successful registration is remembered in the registrar instance. One live client can
be registered in independent registries. Retrying after an error is allowed: after
partial completion, successful bindings remain, and a corrected declaration adds the
missing ones. A namespace already owned by another client remains a conflict.
`ClientRegistry::register()` itself still rejects duplicates without explicit `force`.

## Registered services <a id="section-3"></a>

- `ApiSutra\Laravel\ClientConfigFactory`.
- `ApiSutra\Laravel\Contracts\RequestFactoryInterface`.
- `ApiSutra\Laravel\Contracts\ClientResponseAdapterInterface`.
- `ClientRegistry` / `ClientResolver`.
- `ClientDiscoveryService`.
- `ServiceRegistrar` (for multi-service clients).
- Default `TransportInterface` (if none is configured).
- [TestingManager](testing.md) and [ObservationManager / ExecutionObserverInterface](observability.md); explicit bindings are preserved.

## Request DI <a id="section-4"></a>

Ordinary DI preserves SDK request values and binds a client if none is set.
It does not read the incoming `Illuminate\Http\Request`: `limit=500` in the browser
does not replace `limit=20` set by the application. The same rules apply in Artisan
and jobs. Create requests with required constructor arguments explicitly or provide
a normal Laravel binding.

### Explicit population from HTTP input <a id="section-5"></a>

To fill a request from incoming HTTP data, explicitly call `RequestFactoryInterface`.
In this example, `GetUser` and `UserClient` are application SDK classes.

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

## RequestFactory and payload keys <a id="section-6"></a>
Missing input fields preserve property and constructor defaults. An explicit `null`
is passed to the declared field; PHP rejects it for non-nullable fields and constructor parameters.
The source selected by an attribute is authoritative: a missing query parameter does
not fall back to a body field with the same name. A missing nested path also preserves
the default; an existing leaf with `null` passes that null through.

Uploaded files retain their original names. Conversion opens an owned stream without
loading the entire file into memory; already supplied `FileInput` objects are reused.

`RequestFactory` accepts:
- `Illuminate\Http\Request`.
- An array with `route`, `query`, `body`, `headers`, and `files` keys.

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

## Client auto-discovery <a id="section-7"></a>
```php
use ApiSutra\Resolver\ClientDiscoveryService;

$discovery = app(ClientDiscoveryService::class);
$discovery->registerAuto($client);
```

## Multi-service clients <a id="section-8"></a>
When the container resolves an object implementing `MultiServiceClientInterface`,
`SdkServiceProvider` automatically calls `ServiceRegistrar` and registers the
namespaces of all service clients.

## ClientResponseAdapter <a id="section-9"></a>
The adapter converts `ClientResponse` into `SymfonyResponse`:
```php
use ApiSutra\Response\ClientResponseFactoryInterface;
use ApiSutra\Laravel\Contracts\ClientResponseAdapterInterface;

$clientResponse = $factory->make($handle->resolved());
$response = $adapter->toResponse($clientResponse);
```

For file responses, the adapter generates `Content-Disposition` through Symfony,
escaping quotes and encoding Unicode names with an ASCII fallback. An explicitly
supplied header takes priority, regardless of its name's casing. Path separators `/`
and `\` become `_` in generated filenames. Each non-ASCII character becomes one `_`
in the ASCII fallback; the Unicode filename is preserved in `filename*`.

## ClientConfigFactory <a id="section-10"></a>

```php
use ApiSutra\Laravel\ClientConfigFactory;

$config = app(ClientConfigFactory::class)->make([
    'baseUrl' => config('services.api.base_url'),
]);
```

The optional factory returns the core `ClientConfig`. Explicit overrides win; otherwise
it reads debug/environment from its own application provider. An explicit
`containerProvider` supplies those defaults instead. It never consults another global
application. Unknown environments map to production; absent debug maps to false.

For `debug` and `environment`, explicit null also selects the application defaults;
explicit false for `debug` is preserved. A missing, null or empty `baseUrl` raises
the core's localized `ConfigurationException`. Other overrides, including allowed
null values in optional config blocks, pass through unchanged.

Without an override, or with explicit null, `containerProvider` stays null. The config
uses the current registry during execution; reading application defaults does not
retain Application in the config. Pass `NullContainerProvider` to disable container
access, or an explicit provider to bind a particular container. All config blocks and
LocalizationConfig pass through unchanged. Create objects in a provider/factory,
not in cached Laravel config. You can also construct `new ClientConfig(...)` directly.

`LaravelContainerProvider` also accepts a standalone Illuminate Container without a
Laravel Application. Pass it as the client's `containerProvider`, or register it through
`ContainerProviderRegistry::set($provider)` for application-wide use.

## SDK configuration <a id="section-11"></a>

The recommended SDK key is `apisutra.<provider>.*`, with optional publication to
`config/apisutra/<provider>.php`. Without publication, provider defaults apply.
ClientConfig/auth/cache objects are created in the factory; config files contain
serializable values. `env()` is read only when configuration is loaded.

Records uses `apisutra.records.*`:

| Key | Environment | Default and behavior |
| --- | --- | --- |
| base_url | RECORDS_BASE_URL | https://records.example.test; nonempty string |
| timeout | RECORDS_TIMEOUT | 30 seconds; integer >= 0, including an integer string from env |
| token | RECORDS_TOKEN | null; a nonempty string enables BearerAuthenticator, an empty value means no auth |

Invalid types cause `ConfigurationException` during client creation, before HTTP;
the token value is not included in the message. The teaching API allows no token;
the SDK defines the requirements of a real API. The shared factory preserves DTO
policies; the Laravel factory reads debug/environment from the supplied application
and sets an explicit containerProvider.

`php artisan vendor:publish --tag=records-config` creates the configuration file;
repeating it without `--force` preserves user changes. A partial override of one
parameter preserves the other defaults. After changing env/config with caching enabled,
run `php artisan config:cache`; an existing singleton retains its configuration until
a new instance is created.

### Configuration location <a id="section-12"></a>

The Records SDK reads `apisutra.records.base_url` from `config/apisutra/records.php`.
After changing the configuration, rebuild the application config cache.
ClientConfig fields retain their names; the SDK factory maps application settings
to those fields.

## Disabled package discovery <a id="section-13"></a>

If the application disables discovery, register ApiSutra's `SdkServiceProvider` and
your SDK provider in `bootstrap/providers.php`. For Records, the latter is
`Example\Records\Laravel\DemoServiceProvider`. A local SDK inside the application
also requires explicit registration of its own provider.

## Lifecycle <a id="section-14"></a>

The default Records client is a container singleton with fixed application settings.
Bootstrap, `package:discover`, and `config:cache` neither create it nor perform HTTP.
Separate applications get independent default clients and registries; the SDK factory
passes its LaravelContainerProvider explicitly. The integration registers a default in the core registry; an explicit registry provider wins.
A new default replaces the previous application immediately. With null, a client uses
the current default; this does not provide automatic application or tenant isolation.

Create a new request for each call. For different accounts within one SDK, select
the client explicitly: namespace registration selects one default executor.
A singleton must not capture the current HTTP user's credentials. Scoped clients,
tenant changes, and instance hot reload require separate application design.

## Default transport <a id="section-15"></a>
If `TransportInterface` is not bound, the service provider tries to:
1) Find PSR-18 + PSR-17 in the container.
2) Create the built-in `GuzzleHttpClient` with its cURL handler and PSR-17 factories.
If neither is possible, it raises `ConfigurationException`.

The built-in setup requires `guzzlehttp/guzzle` and `ext-curl`, and automatically
applies 30/10-second timeouts. User bindings retain priority. A supplied PSR client
must implement `HttpClientOptionsInterface` for SDK limits; otherwise sending with
nonzero defaults returns `configuration_error` before HTTP. See the
[transport guide](https://github.com/apisutra/php/blob/master/docs/en/reference/execution/transport.md) for configuration and operation without SDK limits.

The default also supports concurrent sendAsync and pool with cURL AsynchDNS. An
explicit synchronous-only PSR binding keeps priority: send works, while async returns
configuration_error. The adapter must implement AsyncHttpClientInterface for async,
or the application can bind HttpTransport::createDefault(). No new Laravel settings
are needed; Revolt comes through the core dependency.

For large sources, the same client supports [pool consume/consumeAsync](https://github.com/apisutra/php/blob/master/docs/en/reference/execution/pool-consumption.md) without Laravel settings. In a job, await consumeAsync before returning from handle; it is not fire-and-forget. Application code owns persistence and checkpoints.

## Validation and multiple clients <a id="section-16"></a>

When Laravel's `validator` is available, `#[Validate]` checks attach automatically,
without additional client configuration. If the client receives an explicit
`containerProvider`, the factory comes from that provider; other clients and global
bootstrap do not replace it. With declared rules, an unavailable factory produces
`configuration_error` before HTTP. Requests without rules and custom preflight
validation do not require Illuminate Validation.
[Priorities, manual DTO validation](https://github.com/apisutra/php/blob/master/docs/en/reference/client/validation.md#section-5).

## Verified contexts <a id="section-17"></a>

Laravel 13 / PHP 8.4+ integration covers HTTP, Artisan, queue lifecycle, DI and config:cache.
[Testing](testing.md) describes strict SDK fake; Laravel Http::fake() does not intercept SDK traffic.
Jobs carry data and resolve clients in handle; serializing clients is not guaranteed.
Full Octane and automatic tenant isolation are outside this contract.

## Shared quotas across workers <a id="section-18"></a>

For atomic accounting, use a dedicated named phpredis connection and construct the
backend in a ServiceProvider. See the [recipe](https://github.com/apisutra/php/blob/master/docs/en/reference/integrations/redis.md#section-5).
Having Laravel and Redis installed does not enable it automatically.

With null provider, `Validator::useFactory()` still takes priority over the Laravel validator.

[OAuth2 user connections and jobs](../../guides/integration/oauth2.md): core grants with application-owned storage and coordination.

## Server cooldown and client lifetime <a id="cooldown"></a>

ClientConfigFactory accepts the core CooldownConfig unchanged; no Laravel-only settings,
Redis connection, or queue binding are required. Without overrides, local cooldown after
429 is enabled. [Core contract](https://github.com/apisutra/php/blob/master/docs/en/reference/execution/cooldown.md).

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

By default cooldown is local: a new client, bootstrap or reset scoped binding starts fresh. The word singleton alone guarantees neither
cross-request nor cross-process sharing; a Redis quota backend does not change this.
Do not retain a process-wide client holding the current user's credentials.

An active prohibition returns rate_limited with reason server_cooldown_active and
retryAfterMs when the call cannot wait. A job may handle that result and reschedule through
the application, considering repeated side effects; automatic release requires [queue middleware](queue.md).
For an import that should wait, set a finite totalTimeoutMs per element or one shared
external deadline. Without a budget, automatic additional waiting is limited to 1000 ms.
Always await sendAsync inside the job; it is not fire-and-forget.

[Shared cooldown through Redis](cooldown.md#shared-cooldown): setup, scopes and I/O cost.

## Concurrent pagination <a id="pagination"></a>

ClientConfigFactory forwards PaginationRule unchanged; request-first DI uses the same rule.
Set `paginationRule: PaginationRule::all(concurrency: 3)` on the core config, or call
`$request->paginate()->withConcurrency(3)->all()`. Async uses `rules(...)->sendAsync()->wait()`.
A concurrent transport is required: an explicit sync-only PSR-18 binding keeps ordinary send
but rejects concurrent traversal before HTTP. No jobs, queue workers or extra bindings are needed.
Each traversal owns its state and shared deadline; nothing is scheduled for a later request.
Await async results before a controller/job finishes. This adds no Octane/Swoole compatibility promise.
[Bounds, cursor, ordering, memory and deadlines](https://github.com/apisutra/php/blob/master/docs/en/reference/execution/pagination.md#section-21).

[Lazy pagination collections and DTO factory injection](pagination.md).

## Capabilities <a id="capabilities"></a>

[Strict fake](testing.md) · [Events and current application](observability.md) ·
[Queue middleware](queue.md) · [Artisan](generation.md).
