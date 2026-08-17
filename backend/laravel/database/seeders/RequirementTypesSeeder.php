<?php

namespace Database\Seeders;

use App\Models\RequirementType;
use Illuminate\Database\Seeder;

/**
 * The documents CDE Manpower Services collects, split into the two batches the
 * agency actually works in.
 *
 * Primary requirements are gathered during screening. Final requirements are the
 * medical battery, deferred until an applicant is close to placement because the
 * tests cost money and expire.
 */
class RequirementTypesSeeder extends Seeder
{
    private const REQUIREMENTS = [
        // code, name, group, required, expires
        ['resume', 'Resume', 'primary', true, false],
        ['bio_data', 'Bio-data', 'primary', true, false],
        ['birth_certificate', 'PSA Birth Certificate', 'primary', true, false],
        ['diploma', 'Diploma / Certificate of Graduation', 'primary', true, false],
        ['sss', 'SSS Number / E-1 Form', 'primary', true, false],
        ['philhealth', 'PhilHealth Number', 'primary', true, false],
        ['pagibig', 'Pag-IBIG Number', 'primary', true, false],
        ['police_clearance', 'Police Clearance', 'primary', true, true],
        ['barangay_clearance', 'Barangay Clearance', 'primary', true, true],
        ['coe', 'Certificate of Employment', 'primary', false, false],
        ['marriage_certificate', 'Marriage Certificate', 'primary', false, false],
        ['id_photo', '2x2 ID Picture', 'primary', true, false],

        ['drug_test', 'Drug Test Result', 'final', true, true],
        ['urine_test', 'Urinalysis Result', 'final', true, true],
        ['stool_test', 'Fecalysis Result', 'final', true, true],
        ['hepatitis_b', 'Hepatitis B Screening', 'final', true, true],
        ['health_card', 'Health Card', 'final', true, true],
        ['medical_result', 'Medical Examination Result', 'final', true, true],
    ];

    public function run(): void
    {
        foreach (self::REQUIREMENTS as $index => [$code, $name, $group, $required, $expires]) {
            RequirementType::updateOrCreate(
                ['requirement_code' => $code],
                [
                    'requirement_name' => $name,
                    'requirement_group' => $group,
                    'is_required' => $required,
                    'has_expiry' => $expires,
                    'active_flag' => true,
                    'display_order' => $index + 1,
                ]
            );
        }
    }
}
