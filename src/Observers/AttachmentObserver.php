<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Observers;

use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;

class AttachmentObserver
{
    public function created($attachment): void
    {
        $sync = app(OpenWebUISyncService::class);
        if (!$sync->isEnabled()) {
            return;
        }

        $sync->enqueueTask(OpenWebUISyncService::TASK_UPSERT_ATTACHMENT, [
            'attachment_id' => $attachment->id,
            'book_id' => $attachment->book_id ?? null,
        ]);
    }

    public function updated($attachment): void
    {
        $sync = app(OpenWebUISyncService::class);
        if (!$sync->isEnabled()) {
            return;
        }

        $sync->enqueueTask(OpenWebUISyncService::TASK_UPSERT_ATTACHMENT, [
            'attachment_id' => $attachment->id,
            'book_id' => $attachment->book_id ?? null,
        ]);
    }

    public function deleted($attachment): void
    {
        $sync = app(OpenWebUISyncService::class);
        if (!$sync->isEnabled()) {
            return;
        }

        $sync->enqueueTask(OpenWebUISyncService::TASK_DELETE_ATTACHMENT, [
            'attachment_id' => $attachment->id,
            'book_id' => $attachment->book_id ?? null,
        ]);
    }
}
