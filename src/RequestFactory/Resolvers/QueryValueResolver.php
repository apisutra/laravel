<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\RequestFactory\Resolvers;

use ApiSutra\Laravel\RequestFactory\PayloadKeys;
use ApiSutra\Laravel\RequestFactory\ResolveContext;
use ApiSutra\Laravel\RequestFactory\ResolverInterface;
use ApiSutra\Support\PathResult;

/**
 * Резолвер значений из query параметров.
 */
final class QueryValueResolver implements ResolverInterface
{
    /**
     * Проверяет, что свойство читается из query.
     */
    public function supports(ResolveContext $context): bool
    {
        if ($context->bodyAttribute !== null) {
            return false;
        }

        return $context->queryAttribute !== null || $context->isQueryMethod;
    }

    /**
     * Возвращает значение query параметра.
     */
    public function resolve(ResolveContext $context): PathResult
    {
        $paramName = $context->queryAttribute->name ?? $context->propertyName;

        return $context->read(PayloadKeys::QUERY, $paramName);
    }
}
