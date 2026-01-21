<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Console\Commands;

use Illuminate\Console\Command;
use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;

class SyncBookCommand extends Command
{
    protected $signature = 'openwebui:sync-book {bookId}';
    protected $description = 'Enqueue sync tasks for a single book.';

    public function handle(OpenWebUISyncService $sync): int
    {
        if (!$sync->isEnabled()) {
            $this->info('OpenWebUI sync is disabled.');
            return Command::SUCCESS;
        }

        $bookId = (int) $this->argument('bookId');
        $sync->enqueueSyncBook($bookId);

        $this->info('Sync tasks enqueued for book ' . $bookId . '.');

        return Command::SUCCESS;
    }
}
