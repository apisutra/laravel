<!-- languages --> <a href="../../../en/reference/integrations/queue.md">English</a> · <a href="queue.md">Русский</a> <!-- /languages -->
# Возврат job в очередь при ограничении SDK <a id="queue"></a>

`ReleaseOnSdkThrottle` — явный выбор повтора **всей job** при отказе допуска HTTP в SDK.
Подключайте его только к задачам, у которых можно безопасно повторить предыдущие HTTP
и прикладные эффекты. Middleware не откатывает их и не доказывает идемпотентность.
Ядру SDK очередь и worker не нужны; интеграция предназначена приложениям с очередями Laravel.

Job должна иметь публичный `release()`, обычно через Laravel trait `Queueable` или
`InteractsWithQueue`. Иначе middleware бросает ConfigurationException до вызова handle,
с сообщением на языке приложения (`apisutra::queue.release_required`).

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
        // Подходящий отказ SDK остановит работу до следующих прикладных эффектов.
    }
}
```

Активная область заменяет ожидание локальной квоты/cooldown управляющим отказом.
Реальный допуск каждой попытки, ключи и квоты сохраняются, включая auth и retry.
Обычное ожидание сети, auth lock и retry backoff остаётся. Квота не списывается за сам
запуск job. Конфигурация клиента не меняется; другие jobs и клиенты вне области
сохраняют обычное поведение.

Только `local_rate_limit_exceeded` и `server_cooldown_active` дают release этого middleware.
Голый HTTP 429, ошибка Redis/backend, истёкший deadline, отмена и неизвестный сетевой
сбой — нет. Отказ возможен **после предыдущего HTTP**, на retry или в цепочке auth.
Redis остаётся ограниченно блокирующим I/O (`ioTimeoutMs` по умолчанию 1000), хотя
сон в ожидании квоты/cooldown исключён.

Сигнал выходит из канонического допуска до возврата обычного FAILED и до фабрики
исключений приложения. Игнорирование raw, смена throwOnErrors, Event::fake(), выключенная
телеметрия и полный диагностический буфер не превращают его в успех. Фабрики,
возвращающие null или бросающие исключение, при таком отказе не вызываются.

## Async, агрегаты и повторы <a id="async"></a>

До конца job обязательно дождитесь `sendAsync()->wait()`, `sendAsync()`/`consumeAsync()`
пула. Fire-and-forget не поддерживается. Пулы прекращают новые запуски, завершают начатые
операции в их бюджетах и доставляют результаты настроенным обработчикам, затем один раз
возвращают job в очередь. Отказы объединяются по самому позднему сроку; время завершения
начатых операций вычитается. Ошибка источника/обработчика, отмена и другое исключение,
которое обычный контракт обязан бросить, имеют приоритет над release. Обычные FAILED
сохраняют правила результата/обработчика; middleware не объявляет их успешными.

Задержка округляется из миллисекунд в секунды **вверх**. Attempts, retryUntil и
maxExceptions принадлежат Laravel; второго счётчика SDK нет. Release не считается
искусственным необработанным исключением. Серверный запрет не сокращается ради retryUntil;
допустимость следующей попытки определяет Laravel. Для отложенного повтора нужна настоящая
очередь: SyncQueue исполняет middleware, но не планирует released job заново.

Не ловите все исключения с продолжением внутри handle:

```php
try {
    $client->send($request);
} catch (\Throwable) {
    // Продолжение может выполнить побочные эффекты вопреки отказу допуска.
}
```

Проглоченный сигнал остаётся зафиксированным: middleware всё равно делает release,
но не откатывает уже продолжившийся код. Безопасное продолжение такого вида не поддерживается.
Без middleware действуют ядровые result-first и правила фабрики исключений.

[Ограничение запросов](cooldown.md) · [Наблюдение](observability.md).
