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
        if (is_string($stream) && $extension === '') {
            $filename .= '.md';
        }

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
        $filename = $this->sanitizeFilename($externalKey) . ($extension ? '.' . $extension : '');

        $existing = OpenWebUIFileMap::query()
            ->where('entity_type', 'image')
            ->where('entity_id', $image->id)
            ->first();

        if ($existing && $existing->openwebui_file_id) {
            $this->deleteRemoteFileQuietly($existing->openwebui_file_id);
        }

        [$stream] = $this->bookStack->resolveImageStream($image);

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
        $mapping->entity_type = 'image';
        $mapping->entity_id = $image->id;
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

    public function deleteImage(int $imageId): void
    {
        $mapping = OpenWebUIFileMap::query()
            ->where('entity_type', 'image')
            ->where('entity_id', $imageId)
            ->first();

        if (!$mapping) {
            return;
        }

        if ($mapping->openwebui_file_id) {
            $this->deleteRemoteFileQuietly($mapping->openwebui_file_id);
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
        $this->enqueueTask(self::TASK_ENSURE_BOOK, ['book_id' => $bookId]);

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
