<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Console\Commands;

use Illuminate\Console\Command;
use Pronomix\BookStackOpenWebUISync\Services\BookStackRepository;
use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;

class SyncBookCommand extends Command
{
    protected $signature = 'openwebui:sync-book {bookId?}';
    protected $description = 'Enqueue sync tasks for a single book.';

    public function handle(OpenWebUISyncService $sync, BookStackRepository $books): int
    {
        if (!$sync->isEnabled()) {
            $this->info('OpenWebUI sync is disabled.');
            return Command::SUCCESS;
        }

        $bookId = $this->argument('bookId');

        if ($bookId === null || $bookId === '') {
            $bookList = $books->listBooks();

            if (count($bookList) === 0) {
                $this->info('No books found.');
                return Command::SUCCESS;
            }

            $choices = [];
            $choiceToId = [];

            foreach ($bookList as $book) {
                $label = (string) ($book->name ?? 'Book') . ' (#' . $book->id . ')';
                $choices[] = $label;
                $choiceToId[$label] = (int) $book->id;
            }

            $selection = $this->choice('Select a book to sync', $choices);
            $bookId = $choiceToId[$selection] ?? null;
        }

        if ($bookId === null || $bookId === '') {
            $this->error('No book selected.');
            return Command::FAILURE;
        }

        $bookId = (int) $bookId;
        $sync->enqueueSyncBook($bookId);

        $this->info('Sync tasks enqueued for book ' . $bookId . '.');

        return Command::SUCCESS;
    }
}
