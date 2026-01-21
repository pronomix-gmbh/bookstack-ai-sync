<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Services;

use Illuminate\Http\Client\RequestException;
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
    public const TASK_UPSERT_IMAGE = 'upsert_image';
    public const TASK_DELETE_IMAGE = 'delete_image';
    public const TASK_ENSURE_BOOK = 'ensure_book_knowledge';
    public const TASK_REBUILD_BOOK = 'rebuild_book';
    public const TASK_DELETE_BOOK = 'delete_book';

    public function __construct(
        private readonly BookStackRepository $bookStack,
        private readonly OpenWebUIClient $client
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('bookstack-openwebui.enabled');
    }

    public function instanceName(): string
    {
        $value = config('bookstack-openwebui.instance_name');
        return $value ? (string) $value : 'bookstack';
    }

    public function workspaceEnabled(): bool
    {
        return (bool) config('bookstack-openwebui.workspace.enabled', true);
    }

    public function workspaceModelId(): string
    {
        $value = (string) config('bookstack-openwebui.workspace.model_id', '');

        return $value !== '' ? $value : $this->instanceName();
    }

    public function workspaceModelName(): string
    {
        $value = (string) config('bookstack-openwebui.workspace.model_name', '');

        return $value !== '' ? $value : $this->instanceName();
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

    public function externalKeyForImage(string $bookSlug, int $imageId): string
    {
        return $this->instanceName() . ':' . $bookSlug . ':image:' . $imageId;
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
        $shouldSyncWorkspace = false;

        $knowledge = $this->findKnowledge($mapping?->knowledge_id, $expectedName);

        if (!$knowledge) {
            $created = $this->client->createKnowledge($expectedName, $this->knowledgeDescriptionForBook($book));
            $knowledgeId = $this->client->extractId($created);
            if (!$knowledgeId) {
                throw new \RuntimeException('OpenWebUI did not return a knowledge ID');
            }
            $knowledge = ['id' => $knowledgeId, 'name' => $expectedName];
            $shouldSyncWorkspace = true;
        }

        if (!$mapping) {
            $mapping = new OpenWebUIKnowledgeMap();
            $mapping->book_id = $book->id;
            $shouldSyncWorkspace = true;
        }

        $mapping->book_slug = $book->slug ?? null;
        $mapping->knowledge_id = (string) Arr::get($knowledge, 'id');
        $mapping->knowledge_name = (string) Arr::get($knowledge, 'name', $expectedName);
        $mapping->save();

        if ($shouldSyncWorkspace) {
            $this->syncWorkspaceKnowledge();
        }

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

        $searchFileId = $this->findFileIdByFilename($knowledgeMap->knowledge_id, $filename);
        $existingFileId = $searchFileId ?: ($existing?->openwebui_file_id);

        if ($existingFileId) {
            try {
                $this->updateKnowledgeFileContent($knowledgeMap->knowledge_id, $existingFileId, $content);
                $this->saveFileMapping(
                    $existing,
                    'page',
                    $page->id,
                    $book->id,
                    $knowledgeMap->knowledge_id,
                    $existingFileId,
                    $filename,
                    $externalKey
                );
                return;
            } catch (RequestException $e) {
                $status = $e->response?->status();
                if ($status !== 404) {
                    throw $e;
                }
            }
        }

        try {
            $upload = $this->client->uploadFile($filename, $content);
        } catch (RequestException $e) {
            if ($this->isDuplicateContentError($e) && $this->handleDuplicateUpload(
                $existing,
                $knowledgeMap->knowledge_id,
                $filename,
                $externalKey,
                'page',
                $page->id,
                (int) $book->id
            )) {
                return;
            }
            throw $e;
        }

        $fileId = $this->client->extractId($upload);
        if (!$fileId) {
            throw new \RuntimeException('OpenWebUI did not return a file ID');
        }

        $this->client->addFileToKnowledge($knowledgeMap->knowledge_id, $fileId);

        if ($searchFileId && $searchFileId !== $fileId) {
            $this->removeKnowledgeFileQuietly($knowledgeMap->knowledge_id, $searchFileId, true);
        }

        $this->saveFileMapping(
            $existing,
            'page',
            $page->id,
            $book->id,
            $knowledgeMap->knowledge_id,
            $fileId,
            $filename,
            $externalKey
        );
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

        $knowledgeId = $mapping->knowledge_id;
        $filename = $mapping->openwebui_filename ?? '';
        $fileId = $filename !== '' && $knowledgeId
            ? $this->findFileIdByFilename($knowledgeId, $filename)
            : null;
        $fileId = $fileId ?: $mapping->openwebui_file_id;

        if ($knowledgeId && $fileId) {
            $this->removeKnowledgeFileQuietly($knowledgeId, $fileId, true);
        } elseif ($fileId) {
            $this->deleteRemoteFileQuietly($fileId);
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
        $baseFilename = $this->sanitizeFilename($externalKey);
        $filename = $baseFilename . ($extension ? '.' . $extension : '');
        $candidates = $extension !== '' ? [$filename] : [$baseFilename, $baseFilename . '.md'];

        $existing = OpenWebUIFileMap::query()
            ->where('entity_type', 'attachment')
            ->where('entity_id', $attachment->id)
            ->first();

        [$searchFileId, $searchFilename] = $this->findFileIdByCandidates($knowledgeMap->knowledge_id, $candidates);
        $existingFileId = $searchFileId ?: ($existing?->openwebui_file_id);
        $resolvedFilename = $searchFilename ?: ($existing?->openwebui_filename ?: $candidates[0]);

        if ($existingFileId) {
            try {
                $this->ensureKnowledgeFileUpdated($knowledgeMap->knowledge_id, $existingFileId);
                $this->saveFileMapping(
                    $existing,
                    'attachment',
                    $attachment->id,
                    $book->id,
                    $knowledgeMap->knowledge_id,
                    $existingFileId,
                    $resolvedFilename,
                    $externalKey
                );
                return;
            } catch (RequestException $e) {
                $status = $e->response?->status();
                if ($status !== 404) {
                    throw $e;
                }
            }
        }

        [$stream] = $this->bookStack->resolveAttachmentStream($attachment);
        if (is_string($stream) && $extension === '') {
            $filename = $baseFilename . '.md';
        }

        try {
            try {
                $upload = $this->client->uploadFile($filename, $stream);
            } catch (RequestException $e) {
                if ($this->isDuplicateContentError($e) && $this->handleDuplicateUpload(
                    $existing,
                    $knowledgeMap->knowledge_id,
                    $filename,
                    $externalKey,
                    'attachment',
                    $attachment->id,
                    (int) $book->id
                )) {
                    return;
                }
                throw $e;
            }
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

        if ($searchFileId && $searchFileId !== $fileId) {
            $this->removeKnowledgeFileQuietly($knowledgeMap->knowledge_id, $searchFileId, true);
        }

        $this->saveFileMapping(
            $existing,
            'attachment',
            $attachment->id,
            $book->id,
            $knowledgeMap->knowledge_id,
            $fileId,
            $filename,
            $externalKey
        );
    }

    public function upsertImage(int $imageId): void
    {
        $image = $this->bookStack->getImageById($imageId);

        if (!$image) {
            $this->deleteImage($imageId);
            return;
        }

        $book = $this->bookStack->resolveBookForImage($image);
        if (!$book) {
            return;
        }

        $knowledgeMap = $this->ensureKnowledgeForBook((int) $book->id);
        if (!$knowledgeMap) {
            return;
        }

        $bookSlug = $book->slug ?? ('book-' . $book->id);
        $externalKey = $this->externalKeyForImage((string) $bookSlug, (int) $image->id);
        $originalName = $this->bookStack->imageFilename($image);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $baseFilename = $this->sanitizeFilename($externalKey);
        $filename = $baseFilename . ($extension ? '.' . $extension : '');
        $candidates = $extension !== '' ? [$filename] : [$baseFilename];

        $existing = OpenWebUIFileMap::query()
            ->where('entity_type', 'image')
            ->where('entity_id', $image->id)
            ->first();

        [$searchFileId, $searchFilename] = $this->findFileIdByCandidates($knowledgeMap->knowledge_id, $candidates);
        $existingFileId = $searchFileId ?: ($existing?->openwebui_file_id);
        $resolvedFilename = $searchFilename ?: ($existing?->openwebui_filename ?: $candidates[0]);

        if ($existingFileId) {
            try {
                $this->ensureKnowledgeFileUpdated($knowledgeMap->knowledge_id, $existingFileId);
                $this->saveFileMapping(
                    $existing,
                    'image',
                    $image->id,
                    $book->id,
                    $knowledgeMap->knowledge_id,
                    $existingFileId,
                    $resolvedFilename,
                    $externalKey
                );
                return;
            } catch (RequestException $e) {
                $status = $e->response?->status();
                if ($status !== 404) {
                    throw $e;
                }
            }
        }

        [$stream] = $this->bookStack->resolveImageStream($image);

        try {
            try {
                $upload = $this->client->uploadFile($filename, $stream);
            } catch (RequestException $e) {
                if ($this->isDuplicateContentError($e) && $this->handleDuplicateUpload(
                    $existing,
                    $knowledgeMap->knowledge_id,
                    $filename,
                    $externalKey,
                    'image',
                    $image->id,
                    (int) $book->id
                )) {
                    return;
                }
                throw $e;
            }
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

        if ($searchFileId && $searchFileId !== $fileId) {
            $this->removeKnowledgeFileQuietly($knowledgeMap->knowledge_id, $searchFileId, true);
        }

        $this->saveFileMapping(
            $existing,
            'image',
            $image->id,
            $book->id,
            $knowledgeMap->knowledge_id,
            $fileId,
            $filename,
            $externalKey
        );
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

        $knowledgeId = $mapping->knowledge_id;
        $filename = $mapping->openwebui_filename ?? '';
        $fileId = $filename !== '' && $knowledgeId
            ? $this->findFileIdByFilename($knowledgeId, $filename)
            : null;
        $fileId = $fileId ?: $mapping->openwebui_file_id;

        if ($knowledgeId && $fileId) {
            $this->removeKnowledgeFileQuietly($knowledgeId, $fileId, true);
        } elseif ($fileId) {
            $this->deleteRemoteFileQuietly($fileId);
        }

        $mapping->delete();
    }

    public function deleteImage(int $imageId): void
    {
        $mapping = OpenWebUIFileMap::query()
            ->where('entity_type', 'image')
            ->where('entity_id', $imageId)
            ->first();

        if (!$mapping) {
            return;
        }

        $knowledgeId = $mapping->knowledge_id;
        $filename = $mapping->openwebui_filename ?? '';
        $fileId = $filename !== '' && $knowledgeId
            ? $this->findFileIdByFilename($knowledgeId, $filename)
            : null;
        $fileId = $fileId ?: $mapping->openwebui_file_id;

        if ($knowledgeId && $fileId) {
            $this->removeKnowledgeFileQuietly($knowledgeId, $fileId, true);
        } elseif ($fileId) {
            $this->deleteRemoteFileQuietly($fileId);
        }

        $mapping->delete();
    }

    public function deleteBook(int $bookId): void
    {
        $knowledgeMap = OpenWebUIKnowledgeMap::query()->where('book_id', $bookId)->first();
        $knowledgeId = $knowledgeMap?->knowledge_id;

        $fileMappings = OpenWebUIFileMap::query()->where('book_id', $bookId)->get();
        foreach ($fileMappings as $mapping) {
            if ($mapping->openwebui_file_id) {
                $this->deleteRemoteFileQuietly($mapping->openwebui_file_id);
            }
        }

        if ($knowledgeId) {
            try {
                $this->client->deleteKnowledge($knowledgeId);
            } catch (RequestException $e) {
                $status = $e->response?->status();
                if ($status !== 404) {
                    throw $e;
                }
            }
        }

        OpenWebUIFileMap::query()->where('book_id', $bookId)->delete();
        if ($knowledgeMap) {
            $knowledgeMap->delete();
        }

        $this->syncWorkspaceKnowledge();
    }

    public function rebuildBook(int $bookId): void
    {
        $knowledgeMap = $this->ensureKnowledgeForBook($bookId);
        if (!$knowledgeMap) {
            return;
        }

        $book = $this->bookStack->getBookById($bookId);
        if (!$book) {
            return;
        }

        $this->pruneKnowledgeFilesForBook($book, $knowledgeMap);

        foreach ($this->bookStack->listPagesForBook($bookId) as $page) {
            $this->enqueueTask(self::TASK_UPSERT_PAGE, ['page_id' => $page->id, 'book_id' => $bookId]);
        }

        foreach ($this->bookStack->listAttachmentsForBook($bookId) as $attachment) {
            $this->enqueueTask(self::TASK_UPSERT_ATTACHMENT, ['attachment_id' => $attachment->id, 'book_id' => $bookId]);
        }

        foreach ($this->bookStack->listImagesForBook($bookId) as $image) {
            $this->enqueueTask(self::TASK_UPSERT_IMAGE, ['image_id' => $image->id, 'book_id' => $bookId]);
        }
    }

    public function enqueueTask(string $type, array $payload = []): OpenWebUISyncTask
    {
        $task = OpenWebUISyncTask::enqueue(array_merge(['task_type' => $type], $payload));

        $dispatch = (bool) config('bookstack-openwebui.queue.dispatch_jobs');
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

        if (OpenWebUIKnowledgeMap::query()->exists()) {
            $this->syncWorkspaceKnowledge();
        }
    }

    public function enqueueSyncBook(int $bookId): void
    {
        $this->enqueueTask(self::TASK_REBUILD_BOOK, ['book_id' => $bookId]);
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
            case self::TASK_UPSERT_IMAGE:
                if ($task->image_id) {
                    $this->upsertImage((int) $task->image_id);
                }
                return;
            case self::TASK_DELETE_IMAGE:
                if ($task->image_id) {
                    $this->deleteImage((int) $task->image_id);
                }
                return;
            case self::TASK_REBUILD_BOOK:
                if ($task->book_id) {
                    $this->rebuildBook((int) $task->book_id);
                }
                return;
            case self::TASK_DELETE_BOOK:
                if ($task->book_id) {
                    $this->deleteBook((int) $task->book_id);
                }
                return;
            case 'poll_changes':
                // Legacy no-op for removed polling tasks.
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

    private function resolveLogChannel(): string
    {
        $channel = config('bookstack-openwebui.log_channel');
        if (is_string($channel) && $channel !== '' && config('logging.channels.' . $channel)) {
            return $channel;
        }

        $default = (string) config('logging.default');
        if ($default !== '' && config('logging.channels.' . $default)) {
            return $default;
        }

        return 'stack';
    }

    private function isDuplicateContentError(RequestException $e): bool
    {
        $response = $e->response;
        if (!$response || $response->status() !== 400) {
            return false;
        }

        $body = (string) $response->body();

        return stripos($body, 'duplicate content') !== false;
    }

    private function handleDuplicateUpload(
        ?OpenWebUIFileMap $existing,
        string $knowledgeId,
        string $filename,
        string $externalKey,
        string $entityType,
        int $entityId,
        int $bookId
    ): bool {
        $fileId = $this->findFileIdByFilename($knowledgeId, $filename);
        if (!$fileId && $existing?->openwebui_file_id) {
            $fileId = $existing->openwebui_file_id;
        }

        if (!$fileId) {
            Log::channel($this->resolveLogChannel())->warning('OpenWebUI duplicate content without existing file', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);
            return false;
        }

        try {
            $this->client->addFileToKnowledge($knowledgeId, $fileId);
        } catch (\Throwable $e) {
            Log::channel($this->resolveLogChannel())->warning('OpenWebUI duplicate content could not reattach file', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->saveFileMapping(
            $existing,
            $entityType,
            $entityId,
            $bookId,
            $knowledgeId,
            $fileId,
            $filename,
            $externalKey
        );

        return true;
    }

    private function updateKnowledgeFileContent(string $knowledgeId, string $fileId, string $content): void
    {
        $this->client->updateFileContent($fileId, $content);

        try {
            $this->client->updateKnowledgeFile($knowledgeId, $fileId);
        } catch (RequestException $e) {
            $status = $e->response?->status();
            if ($status !== 404) {
                throw $e;
            }

            $this->client->addFileToKnowledge($knowledgeId, $fileId);
            $this->client->updateKnowledgeFile($knowledgeId, $fileId);
        }
    }

    private function ensureKnowledgeFileUpdated(string $knowledgeId, string $fileId): void
    {
        try {
            $this->client->updateKnowledgeFile($knowledgeId, $fileId);
        } catch (RequestException $e) {
            $status = $e->response?->status();
            if ($status !== 404) {
                throw $e;
            }

            $this->client->addFileToKnowledge($knowledgeId, $fileId);
            $this->client->updateKnowledgeFile($knowledgeId, $fileId);
        }
    }

    private function saveFileMapping(
        ?OpenWebUIFileMap $existing,
        string $entityType,
        int $entityId,
        int $bookId,
        string $knowledgeId,
        string $fileId,
        string $filename,
        string $externalKey
    ): OpenWebUIFileMap {
        $mapping = $existing ?? new OpenWebUIFileMap();
        $mapping->entity_type = $entityType;
        $mapping->entity_id = $entityId;
        $mapping->book_id = $bookId;
        $mapping->knowledge_id = $knowledgeId;
        $mapping->openwebui_file_id = $fileId;
        $mapping->openwebui_filename = $filename;
        $mapping->external_key = $externalKey;
        $mapping->save();

        return $mapping;
    }

    private function findFileIdByFilename(string $knowledgeId, string $filename): ?string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return null;
        }

        $searchResults = $this->client->searchFilesByFilename($filename);
        $knowledgeResults = $this->listKnowledgeFiles($knowledgeId, ['query' => $filename]);

        $knowledgeIds = [];
        foreach ($knowledgeResults as $file) {
            $id = (string) Arr::get($file, 'id');
            $fileName = (string) Arr::get($file, 'filename');
            if ($id === '' || $fileName === '') {
                continue;
            }
            if ($fileName === $filename) {
                return $id;
            }
            $knowledgeIds[$id] = true;
        }

        foreach ($searchResults as $file) {
            $id = (string) Arr::get($file, 'id');
            $fileName = (string) Arr::get($file, 'filename');
            if ($id === '') {
                continue;
            }
            if ($fileName !== '' && $fileName !== $filename) {
                continue;
            }
            if (empty($knowledgeIds) || isset($knowledgeIds[$id])) {
                return $id;
            }
        }

        if (!empty($knowledgeIds)) {
            $first = array_key_first($knowledgeIds);
            return $first !== null ? (string) $first : null;
        }

        return null;
    }

    private function findFileIdByCandidates(string $knowledgeId, array $candidates): array
    {
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }
            $fileId = $this->findFileIdByFilename($knowledgeId, $candidate);
            if ($fileId) {
                return [$fileId, $candidate];
            }
        }

        return [null, null];
    }

    private function listKnowledgeFiles(string $knowledgeId, array $query = []): array
    {
        $response = $this->client->listKnowledgeFiles($knowledgeId, $query);
        return $this->extractKnowledgeFileItems($response);
    }

    private function listAllKnowledgeFiles(string $knowledgeId): array
    {
        $page = 1;
        $all = [];

        while (true) {
            $response = $this->client->listKnowledgeFiles($knowledgeId, ['page' => $page]);
            $items = $this->extractKnowledgeFileItems($response);
            if (empty($items)) {
                break;
            }

            $all = array_merge($all, $items);

            $total = $this->extractKnowledgeFileTotal($response);
            if ($total !== null && count($all) >= $total) {
                break;
            }

            $page++;
        }

        return $all;
    }

    private function extractKnowledgeFileItems(array $response): array
    {
        if (Arr::has($response, 'items') && is_array($response['items'])) {
            return $response['items'];
        }

        if (Arr::has($response, 'data') && is_array($response['data'])) {
            return $response['data'];
        }

        return [];
    }

    private function extractKnowledgeFileTotal(array $response): ?int
    {
        $total = Arr::get($response, 'total');
        if (is_int($total)) {
            return $total;
        }
        if (is_numeric($total)) {
            return (int) $total;
        }

        return null;
    }

    private function pruneKnowledgeFilesForBook(mixed $book, OpenWebUIKnowledgeMap $knowledgeMap): void
    {
        $knowledgeId = $knowledgeMap->knowledge_id;
        if ($knowledgeId === '') {
            return;
        }

        $bookSlug = $book->slug ?? ('book-' . $book->id);
        $expected = $this->buildExpectedFilenamesForBook($book, (string) $bookSlug);
        $prefix = $this->sanitizeFilename($this->instanceName() . ':' . $bookSlug . ':');

        $files = $this->listAllKnowledgeFiles($knowledgeId);
        foreach ($files as $file) {
            $fileId = (string) Arr::get($file, 'id');
            $filename = (string) Arr::get($file, 'filename');

            if ($fileId === '' || $filename === '') {
                continue;
            }

            if (isset($expected[$filename])) {
                continue;
            }

            if ($prefix !== '' && !str_starts_with($filename, $prefix)) {
                continue;
            }

            $this->removeKnowledgeFileQuietly($knowledgeId, $fileId, true);
            OpenWebUIFileMap::query()
                ->where('knowledge_id', $knowledgeId)
                ->where('openwebui_file_id', $fileId)
                ->delete();
        }

        $expectedNames = array_keys($expected);
        if (empty($expectedNames)) {
            OpenWebUIFileMap::query()
                ->where('knowledge_id', $knowledgeId)
                ->delete();
            return;
        }

        OpenWebUIFileMap::query()
            ->where('knowledge_id', $knowledgeId)
            ->whereNotIn('openwebui_filename', $expectedNames)
            ->delete();
    }

    private function buildExpectedFilenamesForBook(mixed $book, string $bookSlug): array
    {
        $expected = [];

        foreach ($this->bookStack->listPagesForBook((int) $book->id) as $page) {
            $externalKey = $this->externalKeyForPage($bookSlug, (int) $page->id);
            $filename = $this->sanitizeFilename($externalKey) . '.md';
            $expected[$filename] = true;
        }

        foreach ($this->bookStack->listAttachmentsForBook((int) $book->id) as $attachment) {
            $externalKey = $this->externalKeyForAttachment($bookSlug, (int) $attachment->id);
            $base = $this->sanitizeFilename($externalKey);
            $extension = pathinfo($this->bookStack->attachmentFilename($attachment), PATHINFO_EXTENSION);

            if ($extension !== '') {
                $expected[$base . '.' . $extension] = true;
            } else {
                $expected[$base] = true;
                $expected[$base . '.md'] = true;
            }
        }

        foreach ($this->bookStack->listImagesForBook((int) $book->id) as $image) {
            $externalKey = $this->externalKeyForImage($bookSlug, (int) $image->id);
            $base = $this->sanitizeFilename($externalKey);
            $extension = pathinfo($this->bookStack->imageFilename($image), PATHINFO_EXTENSION);

            $expected[$extension !== '' ? $base . '.' . $extension : $base] = true;
        }

        return $expected;
    }

    private function removeKnowledgeFileQuietly(string $knowledgeId, string $fileId, bool $deleteFile): void
    {
        try {
            $this->client->removeKnowledgeFile($knowledgeId, $fileId, $deleteFile);
        } catch (\Throwable $e) {
            Log::channel($this->resolveLogChannel())->warning('OpenWebUI knowledge file remove failed', [
                'file_id' => $fileId,
                'knowledge_id' => $knowledgeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function deleteRemoteFileQuietly(string $fileId): void
    {
        try {
            $this->client->deleteFile($fileId);
        } catch (\Throwable $e) {
            Log::channel($this->resolveLogChannel())->warning('OpenWebUI file delete failed', [
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

    private function syncWorkspaceKnowledge(): void
    {
        if (!$this->workspaceEnabled()) {
            return;
        }

        $modelId = $this->workspaceModelId();
        if ($modelId === '') {
            return;
        }

        $model = $this->getOrCreateWorkspaceModel($modelId);
        if (!$model) {
            return;
        }

        $desiredIds = OpenWebUIKnowledgeMap::query()
            ->pluck('knowledge_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $knowledgeItems = $this->collectKnowledgeItems($desiredIds);

        $meta = Arr::get($model, 'meta', []);
        if (!is_array($meta)) {
            $meta = [];
        }
        $meta['knowledge'] = $knowledgeItems;

        $payload = $this->buildModelPayload($modelId, $model, $meta);
        $this->client->updateModel($payload);
    }

    private function collectKnowledgeItems(array $knowledgeIds): array
    {
        if (empty($knowledgeIds)) {
            return [];
        }

        $lookup = [];
        foreach ($this->client->listKnowledge() as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (string) Arr::get($entry, 'id');
            if ($id !== '') {
                $lookup[$id] = $entry;
            }
        }

        $items = [];
        foreach ($knowledgeIds as $id) {
            if (isset($lookup[$id])) {
                $items[] = $lookup[$id];
                continue;
            }
            $entry = $this->client->getKnowledge((string) $id);
            if (is_array($entry)) {
                $items[] = $entry;
            }
        }

        usort($items, function (array $a, array $b): int {
            return strcmp((string) Arr::get($a, 'name'), (string) Arr::get($b, 'name'));
        });

        return $items;
    }

    private function getOrCreateWorkspaceModel(string $modelId): ?array
    {
        $model = $this->client->getModel($modelId);
        if (is_array($model)) {
            return $model;
        }

        $meta = [
            'description' => 'Workspace for ' . $this->instanceName(),
            'knowledge' => [],
        ];

        $payload = [
            'id' => $modelId,
            'name' => $this->workspaceModelName(),
            'base_model_id' => null,
            'params' => (object) [],
            'meta' => $meta,
            'access_control' => null,
            'is_active' => true,
        ];

        try {
            $created = $this->client->createModel($payload);
        } catch (RequestException $e) {
            $status = $e->response?->status();
            if ($status === 409) {
                return $this->client->getModel($modelId);
            }
            throw $e;
        }

        return is_array($created) ? $created : null;
    }

    private function buildModelPayload(string $modelId, array $model, array $meta): array
    {
        $name = (string) Arr::get($model, 'name', $this->workspaceModelName());
        if ($name === '') {
            $name = $modelId;
        }

        return [
            'id' => $modelId,
            'name' => $name,
            'base_model_id' => Arr::get($model, 'base_model_id'),
            'params' => $this->normalizeModelParams(Arr::get($model, 'params')),
            'meta' => $meta,
            'access_control' => $this->normalizeModelObject(Arr::get($model, 'access_control')),
            'is_active' => (bool) Arr::get($model, 'is_active', true),
        ];
    }

    private function normalizeModelParams(mixed $value): object
    {
        if (is_array($value)) {
            return (object) $value;
        }

        if (is_object($value)) {
            return $value;
        }

        return (object) [];
    }

    private function normalizeModelObject(mixed $value): mixed
    {
        if (is_array($value)) {
            return (object) $value;
        }

        if (is_object($value) || $value === null) {
            return $value;
        }

        return (object) [];
    }
}
