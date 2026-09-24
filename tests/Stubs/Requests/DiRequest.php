<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Requests;

use ApiSutra\Attributes\Http\Get;
use ApiSutra\Attributes\Request\Query;
use ApiSutra\Core\AbstractRequest;

#[Get('/di')]
final class DiRequest extends AbstractRequest
{
    #[Query]
    public ?string $query = null;
}
