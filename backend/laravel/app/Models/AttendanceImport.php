<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A DTR file received from a client, and what became of each of its rows.
 *
 * Uploaded as a draft, checked against the employee list and the DTR already
 * on file, and only then imported - or discarded with nothing written.
 */
class AttendanceImport extends Model
{
    public const STATUSES = ['draft', 'completed', 'discarded'];

    protected $fillable = [
        'import_code',
        'client_company_id',
        'period_from',
        'period_to',
        'source_note',
        'original_filename',
        'file_disk',
        'file_path',
        'file_hash',
        'file_size_bytes',
        'column_mapping',
        'status',
        'total_rows',
        'imported_rows',
        'replaced_rows',
        'unchanged_rows',
        'skipped_rows',
        'error_rows',
        'results',
        'uploaded_by',
        'completed_by',
        'completed_at',
        'discarded_at',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'column_mapping' => 'array',
            'results' => 'array',
            'completed_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
