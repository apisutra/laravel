<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Console;

use ApiSutra\Tooling\Generation\GenerationKind;

final class MakeClient extends MakeSdkClass
{
    protected $signature = 'make:apisutra-client {name} {--namespace=} {--directory=} ';
    protected $description = 'Generate an ApiSutra client';

    protected function kind(): GenerationKind
    {
        return GenerationKind::Client;
    }
}
