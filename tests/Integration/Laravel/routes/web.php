<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Integration\ItemsController;
use Integration\DownloadController;

Route::get('/probe', ItemsController::class);

Route::get('/async-file', DownloadController::class);
