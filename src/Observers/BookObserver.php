<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Observers;

use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;

class BookObserver
{
    public function deleted($book): void
    {
        $sync = app(OpenWebUISyncService::class);
        if (!$sync->isEnabled()) {
            return;
        }

        $sync->enqueueTask(OpenWebUISyncService::TASK_DELETE_BOOK, [
            'book_id' => $book->id,
        ]);
    }
}
