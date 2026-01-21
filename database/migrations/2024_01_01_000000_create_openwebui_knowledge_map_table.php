<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('openwebui_knowledge_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('book_id')->unique();
            $table->string('book_slug')->nullable();
            $table->string('knowledge_id');
            $table->string('knowledge_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('openwebui_knowledge_map');
    }
};
