<!-- languages --> <a href="laravel.md">English</a> · <a href="../../../ru/guides/integration/laravel.md">Русский</a> <!-- /languages -->
# Use an existing SDK in Laravel <a id="section-1"></a>

Install a Laravel-compatible SDK through Composer following its instructions. Its provider
connects the client; ApiSutra's provider supplies shared integration mechanisms. A normal
installation does not require copying the provider into your application.

The examples below use classes from the [tutorial Records SDK](https://github.com/apisutra/php/blob/master/docs/en/examples/sdk.md#section-2).
It is a fictional API and a locally installed sample; no ready-made package with this name
exists on Packagist. Run its `run.php` to check it without network access.

Install the integration into a Laravel 13 application (PHP 8.4+):

```bash
composer require apisutra/laravel:^0.1
```

This also installs `apisutra/php:^0.1`. The sample SDK needs this explicit installation
for its automatic Laravel client/request DI. Without the adapter, explicit construction
still works; see the [three environments](https://github.com/apisutra/php/blob/master/docs/en/examples/sdk.md#environments).

## Configure settings <a id="section-2"></a>

After installing Records SDK, its defaults are available without publishing configuration.
Set parameters in your application's `.env`:

```dotenv
RECORDS_BASE_URL=https://records.example.test
RECORDS_TIMEOUT=15
RECORDS_TOKEN=your-token
```

The tutorial SDK's token is optional: a nonempty string enables Bearer authentication.
For a real SDK, its documentation defines the address, required credentials, and supported
authentication methods. See [all Records settings](../../reference/integrations/laravel.md#section-11).

## Obtain the client <a id="section-3"></a>

Snippet for an application with a configured transport and installed SDK:

```php
use Example\Records\DemoClient;
use Example\Records\Resources\Records\Get\GetRecordResponseDto;

$client = app(DemoClient::class);
/** @var GetRecordResponseDto $record */
$record = $client->records()->get(7)->send()->dataOrFail();
```

You can also receive the client through DI as a constructor or controller-method argument.
Its settings come from the binding; a separately created `$config` variable does not change them.
See [results, statuses, and errors](https://github.com/apisutra/php/blob/master/docs/en/reference/results/handles.md).

## Publish configuration if needed <a id="section-4"></a>

```bash
php artisan vendor:publish --tag=records-config
```

This creates `config/apisutra/records.php`. Keys are available through
`config('apisutra.records.*')`; an ordinary repeat publish preserves file changes.
After changing application settings with config caching enabled, rebuild it using
`php artisan config:cache`.

## Verify integration <a id="section-5"></a>

For tests, add `InteractsWithApiSutra` to the Laravel base TestCase as shown in
[isolated fakes](../../reference/integrations/testing.md), then replace responses for
the same client obtained by the application:

```php
use ApiSutra\Laravel\Facades\ApiSutra;
use ApiSutra\Testing\MockResponse;
use Example\Records\DemoClient;
use Example\Records\Resources\Records\Get\GetRecordRequest;

$records = ApiSutra::for(DemoClient::class);
$records->fake([GetRecordRequest::class => MockResponse::success(['data' => [
    'record_id' => 7,
    'title' => 'Record',
    'created_at' => '2026-09-15T10:30:00+00:00',
]])]);

$record = app(DemoClient::class)->records()->get(7)->send()->dataOrFail();
$this->assertSame(7, $record->id);
$records->assertSent(GetRecordRequest::class, times: 1);
```

By default, the Records client is a singleton with fixed application settings.
Multiple accounts and mutable credentials require explicit client selection.
Ordinary DI preserves SDK request values; an explicit `RequestFactory` transfers incoming
HTTP payloads. See [lifecycle and limitations](../../reference/integrations/laravel.md#section-14).

With package discovery disabled, use
[explicit registration](../../reference/integrations/laravel.md#section-13).
If you are building an SDK, follow the separate route to
[add Laravel integration to your SDK](../sdk/laravel.md).

## Async execution

With the built-in core Guzzle transport, sendAsync and pool overlap HTTP automatically;
no event-loop service binding or Revolt configuration is needed. Explicit transport
and PSR-18 bindings retain priority. A synchronous-only PSR client still supports
send(), but async (including pool) returns configuration_error before auth/quota/HTTP.
Use the built-in HttpTransport::createDefault() or a compatible concurrent adapter.

Jobs and controllers must keep async promises and call wait() before returning; sendAsync
is not fire-and-forget. Downloads finish into their sink before the promise resolves,
so ClientResponseAdapter can emit StreamedResponse later, outside the SDK execution.
Finish or cancel pending work before the request/job ends. SDK timers are removed
when work finishes; the application's loop and error handler are preserved.

Synchronous hooks, cache/Redis calls and file I/O can block other tasks. These checks
cover ordinary Laravel bootstrap and sequential jobs, not Octane, Swoole coroutines,
FrankenPHP worker mode or platform-specific loop adapters.

[OAuth2 user connections and jobs](oauth2.md): core grants with application-owned storage and coordination.

[Lazy pagination collections and DTO factory injection](../../reference/integrations/pagination.md).

Use [isolated fakes](../../reference/integrations/testing.md) in tests and
[execution events](../../reference/integrations/observability.md) for observation.
Repeatable jobs can opt into [throttle middleware](../../reference/integrations/queue.md).
