<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Observers;

use Pronomix\BookStackOpenWebUISync\Services\BookStackRepository;
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

        $this->enqueueAttachments($page, OpenWebUISyncService::TASK_UPSERT_ATTACHMENT);
        $this->enqueueImages($page, OpenWebUISyncService::TASK_UPSERT_IMAGE);
    }

    public function deleting($page): void
    {
        $sync = app(OpenWebUISyncService::class);
        if (!$sync->isEnabled()) {
            return;
        }

        $this->enqueueAttachments($page, OpenWebUISyncService::TASK_DELETE_ATTACHMENT);
        $this->enqueueImages($page, OpenWebUISyncService::TASK_DELETE_IMAGE);
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

        $this->enqueueAttachments($page, OpenWebUISyncService::TASK_DELETE_ATTACHMENT);
        $this->enqueueImages($page, OpenWebUISyncService::TASK_DELETE_IMAGE);
    }

    private function enqueueAttachments(mixed $page, string $taskType): void
    {
        if (!isset($page->id)) {
            return;
        }

        $repo = app(BookStackRepository::class);
        $attachments = $repo->listAttachmentsForPage((int) $page->id);
        if (!$attachments) {
            return;
        }

        $sync = app(OpenWebUISyncService::class);
        foreach ($attachments as $attachment) {
            if (!isset($attachment->id)) {
                continue;
            }
            $sync->enqueueTask($taskType, [
                'attachment_id' => $attachment->id,
                'book_id' => $page->book_id ?? $attachment->book_id ?? null,
            ]);
        }
    }

    private function enqueueImages(mixed $page, string $taskType): void
    {
        if (!isset($page->id)) {
            return;
        }

        $repo = app(BookStackRepository::class);
        $images = $repo->listImagesForPage((int) $page->id);
        if (!$images) {
            return;
        }

        $sync = app(OpenWebUISyncService::class);
        foreach ($images as $image) {
            if (!isset($image->id)) {
                continue;
            }
            $sync->enqueueTask($taskType, [
                'image_id' => $image->id,
                'book_id' => $page->book_id ?? null,
            ]);
        }
    }
}
