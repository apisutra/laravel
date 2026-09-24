<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('проваливает настоящий Laravel TestCase при проигнорированном FAILED', function (): void {
    $process = new Process([PHP_BINARY, 'vendor/bin/pest', 'tests/Fixtures/Testing/UnmockedLifecycleTest.php', '--colors=never'], dirname(__DIR__, 3));
    $process->run();
    $output = $process->getOutput() . $process->getErrorOutput();
    expect($process->getExitCode())->toBe(1)->and($output)->toContain('No mock response was configured');
});

it('очищает явно включённое наблюдение после ошибки теста без fake', function (): void {
    $process = new Process([PHP_BINARY, 'vendor/bin/pest', 'tests/Fixtures/Testing/ObservationLifecycleTest.php', '--colors=never'], dirname(__DIR__, 3));
    $process->run();
    $output = $process->getOutput() . $process->getErrorOutput();
    expect($process->getExitCode())->toBe(2)->and($output)->toContain('intentional observation lifecycle failure')->toContain('1 failed, 1 passed');
});
