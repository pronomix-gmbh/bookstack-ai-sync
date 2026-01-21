<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Observers;

use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;

class ImageObserver
{
    public function created($image): void
    {
        $sync = app(OpenWebUISyncService::class);
        if (!$sync->isEnabled()) {
            return;
        }

        $sync->enqueueTask(OpenWebUISyncService::TASK_UPSERT_IMAGE, [
            'image_id' => $image->id,
        ]);
    }

    public function updated($image): void
    {
        $sync = app(OpenWebUISyncService::class);
        if (!$sync->isEnabled()) {
            return;
        }

        $sync->enqueueTask(OpenWebUISyncService::TASK_UPSERT_IMAGE, [
            'image_id' => $image->id,
        ]);
    }

    public function deleted($image): void
    {
        $sync = app(OpenWebUISyncService::class);
        if (!$sync->isEnabled()) {
            return;
        }

        $sync->enqueueTask(OpenWebUISyncService::TASK_DELETE_IMAGE, [
            'image_id' => $image->id,
        ]);
    }
}
