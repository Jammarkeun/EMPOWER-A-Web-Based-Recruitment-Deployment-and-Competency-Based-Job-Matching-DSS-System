<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One document a client company has on file with CDE, under the title the
 * uploader gave it.
 *
 * Titles are free text on purpose: a company's papers are whatever that
 * company has, not entries from a fixed checklist.
 */
class ClientCompanyDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'client_company_id',
        'title',
        'description',
        'issued_on',
        'expires_on',
        'file_path',
        'file_name',
        'file_mime',
        'file_size_bytes',
        'file_hash',
        'uploaded_by',
        'uploaded_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /** The storage key stays on the server. */
    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'uploaded_at' => 'datetime',
            'file_size_bytes' => 'integer',
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

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Past its own expiry date. A document with no expiry date never is: an
     * unknown date is not treated as a lapsed one.
     */
    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(today());
    }
}
