<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs;

use ApiSutra\Attributes\Http\Get;
use ApiSutra\Attributes\Behavior\Pagination;
use ApiSutra\Pagination\AbstractPaginatedRequest;

#[Get('/items')]
#[Pagination(itemsType: PaginationItem::class)]
final class PaginationRequest extends AbstractPaginatedRequest
{
}
