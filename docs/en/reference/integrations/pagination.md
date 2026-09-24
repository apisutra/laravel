<!-- languages --> <a href="pagination.md">English</a> · <a href="../../../ru/reference/integrations/pagination.md">Русский</a> <!-- /languages -->
# Lazy pagination collections <a id="section-1"></a>

`ApiSutra\Laravel\Pagination::collect(Paginator $paginator): LazyCollection` wraps the
core item stream. It needs no provider registration, macro, facade, or running Laravel
Application. Constructing the collection sends no HTTP.

```php
use ApiSutra\Laravel\Pagination;

// $request is your paginated SDK request, already bound to its client.
// Items may be DTOs; the repository is supplied by your application.
Pagination::collect($request->paginate())
    ->filter(fn ($item) => $item->active)
    ->take(100)
    ->each(fn ($item) => $repository->save($item));
```

filter/map/take build a lazy chain; each() starts consuming it. Objects are passed
through without calling toArray. all(), collect() on LazyCollection, sorting and other
materializing operations can retain the complete stream. The wrapper adds no cache
or remember behavior.

A second foreach over the same LazyCollection starts a new traversal with new HTTP
requests. With a nonempty first page, take(1) needs only that page. Empty pages are
followed according to the API metadata until an item or the end is reached.
Reading all values follows the core's page/cursor/offset metadata and guards. The stream is sequential; a Paginator with
concurrency greater than one fails rather than silently reducing concurrency.

FAILED throws through the core result exception factory during consumption, even
when ClientConfig::throwOnErrors is false. PARTIAL supplies data. Earlier processed
items are not rolled back. Wrap the consuming foreach/each/all in try/catch and mark
incomplete work in the application. Creating the collection alone cannot catch a
later page failure. For per-page statuses, iterate the original Paginator instead.

The core owns [item, error and lifetime contracts](https://github.com/apisutra/php/blob/master/docs/en/reference/execution/pagination-items.md),
including positional keys, preserved DTOs, generator release and external deadlines.
The Laravel wrapper introduces no HTTP executor, cancellation or worker requirements.

## Application DTO factories <a id="hydrators"></a>

The core also supports [custom DTO hydrators](https://github.com/apisutra/php/blob/master/docs/en/reference/dto/hydrators.md).
Use HydrationConfig(hydrator: $instance) through ClientConfigFactory for a common
handler, or Returns(hydrator: UserHydrator::class) for one request. With this package's
container provider, concrete constructor dependencies are autowired. Interfaces and
special configuration require ordinary application bindings. The handler receives
HydrationContext at invocation, not from the container; http() exposes that call's
request/response. No mandatory clock, logger or new config file is introduced.
