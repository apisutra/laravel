<?php

declare(strict_types=1);

namespace Integration\First;

use ApiSutra\Attributes\Http\Get;
use ApiSutra\Attributes\Request\Query;
use ApiSutra\Core\AbstractRequest;

#[Get('/items')]
final class ItemsRequest extends AbstractRequest
{
    public function __construct(#[Query] public string $limit = '20')
    {
    }
}
