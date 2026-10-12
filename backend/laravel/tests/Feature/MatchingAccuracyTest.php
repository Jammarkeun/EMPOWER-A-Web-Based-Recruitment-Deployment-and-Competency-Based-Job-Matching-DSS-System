<?php

namespace Tests\Feature;

use App\Models\ApplicantSkill;
use App\Models\CompanyCriteria;
use App\Models\CriteriaCatalog;
use App\Models\JobRequestMatch;
use App\Models\RequestCriteria;
use App\Services\Matching\CompetencyScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SeedsDomainData;
use Tests\TestCase;

/**
 * "Carefully craft the matching of applicants to the job posting."
 *
 * The review panel's words. These tests hold the engine to what that means in
 * practice: a client's list of required skills is compared item by item, the
 * requirements typed on the request reach the criteria, a ranking cannot
 * silently go stale, and the position an applicant applied for is visible
 * beside the score.
 */
class MatchingAccuracyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDomainData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
    }

    private function criterion(int $requestId, string $code, $actor, array $attributes): RequestCriteria
    {
        return RequestCriteria::create(array_merge([
            'job_request_id' => $requestId,
            'criteria_id' => CriteriaCatalog::where('criteria_code', $code)->firstOrFail()->id,
            'weight_score' => 0,
            'created_by' => $actor->id,
        ], $attributes));
    }

    private function withSkills($applicant, array $skills)
    {
        foreach ($skills as $skill) {
            ApplicantSkill::create(['applicant_id' => $applicant->id, 'skill_name' => $skill]);
        }

        return $applicant;
    }

    // ================================================ required skills, one by one

    public function test_skills_are_graded_by_how_many_of_the_required_ones_are_held(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $this->criterion($request->id, 'skills', $hr, [
            'weight_score' => 40,
            'expected_value' => 'Communication, Customer Service, Teamwork, Computer Literacy',
        ]);

        $three = $this->withSkills(
            $this->applicant($hr, ['first_name' => 'Three', 'current_status' => 'ready_for_deployment']),
            ['communication', 'Customer  Service', 'Teamwork', 'Forklift Operation']
        );
        $one = $this->withSkills(
            $this->applicant($hr, ['first_name' => 'One', 'current_status' => 'ready_for_deployment']),
            ['Teamwork']
        );

        app(CompetencyScoringService::class)->evaluate($request, $hr);

        $threeMatch = JobRequestMatch::where('applicant_id', $three->id)->firstOrFail();
        $oneMatch = JobRequestMatch::where('applicant_id', $one->id)->firstOrFail();

        // 3 of 4 earns three quarters of the points; 1 of 4 earns a quarter.
        // Under "any one will do" both used to score 100%.
        $this->assertEquals(75.0, (float) $threeMatch->percentage_score);
        $this->assertEquals(25.0, (float) $oneMatch->percentage_score);

        $skills = collect($threeMatch->breakdown_json['criteria'])->firstWhere('criteria', 'skills');
        $this->assertSame(['Communication', 'Customer Service', 'Teamwork'], $skills['details']['matched']);
        $this->assertSame(['Computer Literacy'], $skills['details']['missing']);
        $this->assertStringContainsString('Holds 3 of 4 required', $skills['explanation']);
        $this->assertStringContainsString('Missing: Computer Literacy', $skills['explanation']);
    }

    public function test_mandatory_skills_must_all_be_held(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $this->criterion($request->id, 'skills', $hr, [
            'mandatory_flag' => true,
            'expected_value' => 'Forklift Operation, Safety Procedures',
        ]);
        $this->criterion($request->id, 'experience', $hr, ['weight_score' => 10, 'min_value' => 0, 'max_value' => 12]);

        $both = $this->withSkills(
            $this->applicant($hr, ['current_status' => 'ready_for_deployment']),
            ['Forklift Operation', 'Safety Procedures']
        );
        $half = $this->withSkills(
            $this->applicant($hr, ['current_status' => 'ready_for_deployment']),
            ['Forklift Operation']
        );

        app(CompetencyScoringService::class)->evaluate($request, $hr);

        $this->assertTrue(JobRequestMatch::where('applicant_id', $both->id)->first()->hard_filter_pass);
        $this->assertFalse(JobRequestMatch::where('applicant_id', $half->id)->first()->hard_filter_pass);
    }

    public function test_certifications_remain_accepted_alternatives(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $this->criterion($request->id, 'certifications', $hr, [
            'weight_score' => 20,
            'expected_value' => 'Food Safety NC II, Food Processing NC II',
        ]);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        \App\Models\ApplicantCertification::create([
            'applicant_id' => $applicant->id,
            'certification_name' => 'Food Processing NC II',
            'issuer' => 'TESDA',
            'issued_at' => now()->subYear(),
            'expires_at' => now()->addYears(2),
        ]);

        app(CompetencyScoringService::class)->evaluate($request, $hr);

        $this->assertEquals(100.0, (float) JobRequestMatch::where('applicant_id', $applicant->id)->first()->percentage_score);
    }

    public function test_the_catalogue_says_how_each_list_is_read(): void
    {
        Sanctum::actingAs($hr = $this->hrUser());
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);

        $catalog = collect($this->getJson("/api/v1/job-requests/{$request->id}/criteria")->json('data.catalog'))
            ->keyBy('criteria_code');

        $this->assertSame('graded', $catalog['skills']['list_match']);
        $this->assertSame('any', $catalog['certifications']['list_match']);
    }

    // ========================================== the request's own requirements

    public function test_requirements_typed_on_the_request_are_proposed_as_criteria(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $company = $this->clientCompany($hr);
        $request = $this->jobRequest($this->department($company, $hr), $hr, [
            'age_min' => 18,
            'age_max' => 35,
            'height_min_cm' => 160,
            'gender_preference' => 'any',
            'required_education' => 'High School Graduate',
            'required_experience_months' => 6,
            'required_certifications' => 'Food Safety NC II',
            'physical_requirement' => 'Able to lift 25 kg',
        ]);

        // The client's standing weight for education is kept; the request
        // supplies this posting's level.
        CompanyCriteria::create([
            'client_company_id' => $company->id,
            'criteria_id' => CriteriaCatalog::where('criteria_code', 'education')->first()->id,
            'weight_score' => 30,
            'expected_value' => 'elementary',
            'created_by' => $hr->id,
        ]);

        $data = $this->getJson("/api/v1/job-requests/{$request->id}/criteria")->assertOk()->json('data');

        $fromRequest = collect($data['suggested_from_request'])->keyBy('criteria_code');
        $this->assertTrue($fromRequest['age']['mandatory_flag']);
        $this->assertEquals(18, $fromRequest['age']['min_value']);
        $this->assertEquals(35, $fromRequest['age']['max_value']);
        $this->assertEquals(160, $fromRequest['height']['min_value']);
        $this->assertSame('high_school', $fromRequest['education']['expected_value']);
        $this->assertEquals(6, $fromRequest['experience']['min_value']);
        $this->assertSame('Food Safety NC II', $fromRequest['certifications']['expected_value']);
        // "Any" is no preference, so no gender gate is proposed.
        $this->assertArrayNotHasKey('gender', $fromRequest->all());

        $merged = collect($data['suggested'])->keyBy('criteria_code');
        $this->assertEquals(30, $merged['education']['weight_score']);
        $this->assertSame('high_school', $merged['education']['expected_value']);
        $this->assertSame(['company', 'request'], $merged['education']['sources']);

        // Free text the engine cannot score is named, not silently dropped.
        $this->assertStringContainsString('Able to lift 25 kg', implode(' ', $data['unmapped_requirements']));
    }

    public function test_typed_education_is_read_as_a_ranked_level_or_reported(): void
    {
        $this->assertSame('high_school', CompetencyScoringService::normaliseEducation('High School Graduate'));
        $this->assertSame('senior_high_school', CompetencyScoringService::normaliseEducation('Senior High School graduate'));
        $this->assertSame('college_graduate', CompetencyScoringService::normaliseEducation("Bachelor's degree"));
        $this->assertSame('college_undergraduate', CompetencyScoringService::normaliseEducation('College level'));
        $this->assertSame('vocational', CompetencyScoringService::normaliseEducation('TESDA vocational course'));
        $this->assertNull(CompetencyScoringService::normaliseEducation('Must be literate'));
    }

    // ======================================================= no stale rankings

    public function test_people_who_leave_the_pool_leave_the_ranking(): void
    {
        $hr = $this->hrUser();
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);
        $this->criterion($request->id, 'experience', $hr, ['weight_score' => 10, 'min_value' => 0, 'max_value' => 12]);

        $leaving = $this->applicant($hr, ['first_name' => 'Leaving', 'current_status' => 'ready_for_deployment']);
        $shortlisted = $this->applicant($hr, ['first_name' => 'Kept', 'current_status' => 'ready_for_deployment']);
        $staying = $this->applicant($hr, ['first_name' => 'Staying', 'current_status' => 'ready_for_deployment']);

        $scoring = app(CompetencyScoringService::class);
        $scoring->evaluate($request, $hr);
        JobRequestMatch::where('applicant_id', $shortlisted->id)->update(['is_shortlisted' => true]);

        $leaving->forceFill(['current_status' => 'archived'])->save();
        $shortlisted->forceFill(['current_status' => 'archived'])->save();

        $scoring->evaluate($request, $hr);

        $this->assertDatabaseMissing('job_request_matches', ['applicant_id' => $leaving->id]);

        // The shortlist was a decision somebody made, so its record stays -
        // without a rank, because it is no longer a recommendation.
        $kept = JobRequestMatch::where('applicant_id', $shortlisted->id)->first();
        $this->assertNotNull($kept);
        $this->assertNull($kept->rank_order);

        $this->assertSame(1, JobRequestMatch::where('applicant_id', $staying->id)->first()->rank_order);

        Sanctum::actingAs($hr);
        $rows = $this->getJson("/api/v1/job-requests/{$request->id}/rankings")->assertOk()->json('data');

        // Ranked first, history after, on every database.
        $this->assertSame($staying->id, $rows[0]['applicant_id']);
        $this->assertTrue($rows[0]['in_current_pool']);
        $this->assertFalse($rows[1]['in_current_pool']);
    }

    public function test_a_ranking_is_flagged_when_the_criteria_change_after_it(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);
        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);
        $this->applicant($hr, ['current_status' => 'ready_for_deployment']);

        $save = fn () => $this->postJson("/api/v1/job-requests/{$request->id}/criteria", [
            'criteria' => [['criteria_code' => 'experience', 'weight_score' => 10, 'min_value' => 0, 'max_value' => 12]],
        ])->assertOk();

        $save();
        $this->travel(1)->minutes();
        $this->postJson("/api/v1/job-requests/{$request->id}/evaluate")->assertOk();

        $this->assertFalse($this->getJson("/api/v1/job-requests/{$request->id}")->json('data.evaluation.is_stale'));

        $this->travel(1)->minutes();
        $save();

        $this->assertTrue($this->getJson("/api/v1/job-requests/{$request->id}")->json('data.evaluation.is_stale'));
    }

    // ============================== recording what the applicant actually brings

    public function test_hr_records_qualifications_and_the_engine_scores_them(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);
        $this->criterion($request->id, 'education', $hr, ['weight_score' => 30, 'expected_value' => 'high_school']);
        $this->criterion($request->id, 'experience', $hr, ['weight_score' => 30, 'min_value' => 0, 'max_value' => 24]);
        $this->criterion($request->id, 'skills', $hr, ['weight_score' => 40, 'expected_value' => 'Machine Operation, Food Handling']);

        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);

        $this->postJson("/api/v1/job-requests/{$request->id}/evaluate")->assertOk();
        $this->assertEquals(0.0, (float) JobRequestMatch::where('applicant_id', $applicant->id)->value('percentage_score'));

        $this->travel(1)->minutes();
        $this->putJson("/api/v1/applicants/{$applicant->id}/qualifications", [
            'educations' => [['education_level' => 'senior_high_school', 'school_name' => 'Sta. Cruz NHS']],
            'experiences' => [['company_name' => 'Laguna Foods', 'position_title' => 'Packer', 'months_experience' => 12]],
            'skills' => ['Machine Operation', 'Food Handling'],
            'certifications' => [],
        ])->assertOk();

        // The ranking taken before the profile was recorded is now out of date.
        $this->assertTrue($this->getJson("/api/v1/job-requests/{$request->id}")->json('data.evaluation.is_stale'));
        $this->assertSame(1, $this->getJson("/api/v1/job-requests/{$request->id}")->json('data.evaluation.profiles_changed'));

        $this->travel(1)->minutes();
        $this->postJson("/api/v1/job-requests/{$request->id}/evaluate")->assertOk();

        // 30 (education met) + 15 (12 of 24 months) + 40 (both skills) of 100.
        $this->assertEquals(85.0, (float) JobRequestMatch::where('applicant_id', $applicant->id)->value('percentage_score'));
        $this->assertFalse($this->getJson("/api/v1/job-requests/{$request->id}")->json('data.evaluation.is_stale'));

        $this->assertDatabaseHas('audit_logs', ['module_key' => 'applicants', 'record_id' => $applicant->id]);
    }

    public function test_qualifications_are_validated_and_staff_only(): void
    {
        $hr = $this->hrUser();
        $applicant = $this->applicant($hr);

        Sanctum::actingAs($hr);
        $this->putJson("/api/v1/applicants/{$applicant->id}/qualifications", [
            'educations' => [['education_level' => 'High School Graduate', 'school_name' => 'X']],
            'experiences' => [],
            'skills' => ['Welding', 'welding'],
            'certifications' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['educations.0.education_level', 'skills.1']);

        $user = \App\Models\User::factory()->create([
            'email' => 'self.'.$applicant->id.'@example.test',
            'user_type' => 'applicant',
            'applicant_id' => $applicant->id,
        ]);
        $user->assignRole('portal');

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/applicants/{$applicant->id}/qualifications", [
            'educations' => [], 'experiences' => [], 'skills' => ['Everything'], 'certifications' => [],
        ])->assertForbidden();

        $this->assertDatabaseCount('applicant_skills', 0);
    }

    public function test_the_vocabulary_offers_names_used_on_both_sides(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);
        $this->criterion($request->id, 'skills', $hr, ['weight_score' => 10, 'expected_value' => 'Computer Literacy, Teamwork']);
        $this->withSkills($this->applicant($hr), ['teamwork', 'Forklift Operation']);

        $skills = $this->getJson('/api/v1/qualification-vocabulary')->assertOk()->json('data.skills');

        $this->assertContains('Computer Literacy', $skills);
        $this->assertContains('Forklift Operation', $skills);
        // One entry per skill however it was capitalised.
        $this->assertCount(1, array_filter($skills, fn ($s) => strtolower($s) === 'teamwork'));
    }

    public function test_editing_a_scored_detail_marks_rankings_stale_but_a_contact_change_does_not(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $request = $this->jobRequest($this->department($this->clientCompany($hr), $hr), $hr);
        $this->criterion($request->id, 'experience', $hr, ['weight_score' => 10, 'min_value' => 0, 'max_value' => 12]);
        $applicant = $this->applicant($hr, ['current_status' => 'ready_for_deployment']);
        $this->postJson("/api/v1/job-requests/{$request->id}/evaluate")->assertOk();

        $this->travel(1)->minutes();
        $this->putJson("/api/v1/applicants/{$applicant->id}", ['contact_number' => '0917 000 0000'])->assertOk();
        $this->assertFalse($this->getJson("/api/v1/job-requests/{$request->id}")->json('data.evaluation.is_stale'));

        $this->putJson("/api/v1/applicants/{$applicant->id}", ['communication_rating' => 4])->assertOk();
        $this->assertTrue($this->getJson("/api/v1/job-requests/{$request->id}")->json('data.evaluation.is_stale'));
    }

    // ===================================== what the applicant actually applied for

    public function test_the_ranking_shows_who_applied_for_this_position(): void
    {
        $hr = $this->hrUser();
        Sanctum::actingAs($hr);

        $company = $this->clientCompany($hr);
        $position = $this->jobPosition($company, $hr);
        $other = $this->jobPosition($company, $hr, ['position_title' => 'Warehouse Staff']);
        $request = $this->jobRequest($this->department($company, $hr), $hr, ['job_position_id' => $position->id]);
        $this->criterion($request->id, 'experience', $hr, ['weight_score' => 10, 'min_value' => 0, 'max_value' => 12]);

        $applied = $this->applicant($hr, ['current_status' => 'ready_for_deployment', 'preferred_position_id' => $position->id]);
        $elsewhere = $this->applicant($hr, ['current_status' => 'ready_for_deployment', 'preferred_position_id' => $other->id]);

        $rows = collect($this->postJson("/api/v1/job-requests/{$request->id}/evaluate")->assertOk()->json('data.ranked'))
            ->keyBy('applicant_id');

        $this->assertTrue($rows[$applied->id]['applied_for_this_position']);
        $this->assertFalse($rows[$elsewhere->id]['applied_for_this_position']);

        // Shown, never scored: CDE assesses the whole pool against a request.
        $this->assertEquals($rows[$applied->id]['percentage_score'], $rows[$elsewhere->id]['percentage_score']);
    }
}
