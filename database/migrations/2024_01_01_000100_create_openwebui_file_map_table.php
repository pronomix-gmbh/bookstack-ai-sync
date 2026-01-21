<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('openwebui_file_map', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('book_id');
            $table->string('knowledge_id');
            $table->string('openwebui_file_id');
            $table->string('openwebui_filename')->nullable();
            $table->string('external_key')->unique();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openwebui_file_map');
    }
};
