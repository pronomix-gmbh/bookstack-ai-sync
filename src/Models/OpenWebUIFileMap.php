<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Models;

use Illuminate\Database\Eloquent\Model;

class OpenWebUIFileMap extends Model
{
    protected $table = 'openwebui_file_map';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'book_id',
        'knowledge_id',
        'openwebui_file_id',
        'openwebui_filename',
        'external_key',
    ];
}
