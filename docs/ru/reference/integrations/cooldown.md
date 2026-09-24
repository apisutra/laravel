<!-- languages --> <a href="../../../en/reference/integrations/cooldown.md">English</a> · <a href="cooldown.md">Русский</a> <!-- /languages -->
# Необязательный межпроцессный cooldown <a id="shared-cooldown"></a>

Для общности совпадающих областей между запросами/процессами явно зарегистрируйте backend ядра.
Сначала определите отдельное phpredis-соединение `apisutra_cooldown` в config/database.php с
host, port, database и конечными connect/read timeout. В ServiceProvider::register():

```php
use ApiSutra\RateLimiting\Cooldown\Backends\PhpRedisCooldownBackend;
use Illuminate\Support\Facades\Redis;

$this->app->singleton(PhpRedisCooldownBackend::class, static fn () => new PhpRedisCooldownBackend(
    redis: Redis::connection('apisutra_cooldown')->client(),
));
```

Затем в binding SDK-клиента передайте backend через ClientConfigFactory:

```php
use ApiSutra\Laravel\ClientConfigFactory;
use ApiSutra\RateLimiting\Cooldown\Backends\PhpRedisCooldownBackend;

$config = $app->make(ClientConfigFactory::class)->make([
    'baseUrl' => 'https://api.example',
    'cooldownBackend' => $app->make(PhpRedisCooldownBackend::class),
]);
```

В конфигурации храните только сериализуемые значения; соединения/backend создавайте в bindings.
Это работает с config:cache. Каждый процесс имеет своё соединение/объект; совпадающие хранилище
и identity делают срок общим, в том числе после нового bootstrap. Сам singleton общность
процессов не обеспечивает. По умолчанию состояние локальное и Redis не требуется.

Обычная HTTP-попытка добавляет два чтения Redis (Wait) или три (Throw), перепроверки после
ожидания и одну публикацию для подходящего 429. При 1 ms на чтение добавится около 2–3 ms,
при 5 ms — около 10–15 ms. Это расчёт умножением, не замер задержек.
phpredis временно блокирует другие Fiber; ioTimeoutMs по умолчанию 1000 и ограничен остатком
бюджета исполнения. Сбой backend останавливает вызов, без неявного fallback в память.
SDK не требует запуска очереди или воркера. [Контракт Redis ядра](https://github.com/apisutra/php/blob/master/docs/ru/reference/integrations/redis.md#cooldown)
описывает области, OAuth identity, доступность, TTL и осознанный возврат к локальному состоянию.
