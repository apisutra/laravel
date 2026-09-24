<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\RequestFactory\Resolvers;

use ApiSutra\Laravel\RequestFactory\PayloadKeys;
use ApiSutra\Laravel\RequestFactory\ResolveContext;
use ApiSutra\Laravel\RequestFactory\ResolverInterface;
use ApiSutra\Enums\DataTransfer\ValueState;
use ApiSutra\Support\PathResult;

/**
 * Резолвер значений из заголовков запроса.
 */
final class HeaderValueResolver implements ResolverInterface
{
    /**
     * Проверяет наличие атрибута заголовка.
     */
    public function supports(ResolveContext $context): bool
    {
        return $context->headerAttribute !== null;
    }

    /**
     * Возвращает значение заголовка для свойства.
     */
    public function resolve(ResolveContext $context): PathResult
    {
        $headerName = $context->headerAttribute->name;

        return $this->readFromHeaders($context->payload[PayloadKeys::HEADERS] ?? [], $headerName);
    }

    /**
     * Ищет заголовок без учёта регистра и берёт первое значение.
     *
     * @param array<string, mixed> $headers Заголовки запроса.
     */
    private function readFromHeaders(array $headers, string $name): PathResult
    {
        foreach ($headers as $key => $values) {
            if (strcasecmp($key, $name) === 0) {
                $first = is_array($values) ? ($values[0] ?? null) : $values;
                return new PathResult(
                    $first === null ? ValueState::Null : ValueState::Present,
                    $first !== null ? (string) $first : null,
                );
            }
        }

        return new PathResult(ValueState::Missing, null);
    }
}
