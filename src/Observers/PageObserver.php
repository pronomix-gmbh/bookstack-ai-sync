<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Observers;

use Illuminate\Support\Facades\Log;
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

        $this->logger()->info('OpenWebUI page saved', [
            'page_id' => $page->id ?? null,
            'book_id' => $page->book_id ?? null,
            'title' => $page->name ?? $page->title ?? null,
        ]);

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

        $this->logger()->info('OpenWebUI page deleting', [
            'page_id' => $page->id ?? null,
            'book_id' => $page->book_id ?? null,
            'title' => $page->name ?? $page->title ?? null,
        ]);

        $this->enqueueAttachments($page, OpenWebUISyncService::TASK_DELETE_ATTACHMENT);
        $this->enqueueImages($page, OpenWebUISyncService::TASK_DELETE_IMAGE);
    }

    public function deleted($page): void
    {
        $sync = app(OpenWebUISyncService::class);
        if (!$sync->isEnabled()) {
            return;
        }

        $this->logger()->info('OpenWebUI page deleted', [
            'page_id' => $page->id ?? null,
            'book_id' => $page->book_id ?? null,
            'title' => $page->name ?? $page->title ?? null,
        ]);

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
            $this->logger()->warning('OpenWebUI page attachments skipped (missing page id)', [
                'task_type' => $taskType,
            ]);
            return;
        }

        $repo = app(BookStackRepository::class);
        $attachments = $repo->listAttachmentsForPage((int) $page->id);
        $attachmentIds = [];
        foreach ($attachments as $attachment) {
            if (isset($attachment->id)) {
                $attachmentIds[] = $attachment->id;
            }
        }
        $this->logger()->info('OpenWebUI page attachments resolved', [
            'page_id' => $page->id,
            'task_type' => $taskType,
            'count' => count($attachments),
            'attachment_ids' => $attachmentIds,
        ]);
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
            $this->logger()->warning('OpenWebUI page images skipped (missing page id)', [
                'task_type' => $taskType,
            ]);
            return;
        }

        $repo = app(BookStackRepository::class);
        $images = $repo->listImagesForPage((int) $page->id);
        $imageIds = [];
        foreach ($images as $image) {
            if (isset($image->id)) {
                $imageIds[] = $image->id;
            }
        }
        $this->logger()->info('OpenWebUI page images resolved', [
            'page_id' => $page->id,
            'task_type' => $taskType,
            'count' => count($images),
            'image_ids' => $imageIds,
        ]);
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

    private function logger(): \Psr\Log\LoggerInterface
    {
        $channel = config('bookstack-openwebui.log_channel') ?? config('logging.default');

        return Log::channel($channel);
    }
}
