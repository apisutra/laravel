<!-- languages --> <a href="../../../en/reference/integrations/generation.md">English</a> · <a href="generation.md">Русский</a> <!-- /languages -->
# Заготовки SDK через Artisan <a id="generation"></a>

```bash
php artisan make:apisutra-client 'Sdk\CatalogClient'
php artisan make:apisutra-dto 'Sdk\Product'
php artisan make:apisutra-request 'Sdk\GetProduct' --endpoint=/products/1 --dto='App\Sdk\Product'
```

Команды используют ядровой [генератор](https://github.com/apisutra/php/blob/master/docs/ru/reference/client/generation.md),
а не отдельные Laravel-шаблоны. По умолчанию Laravel выбирает namespace приложения
и соответствующий PSR-4 каталог, даже если в карте также есть factories или seeders.
App — пример, не требование. Для внешнего SDK или неоднозначного соответствия:

```bash
php artisan make:apisutra-client Client --namespace='Acme\Catalog' --directory=packages/catalog/src
```

Результат — обычные ядровые клиент/запрос/DTO, работающие и standalone. Запрос использует
GET; реальный метод, путь и поля добавляет автор. Неуказанный путь — `/replace-me`,
неуказанный DTO не объявляется. Provider, tenant manager, auth-схема и service binding
не придумываются. Существующие файлы и некорректные/выходящие за каталог пути отклоняются
без force overwrite. Файловая политика и шаблоны совпадают со standalone CLI.
Publish конфигурации не нужен. `php artisan about` также показывает наличие binding
транспорта и состояние наблюдения, не создавая клиентов и не обращаясь к API.

## DI приложения <a id="di"></a>

Используйте обычные Laravel bindings и штатные contextual attributes для прикладных
сервисов. Например, `#[Config('services.catalog.account')] string $account` в конструкторе
сервиса приложения читает настроенное значение. `#[Give(CatalogClient::class)]` выбирает
реализацию параметра-интерфейса. Это атрибуты Laravel; собственного реестра tenancy
ApiSutra не добавляет. Клиенты отдельных аккаунтов создавайте явно в bindings, а в тестах
передавайте фактический экземпляр в `ApiSutra::for($client)`. Диагностическая метка
именует телеметрию, никогда не выбирает аккаунт и не разделяет ключи квот.

[Интеграция](laravel.md) · [Тестирование](testing.md).
