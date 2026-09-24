<!-- languages --> <a href="laravel.md">English</a> · <a href="../../../ru/guides/sdk/laravel.md">Русский</a> <!-- /languages -->
# Add Laravel integration to your SDK <a id="section-1"></a>

This route is for SDK authors. Users of an existing package should start with
[Laravel setup](../integration/laravel.md).

The Laravel route requires `apisutra/laravel:^0.1` on Laravel 13. Declare this dependency
in a Laravel-only SDK. A universal SDK, such as Records, requires only the core and
suggests the adapter: the application installs it explicitly. Composer suggest does not
install dependencies. One package contains both the SDK and its optional provider.

## Prepare a standalone client <a id="section-2"></a>

The client, requests, DTOs, and shared configuration factory must work through explicit
`new Client($config, $transport)` without Laravel. Put application and configuration
access in your SDK's `src/Laravel/`. The [Records SDK](https://github.com/apisutra/php/blob/master/docs/en/examples/sdk.md)
contains one set of classes for both ways of running it.

## Describe the package <a id="section-3"></a>

In the SDK's Composer manifest, declare a dependency on a suitable ApiSutra version,
PSR-4 autoloading for your classes, and `extra.laravel.providers`. The complete example is
[composer.json](https://github.com/apisutra/php/blob/master/docs/example/sdk/composer.json). In Laravel, package discovery loads
this provider; consumers do not need separate manual registration.

Laravel remains an optional SDK environment. The provider may extend
`Illuminate\Support\ServiceProvider`, but ordinary SDK classes must not require
loading it for a standalone call.

## Assemble the client and register requests <a id="section-4"></a>

[DemoServiceProvider](https://github.com/apisutra/php/blob/master/docs/example/sdk/src/Laravel/DemoServiceProvider.php) shows
the complete implementation; [LaravelClientConfigFactory](https://github.com/apisutra/php/blob/master/docs/example/sdk/src/Laravel/LaravelClientConfigFactory.php)
converts application settings into client configuration.

Sequence:

1. In `register()`, merge defaults and set a lazy binding through `singletonIf`.
2. In `boot()`, arrange registration of the resulting client through `callAfterResolving`.
   This also applies to an instance supplied by a user binding.
3. In a general `resolving` callback, filter requests by your namespace. If a request
   has no client yet, obtain and register the default client before ApiSutra's typed
   `AbstractRequest` handler.
4. Specify the namespace explicitly through `RequestNamespaceProviderInterface`.
   This lets `ServiceRegistrar` avoid class scanning.

Check the constructed request object: an explicitly bound client must be preserved.
Repeated bootstrap must not add hooks or registrations again.
If the adapter is absent, discovery and config caching must still work. Check its
availability only when automatic client/request DI is requested and report the install
command. Explicit construction and already-bound instances remain usable.
Final guarantees and precedence are in the
[registration contract](../../reference/integrations/laravel.md#section-2).

## Ship configuration <a id="section-5"></a>

[config/records.php](https://github.com/apisutra/php/blob/master/docs/example/sdk/config/records.php) contains only values suitable
for `config:cache`. Read `env()` in the configuration file. Build authentication objects
and dependencies in the provider/factory when creating the client.

Use one key, `apisutra.<provider>.*`, and optional publication to
`config/apisutra/<provider>.php`. Preserve the shared SDK factory's defaults;
Records passes `ClientConfigFactory::defaults()` to the adapter's `ClientConfigFactory`,
adding SDK settings and auth. The adapter reads debug/environment from its Application;
`containerProvider` stays null. An explicit provider is an application override,
not a requirement: see [container binding](../../reference/integrations/laravel.md).
Do not create the client during `package:discover` or perform HTTP during bootstrap.

## Verify installation <a id="section-6"></a>

Install the SDK in a separate application through a Composer path repository declared
in that application's manifest. Then check:

- Discovery of both providers and resolving an SDK request before the first client access.
- Client and transport overrides, including a preconstructed instance.
- Operation without publication, file preservation on repeat publish, and `config:cache`.
- Two SDKs and sequential jobs without request-parameter leakage.
- Production installation of the same SDK without Laravel or require-dev.
- Laravel without the adapter: discovery and config cache, explicit construction,
  preserved bindings and an actionable error only when automatic SDK DI is requested.

Keep executable SDK examples in one place; installation must test the same sources
that developers read. See [release preparation](https://github.com/apisutra/php/blob/master/docs/en/guides/sdk/release.md).

## If the SDK lives inside an application <a id="section-7"></a>

Put client classes in the application's namespace and the provider, for example,
in `App\Providers`. Register it in `bootstrap/providers.php` and set application
configuration. This is a local development approach; when extracting the SDK into
a Composer package, move the provider and defaults into the SDK with its classes.

The [complete Laravel contract](../../reference/integrations/laravel.md) covers lifetime,
multiple clients, explicit RequestFactory use, and integration boundaries.

[Artisan](../../reference/integrations/generation.md) creates the same core skeletons as standalone CLI.
