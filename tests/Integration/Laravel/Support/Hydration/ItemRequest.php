<?php

declare(strict_types=1);

namespace Integration\Hydration;

use ApiSutra\Attributes\Http\Get;
use ApiSutra\Attributes\Response\Returns;
use ApiSutra\Core\AbstractRequest;

#[Get('/hydration')]
#[Returns(Item::class, hydrator: ItemHydrator::class)]
final class ItemRequest extends AbstractRequest
{
}
