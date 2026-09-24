<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Console;

use Illuminate\Foundation\Console\AboutCommand;

final class AboutRegistration
{
    // Реестр AboutCommand сам процессный; маркер не удерживает Application.
    private static bool $registered = false;

    public static function register(): void
    {
        if (!self::$registered && class_exists(AboutCommand::class)) {
            AboutCommand::add('ApiSutra', AboutInformation::class);
            self::$registered = true;
        }
    }
}
