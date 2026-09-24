<!-- languages --> <a href="../../../en/reference/integrations/pagination.md">English</a> · <a href="pagination.md">Русский</a> <!-- /languages -->
# Ленивые коллекции пагинации <a id="section-1"></a>

`ApiSutra\Laravel\Pagination::collect(Paginator $paginator): LazyCollection` оборачивает
поток элементов ядра. Регистрация provider, macro, facade и запущенный Laravel
Application не нужны. Создание коллекции не отправляет HTTP.

```php
use ApiSutra\Laravel\Pagination;

// $request — запрос пагинации вашего SDK, уже привязанный к клиенту.
// Элементы могут быть DTO; репозиторий предоставляет приложение.
Pagination::collect($request->paginate())
    ->filter(fn ($item) => $item->active)
    ->take(100)
    ->each(fn ($item) => $repository->save($item));
```

filter/map/take строят ленивую цепочку; each() запускает её потребление. Объекты
передаются без вызова toArray. all(), collect() самой LazyCollection, сортировка и
другие материализующие операции могут удерживать весь поток. Обёртка не добавляет
кеш или поведение remember.

Второй foreach той же LazyCollection начинает новый обход с новыми HTTP-запросами.
Если первая страница непустая, take(1) требует только её. Пустые страницы обходятся
согласно метаданным API до первого элемента или конца списка. Чтение всех значений
следует метаданным и guards page/cursor/offset ядра. Поток последовательный; Paginator с concurrency
больше единицы завершается ошибкой вместо скрытого уменьшения конкурентности.

FAILED бросает исключение через фабрику результата ядра при потреблении, даже если
ClientConfig::throwOnErrors равен false. PARTIAL отдаёт данные. Обработанные элементы
не откатываются. Оборачивайте потребляющий foreach/each/all в try/catch и отмечайте
незавершённую работу в приложении. Создание коллекции само по себе не позволяет
перехватить будущую ошибку страницы. Для статусов страниц обходите исходный Paginator.

Ядро определяет [контракты элементов, ошибок и времени жизни](https://github.com/apisutra/php/blob/master/docs/ru/reference/execution/pagination-items.md),
включая позиционные ключи, сохранение DTO, освобождение генератора и внешний дедлайн.
Laravel-обёртка не добавляет HTTP-исполнитель, отмену или требование запуска workers.

## Фабрики DTO приложения <a id="hydrators"></a>

Ядро также поддерживает [пользовательские гидраторы DTO](https://github.com/apisutra/php/blob/master/docs/ru/reference/dto/hydrators.md).
Для общего обработчика передайте HydrationConfig(hydrator: $instance) через
ClientConfigFactory, для одного запроса — Returns(hydrator: UserHydrator::class).
Provider контейнера этого пакета автоматически собирает конкретные зависимости
конструктора. Интерфейсы и особые настройки требуют обычных bindings приложения.
HydrationContext передаётся обработчику при вызове, а не из контейнера; http() открывает
request/response данного вызова. Обязательные часы, логгер и новый config-файл не нужны.
