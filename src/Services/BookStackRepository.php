<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookStackRepository
{
    private const ATTACHMENT_MODEL_CLASSES = [
        'BookStack\\Uploads\\Attachment',
        'BookStack\\Entities\\Models\\Attachment',
    ];

    public function getBookById(int $bookId): mixed
    {
        if (!class_exists('BookStack\\Entities\\Models\\Book')) {
            return null;
        }

        return \BookStack\Entities\Models\Book::query()->find($bookId);
    }

    public function getPageById(int $pageId): mixed
    {
        if (!class_exists('BookStack\\Entities\\Models\\Page')) {
            return null;
        }

        return \BookStack\Entities\Models\Page::query()->find($pageId);
    }

    public function getAttachmentById(int $attachmentId): mixed
    {
        $attachmentClass = $this->attachmentModelClass();
        if (!$attachmentClass) {
            return null;
        }

        return $attachmentClass::query()->find($attachmentId);
    }

    public function listBooks(): array
    {
        if (!class_exists('BookStack\\Entities\\Models\\Book')) {
            return [];
        }

        return \BookStack\Entities\Models\Book::query()->orderBy('name')->get()->all();
    }

    public function listPagesForBook(int $bookId): array
    {
        if (!class_exists('BookStack\\Entities\\Models\\Page')) {
            return [];
        }

        return \BookStack\Entities\Models\Page::query()
            ->where('book_id', $bookId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function listAttachmentsForBook(int $bookId): array
    {
        $attachmentClass = $this->attachmentModelClass();
        if (!$attachmentClass) {
            return [];
        }

        $attachmentModel = new $attachmentClass();
        $table = $attachmentModel->getTable();

        $query = $attachmentClass::query();

        if (Schema::hasColumn($table, 'book_id')) {
            $query->where('book_id', $bookId);
        } elseif (Schema::hasColumn($table, 'uploaded_to') && class_exists('BookStack\\Entities\\Models\\Page')) {
            $pageIds = \BookStack\Entities\Models\Page::query()
                ->where('book_id', $bookId)
                ->pluck('id');

            if ($pageIds->isEmpty()) {
                return [];
            }

            $query->whereIn('uploaded_to', $pageIds->all());
        } else {
            return [];
        }

        return $query->orderBy('id')->get()->all();
    }

    public function listAttachmentsForPage(int $pageId): array
    {
        $attachmentClass = $this->attachmentModelClass();
        if (!$attachmentClass) {
            return [];
        }

        $attachmentModel = new $attachmentClass();
        $table = $attachmentModel->getTable();

        $query = $attachmentClass::query();

        if (Schema::hasColumn($table, 'uploaded_to')) {
            $query->where('uploaded_to', $pageId);
        } elseif (Schema::hasColumn($table, 'page_id')) {
            $query->where('page_id', $pageId);
        } else {
            return [];
        }

        return $query->orderBy('id')->get()->all();
    }

    public function getPageBody(mixed $page): string
    {
        $body = $this->extractStringField($page, ['markdown', 'text', 'html', 'body', 'content']);
        if ($body !== '') {
            return $body;
        }

        foreach (['relatedData', 'pageData'] as $relation) {
            $related = $this->loadRelationIfExists($page, $relation);
            if ($related) {
                $body = $this->extractStringField($related, ['markdown', 'text', 'html', 'body', 'content']);
                if ($body !== '') {
                    return $body;
                }
            }
        }

        foreach (['getMarkdown', 'getRawMarkdown', 'getText', 'getHtml', 'getContent'] as $method) {
            if (!method_exists($page, $method)) {
                continue;
            }
            $value = $page->{$method}();
            $body = $this->stringifyValue($value);
            if ($body !== '') {
                return $body;
            }
        }

        return '';
    }

    public function getPageUrl(mixed $page): string
    {
        if (method_exists($page, 'getUrl')) {
            return (string) $page->getUrl();
        }

        if (isset($page->url)) {
            return (string) $page->url;
        }

        return '';
    }

    public function resolveBookForAttachment(mixed $attachment): mixed
    {
        if (isset($attachment->book)) {
            return $attachment->book;
        }

        if (isset($attachment->book_id)) {
            return $this->getBookById((int) $attachment->book_id);
        }

        if (method_exists($attachment, 'page')) {
            $page = $attachment->page;
            if ($page) {
                return $page->book ?? $this->getBookById((int) ($page->book_id ?? 0));
            }
        }

        if (isset($attachment->uploaded_to) && class_exists('BookStack\\Entities\\Models\\Page')) {
            $page = \BookStack\Entities\Models\Page::query()->find((int) $attachment->uploaded_to);
            if ($page) {
                return $page->book ?? $this->getBookById((int) $page->book_id);
            }
        }

        return null;
    }

    public function resolveAttachmentStream(mixed $attachment): array
    {
        if (method_exists($attachment, 'getStream')) {
            $stream = $attachment->getStream();
            return [$stream, $this->attachmentFilename($attachment)];
        }

        $path = $attachment->path ?? $attachment->file_path ?? null;

        if ($this->isExternalAttachment($attachment, $path)) {
            $content = $this->externalAttachmentMarkdown($attachment, $path);
            return [$content, $this->attachmentFilename($attachment)];
        }

        $disk = $attachment->disk ?? null;
        if (!$disk) {
            $disk = config('filesystems.disks.uploads') ? 'uploads' : config('filesystems.default');
        }

        if (!$path && method_exists($attachment, 'getFilePath')) {
            $path = $attachment->getFilePath();
        }

        if (!$path) {
            throw new \RuntimeException('Attachment path not found');
        }

        if (Str::startsWith($path, ['/','C:\\','D:\\'])) {
            $stream = fopen($path, 'rb');
            return [$stream, basename($path)];
        }

        $stream = Storage::disk($disk)->readStream($path);

        if (!$stream) {
            throw new \RuntimeException('Unable to open attachment stream');
        }

        return [$stream, basename($path)];
    }

    public function attachmentFilename(mixed $attachment): string
    {
        if (method_exists($attachment, 'getFileName')) {
            return (string) $attachment->getFileName();
        }

        foreach (['name', 'filename', 'original_name'] as $field) {
            if (isset($attachment->{$field}) && is_string($attachment->{$field})) {
                return $attachment->{$field};
            }
        }

        return 'attachment';
    }

    private function attachmentModelClass(): ?string
    {
        foreach (self::ATTACHMENT_MODEL_CLASSES as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    private function loadRelationIfExists(mixed $model, string $relation): mixed
    {
        if (!is_object($model) || !method_exists($model, $relation)) {
            return null;
        }

        if (method_exists($model, 'relationLoaded') && $model->relationLoaded($relation)) {
            return $model->{$relation};
        }

        if (method_exists($model, 'load')) {
            try {
                $model->load($relation);
            } catch (\Throwable) {
                return null;
            }
        }

        return $model->{$relation} ?? null;
    }

    private function extractStringField(mixed $source, array $fields): string
    {
        foreach ($fields as $field) {
            if (!is_object($source) || !isset($source->{$field})) {
                continue;
            }
            $value = $source->{$field};
            $string = $this->stringifyValue($value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    private function isExternalAttachment(mixed $attachment, mixed $path): bool
    {
        if (isset($attachment->external) && $attachment->external) {
            return true;
        }

        return is_string($path) && Str::startsWith($path, ['http://', 'https://']);
    }

    private function externalAttachmentMarkdown(mixed $attachment, mixed $path): string
    {
        $name = $this->attachmentFilename($attachment);
        $url = '';

        if (method_exists($attachment, 'getUrl')) {
            $url = (string) $attachment->getUrl();
        } elseif (is_string($path)) {
            $url = $path;
        }

        $link = $url !== '' ? '[' . $name . '](' . $url . ')' : $name;

        return implode("\n", [
            '# Attachment',
            '',
            $link,
            '',
        ]);
    }
}
