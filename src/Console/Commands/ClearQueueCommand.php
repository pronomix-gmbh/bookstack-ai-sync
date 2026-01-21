<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Console\Commands;

use Illuminate\Console\Command;
use Pronomix\BookStackOpenWebUISync\Models\OpenWebUISyncTask;

class ClearQueueCommand extends Command
{
    protected $signature = 'openwebui:clear-queue {--all : Clear all tasks, including done/processing} {--status=* : Clear only tasks with specific status values}';
    protected $description = 'Clear OpenWebUI sync tasks from the queue.';

    public function handle(): int
    {
        $statuses = $this->option('status');
        $clearAll = (bool) $this->option('all');

        $query = OpenWebUISyncTask::query();

        if (is_array($statuses) && count($statuses) > 0) {
            $query->whereIn('status', $statuses);
        } elseif (!$clearAll) {
            $query->whereIn('status', [
                OpenWebUISyncTask::STATUS_PENDING,
                OpenWebUISyncTask::STATUS_FAILED,
            ]);
        }

        $count = (int) $query->count();
        $query->delete();

        $this->info('Cleared ' . $count . ' queue task(s).');

        return Command::SUCCESS;
    }
}
