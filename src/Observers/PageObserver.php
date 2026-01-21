<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Observers;

use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;

class PageObserver
{
    public function saved($page): void
    {
        $sync = app(OpenWebUISyncService::class);
        if (!$sync->isEnabled()) {
            return;
        }

        $sync->enqueueTask(OpenWebUISyncService::TASK_UPSERT_PAGE, [
            'page_id' => $page->id,
            'book_id' => $page->book_id ?? null,
        ]);
    }

    public function deleted($page): void
    {
        $sync = app(OpenWebUISyncService::class);
        if (!$sync->isEnabled()) {
            return;
        }

        $sync->enqueueTask(OpenWebUISyncService::TASK_DELETE_PAGE, [
            'page_id' => $page->id,
            'book_id' => $page->book_id ?? null,
        ]);
    }
}
