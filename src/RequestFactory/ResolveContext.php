<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\RequestFactory;

use ApiSutra\Attributes\Request\Body;
use ApiSutra\Attributes\Request\File;
use ApiSutra\Attributes\Request\Header;
use ApiSutra\Attributes\Request\Path;
use ApiSutra\Attributes\Request\Query;
use ApiSutra\Enums\DataTransfer\ValueState;
use ApiSutra\Support\PathResult;
use ReflectionProperty;

readonly class ResolveContext
{
    /**
     * @param array<string, mixed> $payload Нормализованные данные запроса.
     * @param array<int, string> $placeholders Плейсхолдеры пути.
     */
    public function __construct(
        public ReflectionProperty $property,
        public array $payload,
        public array $placeholders,
        public bool $isQueryMethod,
        public string $propertyName,
        public ?Path $pathAttribute,
        public ?Query $queryAttribute,
        public ?Body $bodyAttribute,
        public ?Header $headerAttribute,
        public ?File $fileAttribute,
    ) {
    }

    /** Читает точный ключ секции, отличая отсутствие от явно переданного null. */
    public function read(string $section, string $key): PathResult
    {
        $source = $this->payload[$section] ?? [];
        if (!array_key_exists($key, $source)) {
            return new PathResult(ValueState::Missing, null);
        }

        $value = $source[$key];
        return new PathResult($value === null ? ValueState::Null : ValueState::Present, $value);
    }
}
