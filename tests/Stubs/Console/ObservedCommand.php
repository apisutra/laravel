<?php

declare(strict_types=1);

namespace ApiSutra\Laravel\Tests\Stubs\Console;

use ApiSutra\Laravel\Tests\Stubs\TestClient;
use ApiSutra\Laravel\Tests\Stubs\Requests\DiRequest;
use Illuminate\Console\Command;

final class ObservedCommand extends Command
{
    protected $signature = 'sdk:observed {--nested}';
    public function handle(TestClient $client): int
    {
        $client->send(new DiRequest());
        if ($this->option('nested')) {
            $this->getApplication()->call('sdk:observed');
            $client->send(new DiRequest());
        }
        return self::SUCCESS;
    }
}
