<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A runtime override of a config value.
 *
 * Read through SettingsService rather than directly, so the config fallback and
 * the cache are applied consistently.
 */
class SystemSetting extends Model
{
    protected $fillable = ['key', 'value', 'group', 'updated_by'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /**
     * Scalars are stored inside a JSON column, so they are wrapped on write and
     * unwrapped on read. Without this a plain integer round-trips as [0 => 30].
     */
    public function setValueAttribute($value): void
    {
        $this->attributes['value'] = json_encode(['v' => $value]);
    }

    public function getValueAttribute($value)
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) && array_key_exists('v', $decoded) ? $decoded['v'] : $decoded;
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
