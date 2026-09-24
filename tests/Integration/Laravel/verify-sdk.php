<?php

declare(strict_types=1);

use Integration\RecordsSdkProviderChecks;

$vendor = require __DIR__ . '/bootstrap/vendor-path.php';
require $vendor . '/autoload.php';
RecordsSdkProviderChecks::run();
