<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CriteriaCatalog extends Model
{
    use HasFactory;

    protected $table = 'criteria_catalog';

    protected $fillable = [
        'criteria_code',
        'criteria_name',
        'criteria_type',
        'value_type',
        'score_direction',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function requestCriteria(): HasMany
    {
        return $this->hasMany(RequestCriteria::class, 'criteria_id');
    }

    public function companyCriteria(): HasMany
    {
        return $this->hasMany(CompanyCriteria::class, 'criteria_id');
    }

    public function isHardFilter(): bool
    {
        return $this->criteria_type === 'hard_filter';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Active criteria, plus any switched off since they were put to use here.
     *
     * Switching a criterion off stops it being added anywhere new, but where it
     * is already set it has to stay visible: hidden, it could be neither
     * reviewed nor taken off, yet would still be scored and saved.
     *
     * @param  iterable<int>  $inUseIds
     */
    public function scopeActiveOrInUse(Builder $query, iterable $inUseIds): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('is_active', true)->orWhereIn('id', collect($inUseIds)->all()));
    }
}
