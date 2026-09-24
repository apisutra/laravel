<?php

declare(strict_types=1);

namespace Integration\Second;

use ApiSutra\Attributes\Http\Get;
use ApiSutra\Core\AbstractRequest;

#[Get('/items')]
final class ItemsRequest extends AbstractRequest
{
}
