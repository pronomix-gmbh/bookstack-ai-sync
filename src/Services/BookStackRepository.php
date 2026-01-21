<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BookStackRepository
{
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
        if (!class_exists('BookStack\\Entities\\Models\\Attachment')) {
            return null;
        }

        return \BookStack\Entities\Models\Attachment::query()->find($attachmentId);
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
        if (!class_exists('BookStack\\Entities\\Models\\Attachment')) {
            return [];
        }

        $attachmentModel = new \BookStack\Entities\Models\Attachment();
        $table = $attachmentModel->getTable();

        $query = \BookStack\Entities\Models\Attachment::query();

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

    public function getPageBody(mixed $page): string
    {
        foreach (['markdown', 'text', 'html'] as $field) {
            if (isset($page->{$field}) && is_string($page->{$field})) {
                return $page->{$field};
            }
        }

        if (method_exists($page, 'getText')) {
            return (string) $page->getText();
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

        if (isset($attachment->page)) {
            $page = $attachment->page;
            return $page->book ?? $this->getBookById((int) ($page->book_id ?? 0));
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

        $disk = $attachment->disk ?? config('filesystems.default');
        $path = $attachment->path ?? $attachment->file_path ?? null;

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
        foreach (['name', 'filename', 'original_name'] as $field) {
            if (isset($attachment->{$field}) && is_string($attachment->{$field})) {
                return $attachment->{$field};
            }
        }

        return 'attachment';
    }
}
