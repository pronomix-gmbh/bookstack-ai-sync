<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Tests\Feature;

use Pronomix\BookStackOpenWebUISync\Models\OpenWebUISyncTask;
use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;
use Pronomix\BookStackOpenWebUISync\Tests\TestCase;

class TaskDeduplicationTest extends TestCase
{
    public function test_enqueue_dedupes_pending_tasks(): void
    {
        OpenWebUISyncTask::enqueue([
            'task_type' => OpenWebUISyncService::TASK_UPSERT_PAGE,
            'page_id' => 12,
            'book_id' => 3,
        ]);

        OpenWebUISyncTask::enqueue([
            'task_type' => OpenWebUISyncService::TASK_UPSERT_PAGE,
            'page_id' => 12,
            'book_id' => 3,
        ]);

        $this->assertSame(1, OpenWebUISyncTask::query()->count());
    }
}
