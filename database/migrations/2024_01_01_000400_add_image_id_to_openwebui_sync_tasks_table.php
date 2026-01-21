<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('openwebui_sync_tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('image_id')->nullable()->after('attachment_id');
        });
    }

    public function down(): void
    {
        Schema::table('openwebui_sync_tasks', function (Blueprint $table) {
            $table->dropColumn('image_id');
        });
    }
};
