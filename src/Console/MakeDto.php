<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Console;

use ApiSutra\Tooling\Generation\GenerationKind;

final class MakeDto extends MakeSdkClass
{
    protected $signature = 'make:apisutra-dto {name} {--namespace=} {--directory=} ';
    protected $description = 'Generate an ApiSutra dto';

    protected function kind(): GenerationKind
    {
        return GenerationKind::Dto;
    }
}
