<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * The upper end of a range may not be below its lower end - when both are given.
 *
 * Replaces Laravel's `gte:other`, which fails whenever the other field is
 * empty: "a maximum age of 35" or "no points beyond 30 days" with no lower
 * number was refused, though leaving the lower one blank is allowed and the
 * matching engine reads it as "no lower limit".
 *
 * The other field may use the same wildcards as this one (criteria.*.min_value
 * beside criteria.*.max_value). Where an update does not send it at all, the
 * stored value can be compared instead.
 */
class NotBelow implements DataAwareRule, ValidationRule
{
    private array $data = [];

    public function __construct(
        private readonly string $otherField,
        private readonly string $otherLabel,
        private readonly mixed $storedValue = null,
    ) {}

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $other = $this->resolve($attribute);
        $otherValue = Arr::has($this->data, $other) ? data_get($this->data, $other) : $this->storedValue;

        if ($otherValue === null || $otherValue === '' || ! is_numeric($otherValue) || ! is_numeric($value)) {
            return;
        }

        if ((float) $value < (float) $otherValue) {
            $fail("The :attribute must be the same as or more than the {$this->otherLabel}.");
        }
    }

    /** criteria.3.max_value beside criteria.*.min_value means criteria.3.min_value. */
    private function resolve(string $attribute): string
    {
        $segments = explode('.', $attribute);
        $pattern = explode('.', $this->otherField);

        foreach ($pattern as $i => $segment) {
            if ($segment === '*' && isset($segments[$i])) {
                $pattern[$i] = $segments[$i];
            }
        }

        return implode('.', $pattern);
    }
}
