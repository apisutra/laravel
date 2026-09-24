<?php

declare(strict_types=1);

namespace Integration\Hydration;

final readonly class Item
{
    private function __construct(public int $id) {}

    public static function create(int $id): self
    {
        return new self($id);
    }
}
