<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientCompany extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_code',
        'company_name',
        'business_type',
        'contact_person',
        'contact_number',
        'email',
        'office_address',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    public function departments(): HasMany
    {
        return $this->hasMany(ClientDepartment::class);
    }

    public function jobRequests(): HasMany
    {
        return $this->hasMany(JobRequest::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'current_client_company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
