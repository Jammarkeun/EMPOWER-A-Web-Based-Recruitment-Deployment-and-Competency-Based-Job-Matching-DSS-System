<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('applicants.create');
    }

    public function rules(): array
    {
        return [
            'source_channel' => ['required', Rule::in(['walk_in', 'messenger', 'email', 'online'])],

            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:20'],

            'sex' => ['nullable', Rule::in(['male', 'female'])],
            // Rejects anyone under 15: below the minimum working age set by
            // RA 9231, so such a record should never be created at all.
            'birth_date' => ['nullable', 'date', 'before:'.now()->subYears(15)->toDateString()],
            'civil_status' => ['nullable', Rule::in(['single', 'married', 'widowed', 'separated', 'divorced'])],
            'nationality' => ['nullable', 'string', 'max:80'],
            'height_cm' => ['nullable', 'numeric', 'between:100,250'],
            'weight_kg' => ['nullable', 'numeric', 'between:30,300'],

            'contact_number' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'present_address' => ['required', 'string', 'max:255'],
            'provincial_address' => ['nullable', 'string', 'max:255'],

            /*
             * Staff pick the position from the same list applicants do.
             *
             * The free-text column stays accepted rather than removed: an
             * officer at the counter occasionally records something the list
             * does not cover yet, and refusing to write it down would lose
             * information the agency wanted. When an id is given it wins, and
             * the title is written from it.
             */
            'preferred_position_id' => ['nullable', 'integer', 'exists:job_positions,id'],
            'preferred_position' => ['nullable', 'string', 'max:150'],
            'availability_date' => ['nullable', 'date'],
            'distance_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'communication_rating' => ['nullable', 'numeric', 'between:0,5'],
            'reliability_rating' => ['nullable', 'numeric', 'between:0,5'],

            'application_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'birth_date.before' => 'The applicant must be at least 15 years old.',
            'application_date.before_or_equal' => 'The application date cannot be in the future.',
            'present_address.required' => 'A present address is required so the distance criterion can be scored.',
        ];
    }
}
