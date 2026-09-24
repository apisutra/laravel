<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Console;

use ApiSutra\Tooling\Generation\GenerationKind;

final class MakeRequest extends MakeSdkClass
{
    protected $signature = 'make:apisutra-request {name} {--namespace=} {--directory=} {--endpoint=} {--dto=}';
    protected $description = 'Generate an ApiSutra request';

    protected function kind(): GenerationKind
    {
        return GenerationKind::Request;
    }
}
