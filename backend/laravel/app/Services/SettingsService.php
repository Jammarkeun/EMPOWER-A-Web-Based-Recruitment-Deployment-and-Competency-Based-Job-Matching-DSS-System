<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime configuration, with the config files as the fallback.
 *
 * Reads go through a cache because these values are consulted on nearly every
 * request — folder categorisation reads the expiry rules, the competency engine
 * reads the recommendation bands — and a database round trip for each would be
 * wasteful. Writes clear the cache immediately, so a change made in the settings
 * screen takes effect on the next request rather than after a restart.
 *
 * Anything not overridden falls back to config(), which means a fresh install
 * with an empty settings table behaves exactly as the shipped defaults.
 */
class SettingsService
{
    private const CACHE_KEY = 'empower.settings';
    private const CACHE_TTL = 3600;

    /**
     * The values as they stand in the config files, captured before any override
     * is written over them.
     *
     * Needed because set() also writes into the live config so a change applies
     * within the same request. Without this snapshot, resetting a setting would
     * delete the stored row and then fall back to config() — which still holds
     * the overridden value, leaving the "default" permanently wrong until the
     * process restarted.
     *
     * @var array<string, mixed>
     */
    private array $pristine = [];

    /**
     * The settings that may be edited at runtime, with the config key each one
     * overrides and how it should be validated.
     *
     * Anything absent from this list cannot be written, so a crafted request
     * cannot reach an arbitrary config path.
     */
    public const EDITABLE = [
        // --- competency scoring ---
        'empower.recommendation_bands.highly_recommended' => [
            'group' => 'competency',
            'label' => 'Highly recommended, at or above',
            'help' => 'Percentage at which a candidate is presented as highly recommended.',
            'type' => 'integer',
            'rules' => ['integer', 'between:1,100'],
            'unit' => '%',
        ],
        'empower.recommendation_bands.recommended' => [
            'group' => 'competency',
            'label' => 'Recommended, at or above',
            'help' => 'Percentage at which a candidate is presented as recommended.',
            'type' => 'integer',
            'rules' => ['integer', 'between:1,100'],
            'unit' => '%',
        ],
        'empower.recommendation_bands.reserve_pool' => [
            'group' => 'competency',
            'label' => 'Reserve pool, at or above',
            'help' => 'Below this, a candidate is shown as not recommended.',
            'type' => 'integer',
            'rules' => ['integer', 'between:1,100'],
            'unit' => '%',
        ],

        // --- documents ---
        'empower.uploads.max_size_kb' => [
            'group' => 'documents',
            'label' => 'Maximum document size',
            'help' => 'Applies to every uploaded requirement and evidence file.',
            'type' => 'integer',
            'rules' => ['integer', 'between:512,20480'],
            'unit' => 'KB',
        ],
        'empower.uploads.signed_url_ttl_minutes' => [
            'group' => 'documents',
            'label' => 'Document link lifetime',
            'help' => 'How long a download link stays valid before it expires. Shorter is safer.',
            'type' => 'integer',
            'rules' => ['integer', 'between:1,120'],
            'unit' => 'minutes',
        ],
        'empower.expiry_warning_days' => [
            'group' => 'documents',
            'label' => 'Warn about expiring documents',
            'help' => 'How far ahead the daily check looks for clearances and medicals about to lapse.',
            'type' => 'integer',
            'rules' => ['integer', 'between:1,180'],
            'unit' => 'days ahead',
        ],

        /*
         * --- disciplinary policy ---
         *
         * The client's handbook, not the system's rules. CDE confirmed a
         * one-year record and a fourth-offence threshold, and both are the sort
         * of thing an agency changes without telling its developer.
         */
        'empower.violations.active_window_months' => [
            'group' => 'policy',
            'label' => 'Violations stay on the active record for',
            'help' => 'After this, an offence stays in the history and in reports but stops counting towards the threshold.',
            'type' => 'integer',
            'rules' => ['integer', 'between:1,120'],
            'unit' => 'months',
        ],
        'empower.violations.termination_threshold' => [
            'group' => 'policy',
            'label' => 'Flag for review at',
            'help' => 'Offences within the active window at which an employee is raised for an administrator to review. Reaching it never terminates anybody by itself.',
            'type' => 'integer',
            'rules' => ['integer', 'between:1,20'],
            'unit' => 'offences',
        ],
        'empower.require_training_before_deployment' => [
            'group' => 'policy',
            'label' => 'Require training before deployment',
            'help' => 'CDE trains every worker before placing them. Switch off only to redeploy somebody already trained.',
            'type' => 'boolean',
            'rules' => ['boolean'],
        ],

        // --- retention ---
        'empower.retention.unhired_applicant_months' => [
            'group' => 'retention',
            'label' => 'Offer unhired applicants for archiving after',
            'help' => 'A prompt to review, never an automatic deletion. Nothing is removed without somebody archiving it.',
            'type' => 'integer',
            'rules' => ['integer', 'between:1,120'],
            'unit' => 'months',
        ],
        'empower.retention.legal_document_years' => [
            'group' => 'retention',
            'label' => 'Keep legal documents for at least',
            'help' => 'CDE keeps these five to ten years. This is the point at which a decision becomes due, not a deletion date.',
            'type' => 'integer',
            'rules' => ['integer', 'between:1,30'],
            'unit' => 'years',
        ],

        // --- organisation, printed on every report ---
        'empower.organisation.name' => [
            'group' => 'organisation',
            'label' => 'Agency name',
            'help' => 'Appears in the header of every exported report.',
            'type' => 'string',
            'rules' => ['string', 'max:120'],
        ],
        'empower.organisation.address' => [
            'group' => 'organisation',
            'label' => 'Office address',
            'help' => 'Appears beneath the agency name on reports.',
            'type' => 'string',
            'rules' => ['string', 'max:190'],
        ],
        'empower.organisation.contact' => [
            'group' => 'organisation',
            'label' => 'Contact number',
            'help' => 'Shown to applicants in the portal.',
            'type' => 'string',
            'rules' => ['nullable', 'string', 'max:60'],
        ],

        // --- document reading ---
        'ocr.enabled' => [
            'group' => 'system',
            'label' => 'Automatic document reading',
            'help' => 'Lets HR fill an applicant form by uploading a resume or ID. Turn off if the reading service is unavailable.',
            'type' => 'boolean',
            'rules' => ['boolean'],
        ],
        'ocr.review_threshold' => [
            'group' => 'system',
            'label' => 'Flag read values below',
            'help' => 'Values read with less confidence than this are marked for checking. Names and addresses normally fall below 0.7.',
            'type' => 'decimal',
            'rules' => ['numeric', 'between:0,1'],
        ],
    ];

    /**
     * Read a setting, falling back to the config default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $overrides = $this->all();

        return $overrides[$key] ?? config($key, $default);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => SystemSetting::pluck('value', 'key')->all()
        );
    }

    /**
     * Write one setting and apply it to the running request immediately.
     */
    public function set(string $key, mixed $value): void
    {
        abort_unless(
            array_key_exists($key, self::EDITABLE),
            400,
            "The setting \"{$key}\" cannot be changed."
        );

        $this->rememberDefault($key);

        SystemSetting::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'group' => self::EDITABLE[$key]['group'],
                'updated_by' => Auth::id(),
            ]
        );

        Cache::forget(self::CACHE_KEY);

        // Applied to the live config as well, so code that reads config()
        // directly sees the new value without waiting for the next request.
        config([$key => $value]);
    }

    /**
     * The value as shipped in the config files, before any override.
     */
    public function defaultFor(string $key): mixed
    {
        return array_key_exists($key, $this->pristine) ? $this->pristine[$key] : config($key);
    }

    private function rememberDefault(string $key): void
    {
        if (! array_key_exists($key, $this->pristine)) {
            $this->pristine[$key] = config($key);
        }
    }

    /**
     * Overrides plus their config defaults, shaped for the settings screen.
     */
    public function forDisplay(): array
    {
        $overrides = $this->all();
        $grouped = [];

        foreach (self::EDITABLE as $key => $meta) {
            $grouped[$meta['group']][] = [
                'key' => $key,
                'label' => $meta['label'],
                'help' => $meta['help'],
                'type' => $meta['type'],
                'unit' => $meta['unit'] ?? null,
                'value' => $overrides[$key] ?? config($key),
                // Lets the screen show which values have been changed from the
                // shipped default, and offer to put them back.
                'is_overridden' => array_key_exists($key, $overrides),
                'default' => $this->defaultFor($key),
            ];
        }

        return $grouped;
    }

    /**
     * Remove an override, returning the setting to its shipped default.
     */
    public function reset(string $key): void
    {
        SystemSetting::where('key', $key)->delete();
        Cache::forget(self::CACHE_KEY);

        // Put the shipped value back into the live config. Deleting the row
        // alone is not enough: set() wrote the override into config(), so the
        // fallback would otherwise keep returning it.
        config([$key => $this->defaultFor($key)]);
    }

    /**
     * Push every stored override into the live config.
     *
     * Called once per request from a service provider, so the rest of the
     * application can keep reading config() and still see runtime changes.
     */
    public function applyToConfig(): void
    {
        foreach ($this->all() as $key => $value) {
            // Captured before the override lands, so the shipped value is still
            // recoverable when the setting is later reset.
            $this->rememberDefault($key);
            config([$key => $value]);
        }
    }
}
