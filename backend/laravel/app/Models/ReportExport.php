<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_type',
        'filter_json',
        'file_path',
        'export_format',
        'status',
        'requested_by',
        'requested_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'filter_json' => 'array',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isReady(): bool
    {
        return $this->status === 'completed' && ! is_null($this->file_path);
    }
}
