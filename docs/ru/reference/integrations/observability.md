<!-- languages --> <a href="../../../en/reference/integrations/observability.md">English</a> · <a href="observability.md">Русский</a> <!-- /languages -->
# Наблюдение за SDK <a id="observation"></a>

Интеграция буферизует безопасные снимки завершённых исполнений и публикует
`ApiSutra\Laravel\Observability\Events\ExecutionCompleted` на границах приложения.
Ядровые trace/audit/debug и PSR-логгер приложения остаются независимыми.
Отдельный worker мониторинга, таймер или фоновый exporter не нужен.

## Включение и получение <a id="setup"></a>

При активном Telescope по умолчанию наблюдается один итог корневого исполнения;
штатный EventWatcher показывает SDK-событие, если включён и фильтры Telescope его
пропускают. Это запись события, не дублирующая HTTP-запись и не панель Pulse/Nightwatch.
Telescope необязателен. Для событий без него включите наблюдение в конфигурации приложения:

```php
// В config/apisutra.php; разделы конкретных SDK остаются рядом с integration.
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

Publish необязателен: `php artisan vendor:publish --tag=apisutra-config`. Значения
сериализуемы и совместимы с config:cache. `enabled: null` выбирает автоматический режим;
`false` выключает приём. В testing автоматика выключена даже при активном Telescope.
Выберите существующий Laravel `log_channel`, чтобы писать итоги туда; рассылки во все
каналы нет. Ошибки listener/exporter не меняют результат SDK и retry/release.

Приложение может зарегистрировать listener в `boot()` своего provider:

```php
use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use Example\ApiSutra\SdkMetricsListener;
use Illuminate\Support\Facades\Event;

Event::listen(ExecutionCompleted::class, [SdkMetricsListener::class, 'handle']);
```

Скопируйте исполняемый [listener метрик](../../../example/SdkMetricsListener.php) в
приложение, изменив namespace. Он считает исходы и длительность через настоящий API
Laravel Cache Repository, не требуя конкретного сервиса мониторинга. Приёмник метрик
для production и срок агрегации выбирает приложение. Для Pulse нужен отдельный recorder:
произвольные Laravel-события автоматически в него не попадают.

## Данные и длительности <a id="payload"></a>

`$event->snapshot->data` содержит `traceId`, `executionId`, `parentExecutionId`, `operation`,
`role`, `status`, необязательные `reason`/`stage`, `durationMs`, `attemptCount`, `httpDurationMs`,
безопасные `method`/`origin` и необязательный `clientLabel`. Reason и stage — дополняемые строки.
`status()` и `durationMs()` — короткие accessor-методы. Метка задаётся только явно через
`ClientConfig(diagnosticLabel: 'catalog')`; на credentials, кеш, квоты и cooldown она
не влияет. Корень определяется по `parentExecutionId === null`, а не специальной роли.
Обход 20 страниц обычно экспортирует один корень; pool 20 независимых запросов — 20.

HTTP-сумма учитывает **собственные** попытки исполнения, включая retry и прошедшее время
отменённой попытки. Для последовательной работы: 1800 мс всего − 120 мс собственного HTTP =
1680 мс вне собственных HTTP-попыток. Это также гидратация, hooks и вложенный auth, а не
только ожидание. Суммы конкурентных детей/страниц нельзя вычитать из времени родителя.
У родителя без собственных попыток могут быть HTTP-дети. `details: true` добавляет вложенные
снимки и до 32 записей собственных попыток (`attempt`, `durationMs`, `httpStatus`), плюс `omittedAttempts`.

Тела, DTO, содержимое stream, полная URL/query, headers и текст исключений не собираются.
Ядро выполняет redaction до приёма. Строки ограничены; корреляция Context копируется при
входе из максимум 16 настроенных ключей (ключ до 128 байт, значение до 256), маскируется и
доступна в `$event->correlation`. При доставке она не перечитывается. Laravel Context
не хранит конкурентные SDK-исполнения по Fiber; SDK сохраняет собственные trace ID.

## Доставка и пределы <a id="delivery"></a>

- HTTP: terminable middleware после SDK, до финальных storage callbacks приложения.
- Queue: границы завершения/ошибки/исключения и финальная очистка JobAttempted, включая
  повторяемые исключения и SyncQueue. Порядок Telescope соблюдается без принудительного включения записи.
- Artisan: CommandFinished. Вложенные framework-области закрывают только себя. Прямые
  вложенные вызовы команд без framework-событий остаются в области родительской команды.
- Тесты: явно включённая область принадлежит [InteractsWithApiSutra](testing.md#observation).

Доставка вызывает обычные синхронные Laravel listeners, которым разрешён I/O. Worker
занят доставкой; нулевая добавочная задержка не обещается. Сам приём снимков не выполняет
I/O и не приостанавливает Fiber. Терминал отмены не означает завершение cleanup.
`flush()` возвращает false при активной SDK-работе, не запускает loop и не ждёт брошенные
операции. Автоматическое закрытие отбрасывает недоставленную диагностику; следующий
request/job/test начинает новую область. Полная поддержка lifecycle Octane/Swoole не заявлена.

Одновременно действуют предел количества снимков и байтов JSON-представления. Предел
256 КиБ не означает heap PHP: 200 типичных снимков заняли около 239 КБ heap, типичный
payload — 381 байт. Подробные снимки больше и раньше достигают байтового предела.
Окно экспорта возобновляется при следующем обращении после 60 секунд, без таймеров.
Повторный flush не обходит 200 экспортов за окно. `TelemetryDropped` содержит count,
firstAt/lastAt и конечную разбивку исходов, максимум одна сводка за окно. Отброшенные
снимки не хранятся; новые пропуски накапливают только счётчики до доступной отправки сводки.
Это влияет лишь на диагностику, никогда на результаты SDK или элементы импорта.

Долгая команда или бесконечный импорт вызывает flush **между завершёнными порциями**:

```php
use ApiSutra\Laravel\Observability\ObservationManager;

$observations = app(ObservationManager::class);
// Сначала завершите/дождитесь SDK-операций этой порции.
$delivered = $observations->flush();
```

Без таких границ конечный буфер выборочно принимает данные до CommandFinished.
Fatal и завершение процесса могут потерять буфер и сводки пропусков. Гарантии долговечной
доставки нет. `php artisan about` показывает состояние интеграции без создания
SDK-клиентов и обращений к провайдерам.

[Интеграция](laravel.md) · [Middleware очереди](queue.md).

## Текущее приложение <a id="container"></a>

`apisutra.integration` зарезервирован для адаптера; разделы `apisutra.<provider>`
сохраняются. Настройки адаптера доступны без publish. Наблюдатель разрешается через
ContainerProviderInterface; TestingManager и ObservationManager принадлежат приложению.
Автоматический provider выбирается при входе в исполнение из текущего Application
и сохраняется в его async-детях. Явный config provider/registry override сохраняет
приоритет. Две Application и clone со scoped binding проверены; это не обещание
полной поддержки Octane или автоматической смены singleton credentials.
