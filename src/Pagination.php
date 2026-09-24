<?php

declare(strict_types=1);

namespace ApiSutra\Laravel;

use ApiSutra\Pagination\Paginator;
use Illuminate\Support\LazyCollection;

final class Pagination
{
    /** @return LazyCollection<int, mixed> */
    public static function collect(Paginator $paginator): LazyCollection
    {
        return LazyCollection::make(static fn () => $paginator->items());
    }
}
