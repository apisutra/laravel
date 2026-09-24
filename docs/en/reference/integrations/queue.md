<!-- languages --> <a href="queue.md">English</a> · <a href="../../../ru/reference/integrations/queue.md">Русский</a> <!-- /languages -->
# Releasing a throttled job <a id="queue"></a>

`ReleaseOnSdkThrottle` is an explicit choice to replay the **whole job** when SDK HTTP
admission is refused. Attach it only to jobs whose earlier HTTP and application effects
can safely repeat. The middleware does not undo those effects or prove idempotency.
The core SDK needs no queue or worker; this integration is for applications already using Laravel queues.

The job must expose public `release()`, normally through Laravel's `Queueable` or
`InteractsWithQueue` trait. Otherwise middleware throws ConfigurationException before
calling handle, with a message in the application's language (`apisutra::queue.release_required`).

```php
namespace App\Jobs;

use App\Sdk\CatalogClient;
use App\Sdk\UpsertProduct;
use ApiSutra\Laravel\Queue\ReleaseOnSdkThrottle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SyncProduct implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $productId) {}

    public function middleware(): array
    {
        return [new ReleaseOnSdkThrottle()];
    }

    public function handle(CatalogClient $client): void
    {
        $client->send(new UpsertProduct($this->productId))->raw();
        // A matching SDK refusal stops before any following application effects.
    }
}
```

The active scope changes local quota/cooldown waiting to an immediate control-flow refusal.
It retains real per-attempt admission, keys and quotas, including auth and retries.
Ordinary network waits, auth locks and retry backoff remain. No quota is consumed merely
for starting a job. Client configuration is not mutated; other jobs and clients outside
the scope keep their normal behavior.

Only `local_rate_limit_exceeded` and `server_cooldown_active` cause this middleware's
release. A bare HTTP 429, Redis/backend failure, expired deadline, cancellation or unknown
network failure do not. A refusal may occur **after previous HTTP**, during a retry or
auth chain. Redis access remains bounded blocking (`ioTimeoutMs` defaults to 1000), even
though quota/cooldown sleep is avoided.

The signal leaves canonical admission before an ordinary FAILED is returned and before
the application's exception factory. Ignoring raw, changing throwOnErrors, Event::fake(),
disabled telemetry and full diagnostic buffers cannot turn it into success. Factories
returning null or throwing are not invoked for the refusal.

## Async, aggregates and retries <a id="async"></a>

Always await `sendAsync()->wait()`, `sendAsync()`/`consumeAsync()` of a pool before the job
ends. Fire-and-forget is unsupported. Pools stop admitting new elements, finish started
operations within their budgets and deliver completed results to configured handlers,
then release the job once. Refusals combine by the latest deadline; elapsed draining time
is deducted. A source/handler error, cancellation or another exception that the normal
contract must throw takes precedence over release. Ordinary failed results retain their
normal result/handler contract; the middleware does not make them successful.

Delay milliseconds round **up** to queue seconds. Laravel owns attempts, retryUntil and
maxExceptions; the SDK adds no second counter. A release is not an artificial unhandled
exception. The server prohibition is not shortened to fit retryUntil; Laravel decides
whether a later attempt is still permitted. Use an actual queued connection for delayed
retries: SyncQueue can execute the middleware but does not schedule a released job.

Do not catch all exceptions and continue inside handle:

```php
try {
    $client->send($request);
} catch (\Throwable) {
    // Continuing here can perform side effects despite an admission refusal.
}
```

A swallowed signal remains latched: middleware still releases the job, but cannot undo
code that already continued. This is unsupported as a safe continuation pattern.
Without middleware, the core result-first and exception-factory rules apply.

[Rate limiting](cooldown.md) · [Observation](observability.md).
