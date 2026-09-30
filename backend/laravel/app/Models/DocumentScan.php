<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentScan extends Model
{
    protected $fillable = [
        'user_id',
        'stored_path',
        'original_name',
        'mime_type',
        'document_type',
        'status',
        'result',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
