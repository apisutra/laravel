<!-- languages --> <a href="cooldown.md">English</a> · <a href="../../../ru/reference/integrations/cooldown.md">Русский</a> <!-- /languages -->
# Optional cross-process cooldown <a id="shared-cooldown"></a>

For matching cooldown scopes across requests/processes, explicitly register the core backend.
First define a dedicated phpredis connection `apisutra_cooldown` in config/database.php with
host, port, database and finite connect/read timeouts. In your ServiceProvider::register():

```php
use ApiSutra\RateLimiting\Cooldown\Backends\PhpRedisCooldownBackend;
use Illuminate\Support\Facades\Redis;

$this->app->singleton(PhpRedisCooldownBackend::class, static fn () => new PhpRedisCooldownBackend(
    redis: Redis::connection('apisutra_cooldown')->client(),
));
```

Then, in your SDK client binding, pass the resolved backend through ClientConfigFactory:

```php
use ApiSutra\Laravel\ClientConfigFactory;
use ApiSutra\RateLimiting\Cooldown\Backends\PhpRedisCooldownBackend;

$config = $app->make(ClientConfigFactory::class)->make([
    'baseUrl' => 'https://api.example',
    'cooldownBackend' => $app->make(PhpRedisCooldownBackend::class),
]);
```

Keep only serializable settings in configuration; create connections/backends in bindings.
This supports config:cache. Each process has its own connection/object; matching storage and
identity make the deadline common, including after a new application bootstrap. A singleton
alone does not provide cross-process sharing. Defaults remain local and require no Redis.

Each ordinary HTTP attempt adds two Redis reads (Wait) or three (Throw), plus rechecks after
waiting and one publication per qualifying 429. At 1 ms per read this is about 2–3 ms added;
at 5 ms, about 10–15 ms. These are estimates by multiplication, not latency measurements.
phpredis temporarily blocks other Fibers; ioTimeoutMs defaults to 1000 and is bounded by
the execution budget. Backend failures stop the call, with no implicit local fallback.
No queue or worker must be started by the SDK. See the [core Redis contract](https://github.com/apisutra/php/blob/master/docs/en/reference/integrations/redis.md#cooldown)
for scopes, OAuth identity, availability, TTL and an intentional return to local state.
