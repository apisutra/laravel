<!-- languages --> <a href="../../../en/reference/integrations/testing.md">English</a> · <a href="testing.md">Русский</a> <!-- /languages -->
# Тестирование SDK-клиентов <a id="testing"></a>

Один раз добавьте `InteractsWithApiSutra` в базовый Laravel TestCase приложения. Trait
проверяет нарушения строгого fake и восстанавливает исходный транспорт каждого клиента
перед уничтожением приложения, в том числе после ошибки теста. Pest использует тот же TestCase.

```php
namespace Tests;

use ApiSutra\Laravel\Testing\InteractsWithApiSutra;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;

abstract class TestCase extends LaravelTestCase
{
    use InteractsWithApiSutra;
}
```

В тесте `CatalogClient` и `GetProduct` — классы вашего SDK:

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

`for()` требует зарегистрированный lifecycle теста и отказывает с инструкцией, если
trait отсутствует. Он разрешает существующий shared/scoped binding приложения, включая alias.
Для transient/contextual binding, нескольких аккаунтов и уже созданного клиента передавайте
**фактический экземпляр**: `ApiSutra::for($tenantClient)`. Произвольный `new` не перехватывается.
Повторный `for()` одного объекта возвращает одну сессию; разные экземпляры имеют независимые
ответы и истории. Сессия делегирует ядровому MockTransport и принимает его шаблоны ответов,
callbacks и последовательности.

Каждый `fake()` начинает новый набор ответов и историю. Уже замеченные пропущенные mock
остаются нарушениями теста после повторного fake. Неизвестный запрос проваливает teardown,
даже если приложение игнорирует `raw()`, выключает `throwOnErrors`, заменяет фабрику
исключений либо ловит ошибку. Явные mock 404/500 — обычные ответы, а не пропущенные mock.
Ядровой `$client->fake()` имеет собственные defaults.

`assertSent(times:)` считает **транспортные попытки**, а не бизнес-операции. Один send
с retry 503 → 200 считается дважды; OAuth-запрос токена учитывается под своим классом;
cache hit не добавляет отправку. Для такого retry-сценария задайте последовательность
ответов и проверяйте `times: 2`. `assertNothingSent()` проверяет транспорт сессии.

Замена транспорта сохраняет auth, квоты, cooldown и кеш ответов. Переключение
fake/record/playback во время исполнения запрещено, включая приостановленный async и
ленивую пагинацию. Завершите операции или отмените их с cleanup до teardown; teardown
не запускает event loop ради брошенной работы. Record/playback остаются явными API клиента.
Прямой fake/record/playback не позволяет обойти открытую сессию.

## Наблюдение в тестах <a id="observation"></a>

В `testing` автоматическое наблюдение выключено, даже с Telescope. Для проверки
наблюдаемости включите `apisutra.integration.observation.enabled` до настройки trait
(например в `createApplication()`). Trait открывает и закрывает область наблюдения
даже без fake. После завершённых SDK-вызовов выполните:

```php
use ApiSutra\Laravel\Observability\ObservationManager;

$this->assertTrue($this->app->make(ObservationManager::class)->flush());
```

Затем проверьте `ExecutionCompleted` через свой listener или Event fake. Flush не
исполняет незавершённые задачи. Проверка fake и очистка наблюдения независимы: ошибка
одной не отменяет вторую. Чистый unit-тест без Laravel TestCase сам открывает `begin('test')`
и закрывает `end('test')` в `finally`; запасного буфера на весь процесс нет.

[Интеграция](laravel.md) · [Наблюдение](observability.md).
