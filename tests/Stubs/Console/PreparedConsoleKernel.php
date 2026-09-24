<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Console;

use Illuminate\Console\Application;
use Illuminate\Foundation\Console\Kernel;

/** Приложение уже подготовлено; маршрутизация событий и исполнение настоящие. */
final class PreparedConsoleKernel extends Kernel
{
    public function console(): Application
    {
        $this->rerouteSymfonyCommandEvents();
        return $this->getArtisan();
    }
}
