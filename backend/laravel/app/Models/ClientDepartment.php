<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientDepartment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'client_company_id',
        'department_code',
        'department_name',
        'status',
        'created_by',
        'updated_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    public function jobRequests(): HasMany
    {
        return $this->hasMany(JobRequest::class, 'client_department_id');
    }

    /**
     * Everyone whose current placement is this department.
     *
     * Reads `current_department_id` on the employee, which the deployment and
     * reassignment services already maintain - so a worker moved between
     * departments leaves one list and appears in the other with no separate
     * bookkeeping. There is deliberately no join table: an employee has exactly
     * one current placement, and modelling it as many-to-many would invite two.
     *
     * Note this includes former employees, whose placement is a matter of
     * record even after they leave. Callers that mean "who is working here now"
     * want activeEmployees() below.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'current_department_id');
    }

    /**
     * The people actually working in this department today.
     *
     * Separate from employees() because "12 employees" on a client's screen has
     * to mean twelve people who turn up, not twelve who have ever been placed
     * here. Resigned and terminated staff stay reachable through employees(),
     * since a department that has lost everybody should read as empty rather
     * than as missing its history.
     */
    public function activeEmployees(): HasMany
    {
        return $this->employees()->where('employment_status', 'active');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
