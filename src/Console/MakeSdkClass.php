<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Console;

use ApiSutra\Exceptions\Configuration\ConfigurationException;
use ApiSutra\Tooling\Generation\ClassGenerator;
use ApiSutra\Tooling\Generation\GenerationKind;
use ApiSutra\Tooling\Generation\NamespaceRoot;
use Illuminate\Console\Command;

/** Artisan владеет только вводом; шаблоны и файловая политика принадлежат ядру. */
abstract class MakeSdkClass extends Command
{
    abstract protected function kind(): GenerationKind;

    public function handle(): int
    {
        try {
            $namespace = $this->option('namespace') ?? $this->laravel->getNamespace();
            $root = NamespaceRoot::resolve($this->laravel->basePath(), $namespace, $this->option('directory'));
            $path = new ClassGenerator()->generate(
                $this->kind(),
                $this->argument('name'),
                $root,
                $this->hasOption('endpoint') ? $this->option('endpoint') : null,
                $this->hasOption('dto') ? $this->option('dto') : null,
            );
            $this->components->info($path);
            return self::SUCCESS;
        } catch (ConfigurationException $exception) {
            $this->components->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
