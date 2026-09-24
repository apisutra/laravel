<!-- languages --> <a href="generation.md">English</a> · <a href="../../../ru/reference/integrations/generation.md">Русский</a> <!-- /languages -->
# SDK scaffolding in Artisan <a id="generation"></a>

```bash
php artisan make:apisutra-client 'Sdk\CatalogClient'
php artisan make:apisutra-dto 'Sdk\Product'
php artisan make:apisutra-request 'Sdk\GetProduct' --endpoint=/products/1 --dto='App\Sdk\Product'
```

These commands use the core [generator](https://github.com/apisutra/php/blob/master/docs/en/reference/client/generation.md),
not separate Laravel templates. By default Laravel selects its application namespace
and the corresponding PSR-4 directory, even when the map also contains factories or seeders.
App is an example, not a requirement. For an external SDK or ambiguous mapping:

```bash
php artisan make:apisutra-client Client --namespace='Acme\Catalog' --directory=packages/catalog/src
```

The output is an ordinary core client/request/DTO, also usable standalone. The request
uses GET; add the real method, route and fields yourself. An unspecified route is
`/replace-me`; an unspecified DTO is not declared. No provider, tenant manager, auth
scheme or service binding is invented. Existing files and invalid/escaping paths are
refused, with no force overwrite. Filesystem policy and templates match standalone CLI.
No configuration publishing is needed. `php artisan about` also shows transport-binding
and observation status without constructing clients or contacting the API.

## Application DI <a id="di"></a>

Use ordinary Laravel bindings and its existing contextual attributes for application
services. For example, `#[Config('services.catalog.account')] string $account` on an
application service constructor reads that configured value. `#[Give(CatalogClient::class)]`
selects the implementation of an interface parameter. These are Laravel attributes;
ApiSutra introduces no additional tenancy registry. Construct account-specific clients
explicitly in your bindings, and pass the actual instance to `ApiSutra::for($client)` in tests.
A diagnostic label names telemetry; it never selects an account or partitions quota keys.

[Integration](laravel.md) · [Testing](testing.md).
