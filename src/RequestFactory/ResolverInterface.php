<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\RequestFactory;

use ApiSutra\Support\PathResult;

interface ResolverInterface
{
    public function supports(ResolveContext $context): bool;

    public function resolve(ResolveContext $context): PathResult;
}
