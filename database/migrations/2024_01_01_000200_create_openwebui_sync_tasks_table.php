<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('openwebui_sync_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('task_type');
            $table->unsignedBigInteger('book_id')->nullable();
            $table->unsignedBigInteger('page_id')->nullable();
            $table->unsignedBigInteger('attachment_id')->nullable();
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('available_at');
            $table->longText('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openwebui_sync_tasks');
    }
};
