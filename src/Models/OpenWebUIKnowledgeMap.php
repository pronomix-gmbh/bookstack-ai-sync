<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Models;

use Illuminate\Database\Eloquent\Model;

class OpenWebUIKnowledgeMap extends Model
{
    protected $table = 'openwebui_knowledge_map';

    protected $fillable = [
        'book_id',
        'book_slug',
        'knowledge_id',
        'knowledge_name',
    ];
}
