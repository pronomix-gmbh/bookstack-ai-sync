<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Console\Commands;

use Illuminate\Console\Command;
use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;

class RebuildBookCommand extends Command
{
    protected $signature = 'openwebui:rebuild-book {bookId}';
    protected $description = 'Rebuild sync data for a single book.';

    public function handle(OpenWebUISyncService $sync): int
    {
        if (!$sync->isEnabled()) {
            $this->info('OpenWebUI sync is disabled.');
            return Command::SUCCESS;
        }

        $bookId = (int) $this->argument('bookId');
        $sync->enqueueTask(OpenWebUISyncService::TASK_REBUILD_BOOK, ['book_id' => $bookId]);

        $this->info('Rebuild task enqueued for book ' . $bookId . '.');

        return Command::SUCCESS;
    }
}
