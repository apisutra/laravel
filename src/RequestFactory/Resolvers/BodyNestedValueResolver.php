<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\RequestFactory\Resolvers;

use ApiSutra\Laravel\RequestFactory\PayloadKeys;
use ApiSutra\Laravel\RequestFactory\ResolveContext;
use ApiSutra\Laravel\RequestFactory\ResolverInterface;
use ApiSutra\Support\ArrayPath;
use ApiSutra\Support\PathResult;

/**
 * Резолвер значений из вложенного тела запроса.
 */
final class BodyNestedValueResolver implements ResolverInterface
{
    /**
     * Проверяет, что для свойства задан nested путь.
     */
    public function supports(ResolveContext $context): bool
    {
        return $context->bodyAttribute !== null && $context->bodyAttribute->nested !== null;
    }

    /**
     * Возвращает значение по вложенному пути в теле.
     */
    public function resolve(ResolveContext $context): PathResult
    {
        $path = $context->bodyAttribute->nested;
        if ($path === '') {
            return $context->read(PayloadKeys::BODY, $context->propertyName);
        }

        return ArrayPath::getByPathWithStatus($context->payload[PayloadKeys::BODY] ?? [], $path);
    }
}
