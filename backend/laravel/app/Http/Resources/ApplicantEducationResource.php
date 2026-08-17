<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ApplicantEducation
 */
class ApplicantEducationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'education_level' => $this->education_level,
            'education_label' => ucwords(str_replace('_', ' ', (string) $this->education_level)),
            'school_name' => $this->school_name,
            'course_program' => $this->course_program,
            'graduation_year' => $this->graduation_year,
            'honors' => $this->honors,
        ];
    }
}
