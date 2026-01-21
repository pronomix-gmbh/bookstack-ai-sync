<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class OpenWebUISyncTask extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $table = 'openwebui_sync_tasks';

    protected $fillable = [
        'task_type',
        'book_id',
        'page_id',
        'attachment_id',
        'image_id',
        'status',
        'attempts',
        'available_at',
        'last_error',
    ];

    protected $casts = [
        'available_at' => 'datetime',
    ];

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('available_at', '<=', Carbon::now());
    }

    public static function enqueue(array $attributes): self
    {
        $now = Carbon::now();

        $query = self::query()->where('task_type', $attributes['task_type']);

        foreach (['book_id', 'page_id', 'attachment_id', 'image_id'] as $key) {
            if (array_key_exists($key, $attributes)) {
                $query->where($key, $attributes[$key]);
            } else {
                $query->whereNull($key);
            }
        }

        $existing = $query->where('status', self::STATUS_PENDING)->first();

        if ($existing) {
            $existing->available_at = $now;
            $existing->save();

            return $existing;
        }

        $attributes['status'] = self::STATUS_PENDING;
        $attributes['attempts'] = $attributes['attempts'] ?? 0;
        $attributes['available_at'] = $attributes['available_at'] ?? $now;

        return self::create($attributes);
    }

    public function markProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->attempts++;
        $this->save();
    }

    public function markDone(): void
    {
        $this->status = self::STATUS_DONE;
        $this->save();
    }

    public function markFailed(string $message, CarbonInterface $retryAt = null): void
    {
        $this->last_error = $message;

        if ($retryAt) {
            $this->status = self::STATUS_PENDING;
            $this->available_at = $retryAt;
        } else {
            $this->status = self::STATUS_FAILED;
        }

        $this->save();
    }
}
