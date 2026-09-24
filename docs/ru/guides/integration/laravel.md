<!-- languages --> <a href="../../../en/guides/integration/laravel.md">English</a> · <a href="laravel.md">Русский</a> <!-- /languages -->
# Подключить готовый SDK в Laravel <a id="section-1"></a>

Установите Laravel-совместимый SDK по его инструкции через Composer. Его provider
подключает клиент, а provider ApiSutra — общие механизмы интеграции. Для обычной
установки копировать provider в приложение не требуется.

Ниже используются классы [учебного Records SDK](https://github.com/apisutra/php/blob/master/docs/ru/examples/sdk.md#section-2).
Это вымышленный API и локально устанавливаемый образец; готового пакета с таким
именем в Packagist нет. Для проверки без сети запустите его `run.php`.

Подключите интеграцию в приложение Laravel 13 (PHP 8.4+):

```bash
composer require apisutra/laravel:^0.1
```

Команда устанавливает также `apisutra/php:^0.1`. Адаптер нужен для автоматического DI
клиента/запроса учебного SDK в Laravel. Без него явная сборка остаётся доступна:
см. [три окружения](https://github.com/apisutra/php/blob/master/docs/ru/examples/sdk.md#environments).

## Задать настройки <a id="section-2"></a>

После установки Records SDK его defaults доступны без публикации конфигурации.
Параметры задаются в `.env` приложения:

```dotenv
RECORDS_BASE_URL=https://records.example.test
RECORDS_TIMEOUT=15
RECORDS_TOKEN=your-token
```

В учебном SDK токен необязателен: непустая строка включает Bearer-авторизацию.
У реального SDK адрес, обязательные credentials и способы авторизации определяет
его документация. [Все параметры Records](../../reference/integrations/laravel.md#section-11).

## Получить клиент <a id="section-3"></a>

Фрагмент для приложения с настроенным транспортом и установленным SDK:

```php
use Example\Records\DemoClient;
use Example\Records\Resources\Records\Get\GetRecordResponseDto;

$client = app(DemoClient::class);
/** @var GetRecordResponseDto $record */
$record = $client->records()->get(7)->send()->dataOrFail();
```

Клиент также можно получить аргументом конструктора или метода контроллера через DI.
Его настройки берутся из binding; отдельно созданная переменная `$config` их не меняет.
[Результаты, статусы и ошибки](https://github.com/apisutra/php/blob/master/docs/ru/reference/results/handles.md).

## Опубликовать конфигурацию при необходимости <a id="section-4"></a>

```bash
php artisan vendor:publish --tag=records-config
```

Появится `config/apisutra/records.php`. Ключи доступны через
`config('apisutra.records.*')`; обычный повторный publish сохраняет правки файла.
После изменения настроек приложения с включённым config cache пересоберите его
командой `php artisan config:cache`.

## Проверить интеграцию <a id="section-5"></a>

Для тестов добавьте `InteractsWithApiSutra` в базовый Laravel TestCase, как показано
в [изолированных fake](../../reference/integrations/testing.md), затем подмените ответы
того же клиента, который получает приложение:

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

Клиент Records по умолчанию — singleton с постоянными настройками приложения.
Несколько аккаунтов и изменяемые credentials требуют явного выбора клиента.
Обычный DI сохраняет значения SDK-запроса; входящий HTTP payload переносит явная
`RequestFactory`. [Жизненный цикл и ограничения](../../reference/integrations/laravel.md#section-14).

При отключённом package discovery выполните
[явную регистрацию](../../reference/integrations/laravel.md#section-13).
Если вы создаёте SDK, используйте отдельный маршрут
[добавить Laravel-интеграцию в свой SDK](../sdk/laravel.md).

## Async-выполнение

Со штатным Guzzle-транспортом ядра sendAsync и pool автоматически выполняют HTTP
конкурентно; binding цикла и настройки Revolt не нужны. Явные transport и PSR-18
bindings сохраняют приоритет. Синхронный PSR-клиент поддерживает send(), но async
(включая pool) даёт configuration_error до auth/квоты/HTTP. Используйте штатный
HttpTransport::createDefault() или совместимый конкурентный адаптер.

Jobs и контроллеры должны сохранять async-промисы и вызывать wait() до возврата; sendAsync
не означает fire-and-forget. Скачивание заканчивается в sink до разрешения Promise,
поэтому ClientResponseAdapter выдаёт StreamedResponse позже, вне выполнения SDK.
Завершайте или отменяйте работу до окончания запроса/job. Таймеры SDK снимаются после
завершения; цикл и обработчик ошибок приложения сохраняются.

Синхронные hooks, cache/Redis и файловый I/O могут блокировать другие задачи. Проверки
охватывают обычный bootstrap Laravel и последовательные jobs, а не Octane, корутины
Swoole, worker-режим FrankenPHP или платформенные адаптеры цикла.

[OAuth2: пользовательские подключения и jobs](oauth2.md): grants ядра, хранение и координация приложения.

[Ленивые коллекции пагинации и внедрение фабрик DTO](../../reference/integrations/pagination.md).

Для тестов используйте [изолированный fake](../../reference/integrations/testing.md);
для диагностики — [события исполнения](../../reference/integrations/observability.md).
Повторяемые jobs могут подключить [middleware ограничения](../../reference/integrations/queue.md).
