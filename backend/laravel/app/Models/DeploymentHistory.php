<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentHistory extends Model
{
    use HasFactory;

    protected $table = 'deployment_history';

    protected $fillable = [
        'deployment_id',
        'from_company_id',
        'from_department_id',
        'from_position_title',
        'to_company_id',
        'to_department_id',
        'to_position_title',
        'change_type',
        'effective_date',
        'remarks',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'from_company_id');
    }

    public function toCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'to_company_id');
    }

    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(ClientDepartment::class, 'from_department_id');
    }

    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(ClientDepartment::class, 'to_department_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
