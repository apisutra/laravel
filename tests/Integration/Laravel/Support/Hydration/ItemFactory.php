<?php

declare(strict_types=1);

namespace Integration\Hydration;

final class ItemFactory
{
    public function create(int $id): Item
    {
        return Item::create($id);
    }
}
