<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pronomix\BookStackOpenWebUISync\Models\OpenWebUISyncTask;
use Pronomix\BookStackOpenWebUISync\Services\OpenWebUISyncService;

class ProcessSyncTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $taskId)
    {
    }

    public function handle(OpenWebUISyncService $sync): void
    {
        $task = OpenWebUISyncTask::query()->find($this->taskId);

        if (!$task || $task->status !== OpenWebUISyncTask::STATUS_PENDING) {
            return;
        }

        $task->markProcessing();
        $correlationId = (string) Str::uuid();

        $channel = config('bookstack-openwebui.log_channel') ?? config('logging.default');
        Log::channel($channel)->withContext(['openwebui_task_id' => $task->id, 'correlation_id' => $correlationId]);
        Log::channel($channel)->info('OpenWebUI task started (job)', [
            'task_id' => $task->id,
            'task_type' => $task->task_type,
            'attempt' => $task->attempts,
        ]);

        try {
            $sync->handleTask($task);
            $task->markDone();
            Log::channel($channel)->info('OpenWebUI task completed (job)', [
                'task_id' => $task->id,
                'task_type' => $task->task_type,
            ]);
        } catch (\Throwable $e) {
            $maxAttempts = (int) config('bookstack-openwebui.queue.max_attempts');
            $backoff = (int) config('bookstack-openwebui.queue.backoff_seconds');
            $retryAt = null;

            if ($task->attempts < $maxAttempts) {
                $retryAt = now()->addSeconds($backoff);
            }

            $task->markFailed($e->getMessage(), $retryAt);

            Log::channel($channel)->error('OpenWebUI task failed', [
                'task_id' => $task->id,
                'task_type' => $task->task_type,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
