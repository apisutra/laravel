<!-- languages --> <a href="README.md">English</a> · <a href="docs/ru/overview.md">Русский</a> <!-- /languages -->

<h1 align="center" id="section-1">ApiSutra Laravel</h1>

<p align="center">
  <a href="https://github.com/apisutra/laravel/actions/workflows/tests.yml"><img src="https://github.com/apisutra/laravel/actions/workflows/tests.yml/badge.svg?branch=master&amp;event=push" alt="CI"></a>
  <a href="composer.json"><img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4" alt="PHP 8.4+"></a>
  <a href="composer.json"><img src="https://img.shields.io/badge/Laravel-13.12%2B-64748b" alt="Laravel 13.12+"></a>
  <a href="https://packagist.org/packages/apisutra/laravel"><img src="https://img.shields.io/packagist/v/apisutra/laravel" alt="Packagist"></a>
  <a href="docs/en/README.md"><img src="https://img.shields.io/badge/docs-EN%20%2F%20RU-2563eb" alt="Documentation: EN / RU"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green" alt="MIT license"></a>
</p>

Use [ApiSutra SDKs](https://github.com/apisutra/php) through familiar Laravel tools: dependency injection, lazy collections, fakes, events, queue middleware, and Artisan.

The core handles HTTP, DTOs, authentication, retries, and concurrency. This package connects those capabilities to your application. **Laravel 13.12+ · PHP 8.4+.**

[Use an SDK](docs/en/guides/integration/laravel.md) · [Build an SDK](docs/en/guides/sdk/laravel.md) · [Reference](docs/en/reference/integrations/laravel.md)

## Install <a id="installation"></a>

```bash
composer require apisutra/laravel
```

Composer installs the core too; package discovery registers the integration. Default bindings work without publishing configuration or setting up a database, Redis, or workers. Your SDK supplies its client, API address, and credentials. Explicit application bindings retain priority.

## Use your SDK through DI <a id="client"></a>

With a Laravel-compatible SDK registered and configured, resolve its client or inject it into a controller or job. `CatalogClient` and `GetProduct` below represent your SDK classes:

```php
use App\Sdk\CatalogClient;
use App\Sdk\GetProduct;

$client = app(CatalogClient::class);
$product = $client->send(new GetProduct(42))->dataOrFail();
```

`ClientConfigFactory` supplies application debug/environment defaults. Requests are also available through DI; transferring incoming HTTP input is an explicit `RequestFactory` operation. [Setup and configuration →](docs/en/reference/integrations/laravel.md)

## Process paginated data lazily <a id="pagination"></a>

For a paginated SDK request already bound to its client, and your application's `$repository`:

```php
use ApiSutra\Laravel\Pagination;

Pagination::collect($request->paginate())
    ->filter(fn ($item) => $item->active)
    ->take(100)
    ->each(fn ($item) => $repository->save($item));
```

This is a `LazyCollection` over the core's sequential item stream. DTOs remain objects, pages load as needed, and a FAILED page throws instead of silently truncating the list. [Collection behavior →](docs/en/reference/integrations/pagination.md)

## Import from an Artisan command <a id="bulk"></a>

Use the registered client for a catalog synchronization. Here `$requests` is a lazy iterable of SDK requests; `$onResult` and `$onError` are application handlers receiving a result or exception plus the source request:

```php
use App\Sdk\CatalogClient;

$summary = app(CatalogClient::class)
    ->pool($requests, concurrency: 5)
    ->withResponseHandler($onResult)
    ->withExceptionHandler($onError)
    ->consume();
```

With the built-in transport, up to five requests run concurrently; handlers process results without retaining the full collection. The command waits for completion and can report `$summary->successful` and `$summary->failed`. This uses the core's existing [pool consumption](https://github.com/apisutra/php/blob/master/docs/en/reference/execution/pool-consumption.md) through Laravel DI, with the client's configured auth and execution policies.

## Test the client your application uses <a id="testing"></a>

Add `ApiSutra\Laravel\Testing\InteractsWithApiSutra` to your base Laravel TestCase once. In a test where `/products/42` uses the registered `CatalogClient`:

```php
use App\Sdk\CatalogClient;
use App\Sdk\GetProduct;
use ApiSutra\Laravel\Facades\ApiSutra;
use ApiSutra\Testing\MockResponse;

$catalog = ApiSutra::for(CatalogClient::class);
$catalog->fake([GetProduct::class => MockResponse::success(['id' => 42])]);

$this->getJson('/products/42')->assertOk();
$catalog->assertSent(GetProduct::class, times: 1);
```

Missing mocks fail the test even if the application ignores the SDK result. Teardown restores the original transport. Pass `ApiSutra::for($client)` for a specific instance; class lookup requires a shared/scoped binding. Assertions count **HTTP transport attempts**, including retries, rather than business operations. [Testing contract →](docs/en/reference/integrations/testing.md)

## Release a throttled job <a id="queue"></a>

In an existing queued job using Laravel's `Queueable` trait, add this middleware method. Its `handle()` continues to use the SDK normally:

```php
use ApiSutra\Laravel\Queue\ReleaseOnSdkThrottle;

public function middleware(): array
{
    return [new ReleaseOnSdkThrottle()];
}
```

When local quota or an active server cooldown refuses admission, middleware interrupts the job and releases it with a delay. **The whole job will repeat:** earlier HTTP requests and application changes are not rolled back. Attach it only when those effects can safely repeat; delayed release requires a queue connection that schedules delayed jobs. [Queue behavior and limits →](docs/en/reference/integrations/queue.md)

## See which SDK operations are slow or failing <a id="observation"></a>

Enable `apisutra.integration.observation.enabled` in application configuration, or use automatic observation with active Telescope outside testing. Register a listener in your application provider's `boot()`:

```php
use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(ExecutionCompleted::class, static function (ExecutionCompleted $event): void {
    Log::info('SDK operation completed', $event->snapshot->data);
});
```

The log entry includes the operation, outcome, duration, HTTP attempt count, and reason/stage when available. Your listener can send these summaries to application metrics too. Capture uses a bounded buffer; events are delivered at HTTP, job, or command boundaries. By default, only root executions are observed. [Payload, delivery limits and logging without a listener →](docs/en/reference/integrations/observability.md)

## Capability map <a id="capabilities"></a>

| Need | Laravel integration |
| --- | --- |
| Connect an SDK | [Discovery, client/request DI and configuration defaults](docs/en/reference/integrations/laravel.md), with explicit overrides and `config:cache` support. |
| Handle HTTP input and output | [RequestFactory and ClientResponseAdapter](docs/en/reference/integrations/laravel.md) for input mapping and JSON, text or streamed file responses; Laravel validation. |
| Use collections and application services | [LazyCollection pagination and custom hydrator DI](docs/en/reference/integrations/pagination.md). |
| Test without the provider API | [Isolated fakes and assertions](docs/en/reference/integrations/testing.md), plus automatic checks for missing responses. |
| Observe API operations | [ExecutionCompleted events](docs/en/reference/integrations/observability.md), duration/outcome summaries, Context correlation, optional Telescope and a selected log channel. |
| Release throttled jobs | [ReleaseOnSdkThrottle](docs/en/reference/integrations/queue.md) returns a job to the queue on local quota or server-cooldown refusal. Explicit opt-in to replay the **whole job**. |
| Use OAuth2 accounts | [Core grants with application-owned credential storage](docs/en/guides/integration/oauth2.md) and coordination across jobs/processes. |
| Coordinate rate limits | [Optional Redis cooldown](docs/en/reference/integrations/cooldown.md) shared by clients in the same quota scope. |
| Generate and inspect | [Artisan client/request/DTO generators and `artisan about`](docs/en/reference/integrations/generation.md). |

## Execution and lifecycle <a id="lifecycle"></a>

With the built-in Guzzle transport, async requests and pools need no event-loop setup. Await results before a controller or job returns; `sendAsync()` does not schedule a background job. Queue middleware is optional and applies to repeatable jobs in applications already using Laravel queues.

Supported scenarios include ordinary HTTP requests, Artisan, and sequential queue jobs. Full Octane/Swoole support and automatic tenant switching are not promised; use the documented [client lifecycle and boundaries](docs/en/reference/integrations/laravel.md#section-14).

## Next steps <a id="documentation"></a>

- [Use an existing SDK in Laravel](docs/en/guides/integration/laravel.md).
- [Add Laravel support to your SDK](docs/en/guides/sdk/laravel.md).
- [Browse the documentation](docs/en/README.md) or [core capabilities](https://github.com/apisutra/php#section-5).

[Changelog](CHANGELOG.md) · [Development](https://github.com/apisutra/laravel/blob/master/docs/en/development/testing.md) · [MIT license](LICENSE).
