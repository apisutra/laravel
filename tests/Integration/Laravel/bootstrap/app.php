<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;

foreach (['bootstrap/cache', 'storage/framework/views', 'storage/framework/cache', 'storage/logs'] as $directory) {
    $path = dirname(__DIR__) . '/' . $directory;
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__ . '/../routes/web.php')
    ->withCommands()
    ->withMiddleware()
    ->withExceptions()
    ->create();
$app->make(PackageManifest::class)->vendorPath = require __DIR__ . '/vendor-path.php';
return $app;
