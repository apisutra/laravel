<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\RequestFactory\Resolvers;

use ApiSutra\Laravel\RequestFactory\PayloadKeys;
use ApiSutra\Laravel\RequestFactory\ResolveContext;
use ApiSutra\Laravel\RequestFactory\ResolverInterface;
use ApiSutra\Support\PathResult;

/**
 * Резолвер значений из тела запроса.
 */
final class BodyValueResolver implements ResolverInterface
{
    /**
     * Подходит всегда как последний резолвер.
     */
    public function supports(ResolveContext $context): bool
    {
        return true;
    }

    /**
     * Возвращает значение поля из тела запроса.
     */
    public function resolve(ResolveContext $context): PathResult
    {
        return $context->read(PayloadKeys::BODY, $context->propertyName);
    }
}
