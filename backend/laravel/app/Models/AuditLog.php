<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of a consequential action.
 *
 * Audit rows are written by AuditService and are never updated or deleted from
 * application code; an audit trail that can be edited is not an audit trail.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'actor_user_id',
        'action_type',
        'module_key',
        'record_type',
        'record_id',
        'old_values_json',
        'new_values_json',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'old_values_json' => 'array',
            'new_values_json' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function scopeForRecord(Builder $query, string $type, int|string $id): Builder
    {
        return $query->where('record_type', $type)->where('record_id', $id);
    }
}
