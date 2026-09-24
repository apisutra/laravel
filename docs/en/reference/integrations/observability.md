<!-- languages --> <a href="observability.md">English</a> · <a href="../../../ru/reference/integrations/observability.md">Русский</a> <!-- /languages -->
# SDK observation <a id="observation"></a>

The integration buffers safe completed-execution snapshots and publishes
`ApiSutra\Laravel\Observability\Events\ExecutionCompleted` at application boundaries.
The core trace/audit/debug and the application's PSR logger remain independent.
No monitoring worker, timer or background exporter is required.

## Enable and receive <a id="setup"></a>

With active Telescope, observation defaults to one summary per root execution; its
standard EventWatcher displays the SDK event when enabled and accepted by Telescope's
filters. This is an event entry, not a duplicate HTTP entry or a Pulse/Nightwatch panel.
Telescope is optional. To publish events without it, enable observation in application
configuration:

```php
// In config/apisutra.php; keep any SDK-specific sections alongside integration.
return ['integration' => ['observation' => [
    'enabled' => true,
    'details' => false,
    'max_snapshots' => 256,
    'max_bytes' => 262144,
    'per_window' => 200,
    'window_seconds' => 60,
    'correlation_keys' => ['request_id', 'trace_id'],
    'log_channel' => null,
]]];
```

Publishing is optional: `php artisan vendor:publish --tag=apisutra-config`. Values are
serializable and compatible with config:cache. `enabled: null` selects automatic mode;
`false` disables capture. Testing disables automatic mode even when Telescope is active.
Select an existing Laravel `log_channel` to log summaries there; nothing is broadcast
into every log channel. Listener/export failures do not alter SDK results or retry/release.

An application can register its own listener in its provider's `boot()`:

```php
use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use Example\ApiSutra\SdkMetricsListener;
use Illuminate\Support\Facades\Event;

Event::listen(ExecutionCompleted::class, [SdkMetricsListener::class, 'handle']);
```

Copy the executable [metrics listener](../../../example/SdkMetricsListener.php) into your
application with its namespace adjusted. It uses Laravel's actual Cache Repository API
to count outcomes and duration, rather than requiring a specific monitoring service.
Choose the production metrics receiver and aggregation lifetime in your application.
Pulse requires its own recorder; arbitrary Laravel events do not automatically feed it.

## Payload and timing <a id="payload"></a>

`$event->snapshot->data` contains `traceId`, `executionId`, `parentExecutionId`, `operation`,
`role`, `status`, optional `reason`/`stage`, `durationMs`, `attemptCount`, `httpDurationMs`,
safe `method`/`origin`, and optional `clientLabel`. Reason and stage are extensible strings.
`status()` and `durationMs()` offer short accessors. Label comes only from explicit
`ClientConfig(diagnosticLabel: 'catalog')`; it does not affect credentials, cache, quotas
or cooldown. Roots have `parentExecutionId === null`, not necessarily a special role.
A 20-page traversal normally exports one root; a pool of 20 independent requests exports 20.

The HTTP sum counts the execution's **own** attempts, including retries and elapsed time
of cancellation. For sequential work: 1800 ms total − 120 ms own HTTP = 1680 ms outside
own HTTP attempts. This includes hydration, hooks and nested auth, not just waiting.
Do not subtract concurrent child/page sums from parent wall time. A parent with no own
attempts may still have HTTP children. `details: true` adds nested snapshots and at most
32 own attempt entries (`attempt`, `durationMs`, `httpStatus`) plus `omittedAttempts`.

No body, DTO, stream content, full URL/query, headers or exception text is captured.
Core redaction runs before capture. String fields are bounded; Context correlation is
copied on entry from at most 16 configured keys (keys up to 128 bytes, values 256),
redacted and available in `$event->correlation`. It is not reread at delivery. Laravel
Context is not used as Fiber-local SDK execution storage; SDK trace IDs remain its own.

## Delivery and limits <a id="delivery"></a>

- HTTP: terminable middleware after SDK work, before application's final storage callbacks.
- Queue: completion/failure/exception boundaries and final JobAttempted cleanup, including
  retryable exceptions and SyncQueue. Telescope storage order is honored without forcing recording.
- Artisan: CommandFinished. Nested framework areas close only themselves. Direct nested
  command calls without framework events stay inside the parent command's area.
- Tests: an explicitly enabled area belongs to [InteractsWithApiSutra](testing.md#observation).

Delivery invokes normal synchronous Laravel listeners, which may perform I/O. The worker
remains busy during delivery; zero latency cost is not promised. Capture itself performs
no I/O or Fiber suspension. A terminal cancellation does not mean cleanup has completed.
`flush()` returns false while SDK work remains active, does not run the loop and does not
wait for abandoned work. Automatic closure discards undeliverable diagnostics; the next
request/job/test starts a fresh area. Full Octane/Swoole lifecycle support is not claimed.

Both the snapshot count and JSON-representation byte limit apply. The 256 KiB limit is
not a PHP heap measurement: 200 typical snapshots measured about 239 KB heap and a typical
payload 381 bytes. Detailed payloads are larger and reach the byte limit earlier.
The export window renews on the next operation after 60 seconds, without timers.
Repeated flush cannot bypass 200 exports per window. `TelemetryDropped` reports count,
firstAt/lastAt and a bounded outcome breakdown, at most once per window. Dropped snapshots
are not retained; later drops accumulate only counters until a summary can be sent.
This affects diagnostics only, never SDK results or imported items.

A long command or endless import should flush **between finished chunks**:

```php
use ApiSutra\Laravel\Observability\ObservationManager;

$observations = app(ObservationManager::class);
// Finish/await this chunk's SDK operations first.
$delivered = $observations->flush();
```

Without such boundaries, the finite buffer samples the long operation until CommandFinished.
Fatal errors or process termination may lose buffered events and drop summaries. There is
no durable delivery guarantee. `php artisan about` shows integration status without
constructing SDK clients or contacting providers.

[Integration](laravel.md) · [Queue middleware](queue.md).

## Current application <a id="container"></a>

`apisutra.integration` is reserved for the adapter; `apisutra.<provider>` sections are
preserved. Adapter defaults work without publish. The observer resolves through
ContainerProviderInterface; TestingManager and ObservationManager belong to the application.
The automatic provider is selected from the current Application at execution entry
and retained by async children. Explicit config provider/registry override keeps its
priority. Two Applications and a clone with scoped bindings are covered; this does not
promise full Octane support or automatic replacement of singleton credentials.
