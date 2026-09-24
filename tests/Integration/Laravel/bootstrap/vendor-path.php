<?php

declare(strict_types=1);

// Для отдельной проверки архива можно передать путь установленного vendor.
return getenv('APISUTRA_LARAVEL_VENDOR') ?: dirname(__DIR__, 4) . '/.integration/vendor';
