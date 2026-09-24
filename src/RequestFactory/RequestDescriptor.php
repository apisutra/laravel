<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\RequestFactory;

readonly class RequestDescriptor
{
    public function __construct(
        public string $method,
        public string $path,
    ) {
    }
}
