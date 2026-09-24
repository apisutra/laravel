<!-- languages --> <a href="../../README.md">English</a> · <a href="overview.md">Русский</a> <!-- /languages -->

<h1 align="center" id="section-1">ApiSutra Laravel</h1>

<p align="center">
  <a href="https://github.com/apisutra/laravel/actions/workflows/tests.yml"><img src="https://github.com/apisutra/laravel/actions/workflows/tests.yml/badge.svg?branch=master&amp;event=push" alt="CI"></a>
  <a href="../../composer.json"><img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4" alt="PHP 8.4+"></a>
  <a href="../../composer.json"><img src="https://img.shields.io/badge/Laravel-13.12%2B-64748b" alt="Laravel 13.12+"></a>
  <a href="https://packagist.org/packages/apisutra/laravel"><img src="https://img.shields.io/packagist/v/apisutra/laravel" alt="Packagist"></a>
  <a href="README.md"><img src="https://img.shields.io/badge/docs-EN%20%2F%20RU-2563eb" alt="Документация: EN / RU"></a>
  <a href="../../LICENSE"><img src="https://img.shields.io/badge/license-MIT-green" alt="Лицензия MIT"></a>
</p>

Используйте [SDK на ApiSutra](https://github.com/apisutra/php/blob/master/docs/ru/overview.md) через привычные инструменты Laravel: DI, ленивые коллекции, подмены в тестах, события, middleware очередей и Artisan.

Ядро отвечает за HTTP, DTO, авторизацию, повторы и конкурентное исполнение. Этот пакет связывает их с приложением. **Laravel 13.12+ · PHP 8.4+.**

[Использовать SDK](guides/integration/laravel.md) · [Создать SDK](guides/sdk/laravel.md) · [Справочник](reference/integrations/laravel.md)

## Установка <a id="installation"></a>

```bash
composer require apisutra/laravel
```

Composer устанавливает и ядро; package discovery регистрирует интеграцию. Стандартные привязки работают без публикации конфигурации, настройки БД, Redis или воркеров. Ваш SDK предоставляет клиент, адрес API и credentials. Явные привязки приложения сохраняют приоритет.

## Получить клиент через DI <a id="client"></a>

После регистрации и настройки совместимого с Laravel SDK получите его клиент из контейнера или внедрите в контроллер либо job. Здесь `CatalogClient` и `GetProduct` — классы вашего SDK:

```php
use App\Sdk\CatalogClient;
use App\Sdk\GetProduct;

$client = app(CatalogClient::class);
$product = $client->send(new GetProduct(42))->dataOrFail();
```

`ClientConfigFactory` берёт исходные debug/environment из приложения. Запросы тоже доступны через DI; перенос входящих HTTP-данных выполняется явно через `RequestFactory`. [Подключение и настройка →](reference/integrations/laravel.md)

## Лениво обработать страницы <a id="pagination"></a>

Для пагинируемого запроса SDK, уже связанного с клиентом, и репозитория приложения `$repository`:

```php
use ApiSutra\Laravel\Pagination;

Pagination::collect($request->paginate())
    ->filter(fn ($item) => $item->active)
    ->take(100)
    ->each(fn ($item) => $repository->save($item));
```

Это `LazyCollection` над последовательным потоком элементов ядра. DTO остаются объектами, страницы загружаются по мере необходимости, а FAILED вызывает исключение вместо незаметного обрыва списка. [Поведение коллекции →](reference/integrations/pagination.md)

## Запустить импорт из Artisan-команды <a id="bulk"></a>

Используйте зарегистрированный клиент для синхронизации каталога. Здесь `$requests` — ленивый источник запросов SDK; `$onResult` и `$onError` — обработчики приложения, которые получают результат или исключение вместе с исходным запросом:

```php
use App\Sdk\CatalogClient;

$summary = app(CatalogClient::class)
    ->pool($requests, concurrency: 5)
    ->withResponseHandler($onResult)
    ->withExceptionHandler($onError)
    ->consume();
```

Со штатным транспортом конкурентно выполняются до пяти запросов; обработчики получают результаты без накопления всей коллекции. Команда дожидается завершения и может вывести `$summary->successful` и `$summary->failed`. Это штатный [consume ядра](https://github.com/apisutra/php/blob/master/docs/ru/reference/execution/pool-consumption.md) через Laravel DI, с настроенной у клиента авторизацией и правилами исполнения.

## Проверить клиент, которым пользуется приложение <a id="testing"></a>

Один раз добавьте `ApiSutra\Laravel\Testing\InteractsWithApiSutra` в базовый Laravel TestCase. В тесте, где `/products/42` обращается к зарегистрированному `CatalogClient`:

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

Отсутствующая подмена провалит тест, даже если приложение проигнорировало результат SDK. При завершении теста исходный транспорт восстанавливается. Для конкретного экземпляра используйте `ApiSutra::for($client)`; поиск по классу требует shared/scoped-привязки. Проверки считают **HTTP-попытки транспорта**, включая retry, а не бизнес-операции. [Контракт тестирования →](reference/integrations/testing.md)

## Отложить job при ограничении запросов <a id="queue"></a>

В существующую job очереди с трейтом Laravel `Queueable` добавьте метод middleware. В `handle()` остаются обычные вызовы SDK:

```php
use ApiSutra\Laravel\Queue\ReleaseOnSdkThrottle;

public function middleware(): array
{
    return [new ReleaseOnSdkThrottle()];
}
```

При отказе локальной квоты или активного серверного cooldown middleware прерывает job и возвращает её в очередь с задержкой. **Повторяется вся job:** уже выполненные HTTP-запросы и изменения приложения не откатываются. Подключайте middleware только для действий, допускающих повтор; для отложенного запуска нужно подключение очереди с поддержкой задержки. [Поведение и ограничения →](reference/integrations/queue.md)

## Увидеть медленные операции и отказы SDK <a id="observation"></a>

Включите `apisutra.integration.observation.enabled` в конфигурации приложения либо используйте автоматическое наблюдение с активным Telescope вне тестовой среды. Зарегистрируйте слушатель в `boot()` провайдера приложения:

```php
use ApiSutra\Laravel\Observability\Events\ExecutionCompleted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(ExecutionCompleted::class, static function (ExecutionCompleted $event): void {
    Log::info('SDK operation completed', $event->snapshot->data);
});
```

Запись в логе содержит операцию, исход, длительность, число HTTP-попыток и, когда доступны, причину и этап отказа. Слушатель может передавать эти сводки и в метрики приложения. Сбор использует ограниченный буфер; события доставляются на границах HTTP, job или команды. По умолчанию наблюдаются только корневые исполнения. [Состав события, лимиты доставки и логирование без слушателя →](reference/integrations/observability.md)

## Карта возможностей <a id="capabilities"></a>

| Задача | Интеграция с Laravel |
| --- | --- |
| Подключить SDK | [Discovery, DI клиентов и запросов, настройки по умолчанию](reference/integrations/laravel.md), явные переопределения и поддержка `config:cache`. |
| Обработать HTTP-ввод и вывод | [RequestFactory и ClientResponseAdapter](reference/integrations/laravel.md): заполнение запросов, JSON, текст и потоковые файловые ответы; валидация Laravel. |
| Использовать коллекции и сервисы приложения | [Пагинация через LazyCollection и DI пользовательских гидраторов](reference/integrations/pagination.md). |
| Тестировать без API провайдера | [Изолированные подмены и проверки отправок](reference/integrations/testing.md), автоматическое обнаружение пропущенных ответов. |
| Наблюдать за операциями API | [События ExecutionCompleted](reference/integrations/observability.md), длительности и исходы, корреляция через Context, необязательная интеграция с Telescope и выбранным каналом логов. |
| Отложить job при ограничении запросов | [ReleaseOnSdkThrottle](reference/integrations/queue.md) при отказе локальной квоты или серверного cooldown. Подключается явно; повторяется **вся job**. |
| Работать с OAuth2-аккаунтами | [Grants ядра с хранением credentials в приложении](guides/integration/oauth2.md) и координацией между jobs/процессами. |
| Координировать ограничения запросов | [Необязательный Redis cooldown](reference/integrations/cooldown.md) для клиентов в общей области ограничения. |
| Создать классы и проверить настройку | [Генераторы клиентов, запросов и DTO через Artisan, `artisan about`](reference/integrations/generation.md). |

## Исполнение и жизненный цикл <a id="lifecycle"></a>

Со штатным Guzzle-транспортом async-запросы и пулы не требуют настройки цикла событий. Дождитесь результатов до выхода из контроллера или job: `sendAsync()` не ставит фоновую задачу в очередь. Middleware очереди необязателен и предназначен для повторяемых jobs в приложениях, которые уже используют очереди Laravel.

Поддерживаются обычные HTTP-запросы, Artisan и последовательные jobs очереди. Полная поддержка Octane/Swoole и автоматическое переключение арендаторов не обещаны; границы описаны в разделе [жизненного цикла клиента](reference/integrations/laravel.md#section-14).

## Дальше <a id="documentation"></a>

- [Использовать готовый SDK в Laravel](guides/integration/laravel.md).
- [Добавить Laravel в свой SDK](guides/sdk/laravel.md).
- [Открыть документацию](README.md) или [возможности ядра](https://github.com/apisutra/php/blob/master/docs/ru/overview.md#section-5).

[История изменений](changelog.md) · [Разработка](https://github.com/apisutra/laravel/blob/master/docs/ru/development/testing.md) · [Лицензия MIT](../../LICENSE).
