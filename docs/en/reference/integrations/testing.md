<!-- languages --> <a href="testing.md">English</a> · <a href="../../../ru/reference/integrations/testing.md">Русский</a> <!-- /languages -->
# Testing SDK clients <a id="testing"></a>

Add `InteractsWithApiSutra` once to your application's base Laravel TestCase. It checks
strict fake violations and restores each client's original transport before the application
is destroyed, including after a failed test. Pest uses this same TestCase.

```php
namespace Tests;

use ApiSutra\Laravel\Testing\InteractsWithApiSutra;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;

abstract class TestCase extends LaravelTestCase
{
    use InteractsWithApiSutra;
}
```

In a test, `CatalogClient` and `GetProduct` are your SDK's classes:

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

`for()` requires the registered test lifecycle and refuses with instructions if the trait
is missing. It resolves the application's existing shared/scoped binding, including aliases.
For a transient/contextual binding, multiple accounts or an already created client, pass
the **actual instance**: `ApiSutra::for($tenantClient)`. Arbitrary `new` calls are not intercepted.
Repeated `for()` of one instance returns one session; different instances have independent
responses and histories. The session delegates to the core MockTransport and accepts its
response patterns, callbacks and sequences.

Each `fake()` starts a new response map and history. Previously observed missing mocks
remain test violations even after another fake. An unknown request fails teardown even
when the application ignores `raw()`, disables `throwOnErrors`, replaces its exception
factory, or catches the resulting error. Explicit mock 404/500 responses are normal
responses, not missing mocks. The core `$client->fake()` has its own defaults.

`assertSent(times:)` counts **transport attempts**, not business operations. A single send
with a retry from 503 to 200 counts twice; an OAuth token request counts under its own
request class; a cache hit adds no send. Use a response sequence and assert `times: 2`
when testing that retry scenario. `assertNothingSent()` checks the session's transport.

Replacing a transport preserves auth, quota, cooldown and response-cache state. Switching
fake/record/playback while execution is active is rejected, including suspended async and
lazy pagination. Finish or cancel and clean up your operations before teardown; teardown
never runs an event loop for abandoned work. Record/playback remain explicit client APIs.
Direct fake/record/playback cannot bypass an open session.

## Observation in tests <a id="observation"></a>

Automatic observation is disabled in `testing`, even with Telescope. For a test of
observability, enable `apisutra.integration.observation.enabled` before trait setup
(for example in `createApplication()`). The trait opens and closes its observation area
even if no fake is used. After completed SDK work, call:

```php
use ApiSutra\Laravel\Observability\ObservationManager;

$this->assertTrue($this->app->make(ObservationManager::class)->flush());
```

Then inspect `ExecutionCompleted` through your event listener or Event fake. Flush does not
run unfinished tasks. Fake verification and observation cleanup are independent: failure
of one still runs the other. Pure unit tests without Laravel TestCase must open `begin('test')`
and close `end('test')` in `finally`; there is no process-wide fallback buffer.

[Integration](laravel.md) · [Observation](observability.md).
