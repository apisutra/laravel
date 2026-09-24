<?php

declare(strict_types=1);

namespace Integration\Hydration;

use ApiSutra\Contracts\Interfaces\Serialization\DtoHydratorInterface;
use ApiSutra\Serialization\Context\HydrationContext;
use Override;

final readonly class ItemHydrator implements DtoHydratorInterface
{
    public function __construct(private ItemFactory $factory) {}

    #[Override]
    public function supports(string $dtoClass): bool
    {
        return $dtoClass === Item::class;
    }

    #[Override]
    public function hydrate(array|object $data, string $dtoClass, HydrationContext $context): object
    {
        return $this->factory->create(((array) $data)['id']);
    }
}
