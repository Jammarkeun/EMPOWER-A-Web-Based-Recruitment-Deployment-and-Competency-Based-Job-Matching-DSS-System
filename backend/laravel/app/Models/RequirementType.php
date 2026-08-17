<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RequirementType extends Model
{
    use HasFactory;

    protected $fillable = [
        'requirement_code',
        'requirement_name',
        'requirement_group',
        'is_required',
        'has_expiry',
        'active_flag',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'has_expiry' => 'boolean',
            'active_flag' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    public function applicantRequirements(): HasMany
    {
        return $this->hasMany(ApplicantRequirement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active_flag', true);
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('requirement_group', 'primary');
    }

    public function scopeFinal(Builder $query): Builder
    {
        return $query->where('requirement_group', 'final');
    }
}
