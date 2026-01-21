<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Console\Commands;

use Illuminate\Console\Command;
use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;

class SyncAllCommand extends Command
{
    protected $signature = 'openwebui:sync-all';
    protected $description = 'Enqueue sync tasks for all books.';

    public function handle(OpenWebUISyncService $sync): int
    {
        if (!$sync->isEnabled()) {
            $this->info('OpenWebUI sync is disabled.');
            return Command::SUCCESS;
        }

        $sync->enqueueSyncAll();
        $this->info('Sync tasks enqueued for all books.');

        return Command::SUCCESS;
    }
}
