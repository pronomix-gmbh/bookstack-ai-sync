<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Console\Commands;

use Illuminate\Console\Command;
use Pronomix\BookStackOpenWebUISync\Services\OpenWebUIClient;
use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;

class TestConnectionCommand extends Command
{
    protected $signature = 'openwebui:test-connection';
    protected $description = 'Test OpenWebUI API connectivity.';

    public function handle(OpenWebUIClient $client, OpenWebUISyncService $sync): int
    {
        if (!$sync->isEnabled()) {
            $this->info('OpenWebUI sync is disabled.');
            return Command::SUCCESS;
        }

        try {
            $knowledge = $client->listKnowledge();
        } catch (\Throwable $e) {
            $this->error('Connection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $count = is_array($knowledge) ? count($knowledge) : 0;
        $this->info('Connection OK. Knowledge entries: ' . $count);

        return Command::SUCCESS;
    }
}
