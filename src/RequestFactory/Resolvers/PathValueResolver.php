<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\RequestFactory\Resolvers;

use ApiSutra\Laravel\RequestFactory\PayloadKeys;
use ApiSutra\Laravel\RequestFactory\ResolveContext;
use ApiSutra\Laravel\RequestFactory\ResolverInterface;
use ApiSutra\Support\PathResult;

/**
 * Резолвер значений из параметров пути.
 */
final class PathValueResolver implements ResolverInterface
{
    /**
     * Проверяет, что свойство относится к параметрам пути.
     */
    public function supports(ResolveContext $context): bool
    {
        return $context->pathAttribute !== null
            || in_array($context->propertyName, $context->placeholders, true);
    }

    /**
     * Возвращает значение параметра пути.
     */
    public function resolve(ResolveContext $context): PathResult
    {
        $paramName = $context->pathAttribute->name ?? $context->propertyName;

        return $context->read(PayloadKeys::ROUTE, $paramName);
    }
}
