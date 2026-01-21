<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Pronomix\BookStackOpenWebUISync\Models\OpenWebUISyncTask;

class ListQueueCommand extends Command
{
    protected $signature = 'openwebui:list-queue {--limit= : Limit number of tasks (defaults to all open tasks)}';
    protected $description = 'List open OpenWebUI sync tasks.';

    public function handle(): int
    {
        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;

        $query = OpenWebUISyncTask::query()
            ->whereIn('status', [
                OpenWebUISyncTask::STATUS_PENDING,
                OpenWebUISyncTask::STATUS_PROCESSING,
                OpenWebUISyncTask::STATUS_FAILED,
            ])
            ->orderBy('status')
            ->orderBy('available_at');

        if ($limit && $limit > 0) {
            $query->limit($limit);
        }

        $tasks = $query->get();

        if ($tasks->isEmpty()) {
            $this->info('No open tasks.');
            return Command::SUCCESS;
        }

        $rows = $tasks->map(function (OpenWebUISyncTask $task): array {
            $error = $task->last_error ? str_replace(["\r", "\n"], ' ', $task->last_error) : '';

            return [
                $task->id,
                $task->status,
                $task->task_type,
                $task->book_id,
                $task->page_id,
                $task->attachment_id,
                $task->image_id,
                $task->attempts,
                $task->available_at?->toDateTimeString(),
                $error !== '' ? Str::limit($error, 80) : '',
            ];
        })->all();

        $this->table(
            ['ID', 'Status', 'Type', 'Book', 'Page', 'Attachment', 'Image', 'Attempts', 'Available', 'Last error'],
            $rows
        );

        $this->info('Total open tasks: ' . $tasks->count() . '.');

        return Command::SUCCESS;
    }
}
