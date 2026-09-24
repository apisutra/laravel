<!-- languages --> <a href="../../../en/guides/sdk/laravel.md">English</a> · <a href="laravel.md">Русский</a> <!-- /languages -->
# Добавить Laravel-интеграцию в свой SDK <a id="section-1"></a>

Этот маршрут предназначен автору SDK. Пользователь готового пакета начинает с
[подключения в Laravel](../integration/laravel.md).

Laravel-маршрут требует `apisutra/laravel:^0.1` на Laravel 13. SDK только для Laravel
может объявить эту зависимость. Универсальный SDK, как Records, требует только ядро
и рекомендует адаптер через suggest: приложение устанавливает его явно. Composer suggest
не устанавливает зависимости. Сам SDK и необязательный provider находятся в одном пакете.

## Подготовить самостоятельный клиент <a id="section-2"></a>

Клиент, запросы, DTO и общая фабрика конфигурации должны работать через явный
`new Client($config, $transport)` без Laravel. Обращения к приложению и его настройкам
размещайте в `src/Laravel/` своего SDK. [Records SDK](https://github.com/apisutra/php/blob/master/docs/ru/examples/sdk.md)
содержит один комплект классов для обоих способов запуска.

## Описать пакет <a id="section-3"></a>

В Composer-манифесте SDK задайте зависимость от подходящей версии ApiSutra, PSR-4
своих классов и `extra.laravel.providers`. Полный пример —
[composer.json](https://github.com/apisutra/php/blob/master/docs/example/sdk/composer.json). В Laravel этот provider подключится
через package discovery; отдельная ручная регистрация потребителем не потребуется.

Laravel остаётся необязательным окружением SDK. Provider может наследовать
`Illuminate\Support\ServiceProvider`, но обычные классы SDK не должны требовать
его загрузки для standalone-вызова.

## Собрать клиента и зарегистрировать запросы <a id="section-4"></a>

[DemoServiceProvider](https://github.com/apisutra/php/blob/master/docs/example/sdk/src/Laravel/DemoServiceProvider.php) показывает
полную реализацию, а [LaravelClientConfigFactory](https://github.com/apisutra/php/blob/master/docs/example/sdk/src/Laravel/LaravelClientConfigFactory.php)
переводит настройки приложения в конфигурацию клиента.

Последовательность:

1. В `register()` объедините defaults и задайте ленивый binding через `singletonIf`.
2. В `boot()` подключите регистрацию итогового клиента через `callAfterResolving`.
   Она нужна и для экземпляра из пользовательского binding.
3. В общем `resolving` callback отфильтруйте запросы своего namespace. Если у запроса
   ещё нет клиента, получите и зарегистрируйте default-клиента до типизированного
   обработчика `AbstractRequest` в ApiSutra.
4. Укажите namespace явно через `RequestNamespaceProviderInterface`. Это позволяет
   `ServiceRegistrar` обойтись без сканирования классов.

Проверяйте готовый объект запроса: явно привязанный клиент должен сохраниться.
Повторный bootstrap не должен добавлять hooks и регистрации повторно.
Если адаптера нет, discovery и кеш конфигурации должны работать. Проверяйте его наличие
только при запросе автоматического DI клиента/запроса и сообщайте команду установки.
Явная сборка и уже связанные экземпляры остаются доступны.
Итоговые гарантии и приоритеты — в
[контракте регистрации](../../reference/integrations/laravel.md#section-2).

## Поставить конфигурацию <a id="section-5"></a>

[config/records.php](https://github.com/apisutra/php/blob/master/docs/example/sdk/config/records.php) содержит только значения,
пригодные для `config:cache`. Читайте `env()` в конфигурационном файле. Объекты
авторизации и зависимости собирайте в provider/фабрике при создании клиента.

Задайте один ключ `apisutra.<provider>.*` и необязательный publish в
`config/apisutra/<provider>.php`. Defaults общей фабрики SDK сохраняются;
Records передаёт `ClientConfigFactory::defaults()` в `ClientConfigFactory` адаптера,
добавляя настройки SDK и auth. Адаптер читает debug/environment своего Application;
`containerProvider` остаётся null. Явный provider — override приложения,
а не требование: см. [привязку контейнера](../../reference/integrations/laravel.md).
Не создавайте клиента во время `package:discover` и не выполняйте HTTP при bootstrap.

## Проверить установку <a id="section-6"></a>

Установите SDK в отдельное приложение через Composer path repository, объявленный
в манифесте этого приложения. После этого проверьте:

- discovery обоих providers и получение SDK-запроса до первого обращения к клиенту;
- overrides клиента и транспорта, включая заранее созданный instance;
- работу без публикации, сохранность файла при повторном publish и `config:cache`;
- два SDK и последовательные задания без переноса параметров запросов;
- production-установку того же SDK без Laravel и require-dev;
- Laravel без адаптера: discovery и config cache, явную сборку, сохранение bindings
  и понятную ошибку только при запросе автоматического DI SDK.

Исполняемые SDK-примеры держите в одном месте; установка должна проверять те же
исходники, что читает разработчик. [Подготовка выпуска](https://github.com/apisutra/php/blob/master/docs/ru/guides/sdk/release.md).

## Если SDK находится внутри приложения <a id="section-7"></a>

Разместите клиентские классы в namespace приложения, а provider — например,
в `App\Providers`. Зарегистрируйте его в `bootstrap/providers.php` и задайте
конфигурацию приложения. Это локальный способ разработки; при выделении SDK
в Composer-пакет provider и defaults переезжают в SDK вместе с его классами.

[Полный контракт Laravel](../../reference/integrations/laravel.md) описывает lifetime,
несколько клиентов, явную RequestFactory и границы интеграции.

[Artisan](../../reference/integrations/generation.md) создаёт те же ядровые заготовки, что standalone CLI.
