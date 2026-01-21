<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Pronomix\BookStackOpenWebUISync\Jobs\ProcessSyncTaskJob;
use Pronomix\BookStackOpenWebUISync\Models\OpenWebUIFileMap;
use Pronomix\BookStackOpenWebUISync\Models\OpenWebUIKnowledgeMap;
use Pronomix\BookStackOpenWebUISync\Models\OpenWebUISyncTask;

class OpenWebUISyncService
{
    public const TASK_UPSERT_PAGE = 'upsert_page';
    public const TASK_DELETE_PAGE = 'delete_page';
    public const TASK_UPSERT_ATTACHMENT = 'upsert_attachment';
    public const TASK_DELETE_ATTACHMENT = 'delete_attachment';
    public const TASK_ENSURE_BOOK = 'ensure_book_knowledge';
    public const TASK_REBUILD_BOOK = 'rebuild_book';
    public const TASK_POLL_CHANGES = 'poll_changes';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly BookStackRepository $bookStack,
        private readonly OpenWebUIClient $client
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get('enabled', config('bookstack-openwebui.enabled'));
    }

    public function instanceName(): string
    {
        $value = $this->settings->get('instance_name', config('bookstack-openwebui.instance_name'));
        return $value ? (string) $value : 'bookstack';
    }

    public function knowledgeNameForBook(mixed $book): string
    {
        return $this->instanceName() . ':' . (string) ($book->name ?? 'book');
    }

    public function knowledgeDescriptionForBook(mixed $book): string
    {
        $bookName = (string) ($book->name ?? 'book');
        return 'BookStack book "' . $bookName . '" from ' . $this->instanceName();
    }

    public function externalKeyForPage(string $bookSlug, int $pageId): string
    {
        return $this->instanceName() . ':' . $bookSlug . ':page:' . $pageId;
    }

    public function externalKeyForAttachment(string $bookSlug, int $attachmentId): string
    {
        return $this->instanceName() . ':' . $bookSlug . ':attachment:' . $attachmentId;
    }

    public function sanitizeFilename(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
        $sanitized = trim($sanitized ?? '', '_');

        return $sanitized !== '' ? $sanitized : 'file';
    }

    public function ensureKnowledgeForBook(int $bookId): ?OpenWebUIKnowledgeMap
    {
        $book = $this->bookStack->getBookById($bookId);

        if (!$book) {
            return null;
        }

        $expectedName = $this->knowledgeNameForBook($book);
        $mapping = OpenWebUIKnowledgeMap::query()->where('book_id', $book->id)->first();

        $knowledge = $this->findKnowledge($mapping?->knowledge_id, $expectedName);

        if (!$knowledge) {
            $created = $this->client->createKnowledge($expectedName, $this->knowledgeDescriptionForBook($book));
            $knowledgeId = $this->client->extractId($created);
            if (!$knowledgeId) {
                throw new \RuntimeException('OpenWebUI did not return a knowledge ID');
            }
            $knowledge = ['id' => $knowledgeId, 'name' => $expectedName];
        }

        if (!$mapping) {
            $mapping = new OpenWebUIKnowledgeMap();
            $mapping->book_id = $book->id;
        }

        $mapping->book_slug = $book->slug ?? null;
        $mapping->knowledge_id = (string) Arr::get($knowledge, 'id');
        $mapping->knowledge_name = (string) Arr::get($knowledge, 'name', $expectedName);
        $mapping->save();

        return $mapping;
    }

    public function upsertPage(int $pageId): void
    {
        $page = $this->bookStack->getPageById($pageId);

        if (!$page) {
            $this->deletePage($pageId);
            return;
        }

        $book = $page->book ?? $this->bookStack->getBookById((int) $page->book_id);
        if (!$book) {
            return;
        }

        $knowledgeMap = $this->ensureKnowledgeForBook((int) $book->id);
        if (!$knowledgeMap) {
            return;
        }

        $bookSlug = $book->slug ?? ('book-' . $book->id);
        $externalKey = $this->externalKeyForPage((string) $bookSlug, (int) $page->id);
        $filename = $this->sanitizeFilename($externalKey) . '.md';

        $content = $this->buildPageMarkdown($page, $book);

        $existing = OpenWebUIFileMap::query()
            ->where('entity_type', 'page')
            ->where('entity_id', $page->id)
            ->first();

        if ($existing && $existing->openwebui_file_id) {
            $this->deleteRemoteFileQuietly($existing->openwebui_file_id);
        }

        $upload = $this->client->uploadFile($filename, $content);
        $fileId = $this->client->extractId($upload);
        if (!$fileId) {
            throw new \RuntimeException('OpenWebUI did not return a file ID');
        }

        $this->client->addFileToKnowledge($knowledgeMap->knowledge_id, $fileId);

        $mapping = $existing ?? new OpenWebUIFileMap();
        $mapping->entity_type = 'page';
        $mapping->entity_id = $page->id;
        $mapping->book_id = $book->id;
        $mapping->knowledge_id = $knowledgeMap->knowledge_id;
        $mapping->openwebui_file_id = $fileId;
        $mapping->openwebui_filename = $filename;
        $mapping->external_key = $externalKey;
        $mapping->save();
    }

    public function deletePage(int $pageId): void
    {
        $mapping = OpenWebUIFileMap::query()
            ->where('entity_type', 'page')
            ->where('entity_id', $pageId)
            ->first();

        if (!$mapping) {
            return;
        }

        if ($mapping->openwebui_file_id) {
            $this->deleteRemoteFileQuietly($mapping->openwebui_file_id);
        }

        $mapping->delete();
    }

    public function upsertAttachment(int $attachmentId): void
    {
        $attachment = $this->bookStack->getAttachmentById($attachmentId);

        if (!$attachment) {
            $this->deleteAttachment($attachmentId);
            return;
        }

        $book = $this->bookStack->resolveBookForAttachment($attachment);
        if (!$book) {
            return;
        }

        $knowledgeMap = $this->ensureKnowledgeForBook((int) $book->id);
        if (!$knowledgeMap) {
            return;
        }

        $bookSlug = $book->slug ?? ('book-' . $book->id);
        $externalKey = $this->externalKeyForAttachment((string) $bookSlug, (int) $attachment->id);
        $originalName = $this->bookStack->attachmentFilename($attachment);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $filename = $this->sanitizeFilename($externalKey) . ($extension ? '.' . $extension : '');

        $existing = OpenWebUIFileMap::query()
            ->where('entity_type', 'attachment')
            ->where('entity_id', $attachment->id)
            ->first();

        if ($existing && $existing->openwebui_file_id) {
            $this->deleteRemoteFileQuietly($existing->openwebui_file_id);
        }

        [$stream] = $this->bookStack->resolveAttachmentStream($attachment);

        try {
            $upload = $this->client->uploadFile($filename, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $fileId = $this->client->extractId($upload);
        if (!$fileId) {
            throw new \RuntimeException('OpenWebUI did not return a file ID');
        }

        $this->client->addFileToKnowledge($knowledgeMap->knowledge_id, $fileId);

        $mapping = $existing ?? new OpenWebUIFileMap();
        $mapping->entity_type = 'attachment';
        $mapping->entity_id = $attachment->id;
        $mapping->book_id = $book->id;
        $mapping->knowledge_id = $knowledgeMap->knowledge_id;
        $mapping->openwebui_file_id = $fileId;
        $mapping->openwebui_filename = $filename;
        $mapping->external_key = $externalKey;
        $mapping->save();
    }

    public function deleteAttachment(int $attachmentId): void
    {
        $mapping = OpenWebUIFileMap::query()
            ->where('entity_type', 'attachment')
            ->where('entity_id', $attachmentId)
            ->first();

        if (!$mapping) {
            return;
        }

        if ($mapping->openwebui_file_id) {
            $this->deleteRemoteFileQuietly($mapping->openwebui_file_id);
        }

        $mapping->delete();
    }

    public function rebuildBook(int $bookId): void
    {
        $knowledgeMap = $this->ensureKnowledgeForBook($bookId);
        if (!$knowledgeMap) {
            return;
        }

        $existing = OpenWebUIFileMap::query()->where('book_id', $bookId)->get();
        foreach ($existing as $map) {
            if ($map->openwebui_file_id) {
                $this->deleteRemoteFileQuietly($map->openwebui_file_id);
            }
        }

        OpenWebUIFileMap::query()->where('book_id', $bookId)->delete();

        foreach ($this->bookStack->listPagesForBook($bookId) as $page) {
            $this->enqueueTask(self::TASK_UPSERT_PAGE, ['page_id' => $page->id, 'book_id' => $bookId]);
        }

        foreach ($this->bookStack->listAttachmentsForBook($bookId) as $attachment) {
            $this->enqueueTask(self::TASK_UPSERT_ATTACHMENT, ['attachment_id' => $attachment->id, 'book_id' => $bookId]);
        }
    }

    public function pollChanges(): void
    {
        $last = $this->settings->get('poll_last_seen', null);
        $lastSeen = $last ? new \Carbon\Carbon($last) : now()->subMinutes(10);

        if (class_exists('BookStack\\Entities\\Models\\Page')) {
            $pages = \BookStack\Entities\Models\Page::query()
                ->where('updated_at', '>', $lastSeen)
                ->orderBy('updated_at')
                ->get();

            foreach ($pages as $page) {
                $this->enqueueTask(self::TASK_UPSERT_PAGE, ['page_id' => $page->id, 'book_id' => $page->book_id]);
            }
        }

        if (class_exists('BookStack\\Entities\\Models\\Attachment')) {
            $attachments = \BookStack\Entities\Models\Attachment::query()
                ->where('updated_at', '>', $lastSeen)
                ->orderBy('updated_at')
                ->get();

            foreach ($attachments as $attachment) {
                $this->enqueueTask(self::TASK_UPSERT_ATTACHMENT, ['attachment_id' => $attachment->id, 'book_id' => $attachment->book_id]);
            }
        }

        $this->settings->set('poll_last_seen', now()->toDateTimeString());
    }

    public function enqueueTask(string $type, array $payload = []): OpenWebUISyncTask
    {
        $task = OpenWebUISyncTask::enqueue(array_merge(['task_type' => $type], $payload));

        $dispatch = (bool) $this->settings->get('queue_dispatch', config('bookstack-openwebui.queue.dispatch_jobs'));
        if ($dispatch) {
            ProcessSyncTaskJob::dispatch($task->id);
        }

        return $task;
    }

    public function enqueueSyncAll(): void
    {
        foreach ($this->bookStack->listBooks() as $book) {
            $this->enqueueTask(self::TASK_REBUILD_BOOK, ['book_id' => $book->id]);
        }
    }

    public function enqueueSyncBook(int $bookId): void
    {
        $this->enqueueTask(self::TASK_ENSURE_BOOK, ['book_id' => $bookId]);

        foreach ($this->bookStack->listPagesForBook($bookId) as $page) {
            $this->enqueueTask(self::TASK_UPSERT_PAGE, ['page_id' => $page->id, 'book_id' => $bookId]);
        }

        foreach ($this->bookStack->listAttachmentsForBook($bookId) as $attachment) {
            $this->enqueueTask(self::TASK_UPSERT_ATTACHMENT, ['attachment_id' => $attachment->id, 'book_id' => $bookId]);
        }
    }

    public function handleTask(OpenWebUISyncTask $task): void
    {
        switch ($task->task_type) {
            case self::TASK_ENSURE_BOOK:
                if ($task->book_id) {
                    $this->ensureKnowledgeForBook((int) $task->book_id);
                }
                return;
            case self::TASK_UPSERT_PAGE:
                if ($task->page_id) {
                    $this->upsertPage((int) $task->page_id);
                }
                return;
            case self::TASK_DELETE_PAGE:
                if ($task->page_id) {
                    $this->deletePage((int) $task->page_id);
                }
                return;
            case self::TASK_UPSERT_ATTACHMENT:
                if ($task->attachment_id) {
                    $this->upsertAttachment((int) $task->attachment_id);
                }
                return;
            case self::TASK_DELETE_ATTACHMENT:
                if ($task->attachment_id) {
                    $this->deleteAttachment((int) $task->attachment_id);
                }
                return;
            case self::TASK_REBUILD_BOOK:
                if ($task->book_id) {
                    $this->rebuildBook((int) $task->book_id);
                }
                return;
            case self::TASK_POLL_CHANGES:
                $this->pollChanges();
                return;
            default:
                throw new \RuntimeException('Unknown task type: ' . $task->task_type);
        }
    }

    public function buildPageMarkdown(mixed $page, mixed $book): string
    {
        $title = (string) ($page->name ?? $page->title ?? 'Untitled');
        $url = $this->bookStack->getPageUrl($page);
        $updated = (string) ($page->updated_at ?? '');
        $body = $this->bookStack->getPageBody($page);

        return implode("\n", [
            '---',
            'title: ' . $title,
            'book: ' . ($book->name ?? ''),
            'url: ' . $url,
            'updated_at: ' . $updated,
            '---',
            '',
            '# ' . $title,
            '',
            $body,
            '',
        ]);
    }

    public function shouldPoll(CarbonInterface $now): bool
    {
        $enabled = (bool) $this->settings->get('polling_enabled', config('bookstack-openwebui.polling.enabled'));
        if (!$enabled) {
            return false;
        }

        $interval = (int) $this->settings->get('polling_interval_minutes', config('bookstack-openwebui.polling.interval_minutes'));
        $last = $this->settings->get('poll_last_seen', null);

        if (!$last) {
            return true;
        }

        $lastSeen = new \Carbon\Carbon($last);

        return $lastSeen->addMinutes($interval)->lessThanOrEqualTo($now);
    }

    private function deleteRemoteFileQuietly(string $fileId): void
    {
        try {
            $this->client->deleteFile($fileId);
        } catch (\Throwable $e) {
            $channel = config('bookstack-openwebui.log_channel') ?? config('logging.default');
            Log::channel($channel)->warning('OpenWebUI file delete failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findKnowledge(?string $knowledgeId, string $expectedName): ?array
    {
        $list = $this->normalizeKnowledgeList($this->client->listKnowledge());

        if ($knowledgeId) {
            foreach ($list as $entry) {
                if ((string) Arr::get($entry, 'id') === $knowledgeId) {
                    return $entry;
                }
            }
        }

        foreach ($list as $entry) {
            if ((string) Arr::get($entry, 'name') === $expectedName) {
                return $entry;
            }
        }

        return null;
    }

    private function normalizeKnowledgeList(array $list): array
    {
        if (Arr::has($list, 'items') && is_array($list['items'])) {
            return $list['items'];
        }

        if (Arr::has($list, 'data') && is_array($list['data'])) {
            return $list['data'];
        }

        return $list;
    }
}
