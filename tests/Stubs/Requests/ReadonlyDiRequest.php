<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Requests;

use ApiSutra\Attributes\Http\Get;
use ApiSutra\Attributes\Request\Query;
use ApiSutra\Core\AbstractRequest;

#[Get('/items')]
final class ReadonlyDiRequest extends AbstractRequest
{
    public function __construct(#[Query] public readonly string $limit)
    {
    }
}
