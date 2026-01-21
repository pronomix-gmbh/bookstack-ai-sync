<?php

declare(strict_types=1);

namespace Pronomix\BookStackOpenWebUISync\Models;

use Illuminate\Database\Eloquent\Model;

class OpenWebUISetting extends Model
{
    protected $table = 'openwebui_settings';

    protected $fillable = [
        'key',
        'value',
        'is_encrypted',
    ];

    protected $casts = [
        'is_encrypted' => 'bool',
    ];
}
