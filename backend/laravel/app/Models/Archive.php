<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Archive extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'archive_reason',
        'snapshot_json',
        'archived_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            // Accented letters stored as themselves rather than as \u escapes,
            // so a search for "Peña" can find them (see ArchiveController).
            'snapshot_json' => 'json:unicode',
            'archived_at' => 'datetime',
        ];
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }
}
