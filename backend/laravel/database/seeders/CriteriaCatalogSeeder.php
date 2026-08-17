<?php

namespace Database\Seeders;

use App\Models\CriteriaCatalog;
use Illuminate\Database\Seeder;

/**
 * The criteria HR can attach to a manpower request.
 *
 * The catalogue defines what each criterion means and how it is measured; the
 * weight is not fixed here because it varies per request. A production helper
 * posting weights reliability heavily, while a quality control role weights
 * education and certification instead.
 */
class CriteriaCatalogSeeder extends Seeder
{
    private const CRITERIA = [
        [
            'code' => 'education',
            'name' => 'Educational Attainment',
            'type' => 'weighted_scale',
            'value' => 'enum',
            'direction' => 'higher_better',
            'description' => "The applicant's highest completed level of education, compared against the level the request asks for. Higher attainment also satisfies a lower requirement.",
        ],
        [
            'code' => 'experience',
            'name' => 'Relevant Work Experience',
            'type' => 'weighted_scale',
            'value' => 'number',
            'direction' => 'higher_better',
            'description' => 'Total months of prior employment recorded on the applicant profile, graded against the range set on the request.',
        ],
        [
            'code' => 'certifications',
            'name' => 'Certifications',
            'type' => 'weighted_binary',
            'value' => 'text',
            'direction' => 'higher_better',
            'description' => 'Whether the applicant holds any of the certifications the request accepts, such as TESDA NC II. Expired certificates do not count.',
        ],
        [
            'code' => 'skills',
            'name' => 'Skills Match',
            'type' => 'weighted_binary',
            'value' => 'text',
            'direction' => 'higher_better',
            'description' => 'Whether the applicant lists any of the skills named on the request.',
        ],
        [
            'code' => 'availability',
            'name' => 'Availability to Start',
            'type' => 'weighted_scale',
            'value' => 'number',
            'direction' => 'lower_better',
            'description' => 'Days until the applicant can begin work. Someone available immediately scores highest.',
        ],
        [
            'code' => 'distance',
            'name' => 'Distance from Worksite',
            'type' => 'weighted_scale',
            'value' => 'number',
            'direction' => 'lower_better',
            'description' => 'Kilometres between the applicant\'s address and the worksite. Nearer applicants score higher because they are markedly less likely to be absent or resign over commuting cost.',
        ],
        [
            'code' => 'height',
            'name' => 'Height Requirement',
            'type' => 'hard_filter',
            'value' => 'number',
            'direction' => 'higher_better',
            'description' => 'Minimum height in centimetres, where a client role genuinely requires it.',
        ],
        [
            'code' => 'gender',
            'name' => 'Gender Preference',
            'type' => 'hard_filter',
            'value' => 'enum',
            'direction' => 'higher_better',
            'description' => "The client's stated preference. Set to \"any\" unless the role has a genuine occupational requirement.",
        ],
        [
            'code' => 'age',
            'name' => 'Age Range',
            'type' => 'hard_filter',
            'value' => 'number',
            'direction' => 'higher_better',
            'description' => 'The age bracket set on the request, checked against the applicant\'s date of birth.',
        ],
        [
            'code' => 'communication',
            'name' => 'Communication Skills',
            'type' => 'weighted_scale',
            'value' => 'enum',
            'direction' => 'higher_better',
            'description' => 'Interview rating for communication, scored through the rubric configured on the request.',
        ],
        [
            'code' => 'reliability',
            'name' => 'Reliability',
            'type' => 'weighted_scale',
            'value' => 'enum',
            'direction' => 'higher_better',
            'description' => 'Interview rating for dependability and punctuality, scored through the rubric configured on the request.',
        ],
    ];

    public function run(): void
    {
        foreach (self::CRITERIA as $criterion) {
            CriteriaCatalog::updateOrCreate(
                ['criteria_code' => $criterion['code']],
                [
                    'criteria_name' => $criterion['name'],
                    'criteria_type' => $criterion['type'],
                    'value_type' => $criterion['value'],
                    'score_direction' => $criterion['direction'],
                    'description' => $criterion['description'],
                    'is_active' => true,
                ]
            );
        }
    }
}
