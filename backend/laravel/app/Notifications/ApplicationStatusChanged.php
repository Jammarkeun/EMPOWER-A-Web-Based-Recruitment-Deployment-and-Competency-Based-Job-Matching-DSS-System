<?php

namespace App\Notifications;

use App\Models\Applicant;

/**
 * Tells an applicant their application has moved forward.
 *
 * Replaces the phone calls the agency currently fields asking "any update?" -
 * the single most common reason applicants ring the office.
 */
class ApplicationStatusChanged extends EmpowerNotification
{
    public function __construct(
        private readonly Applicant $applicant,
        private readonly string $toStatus,
    ) {
    }

    protected function category(): string
    {
        return 'application';
    }

    protected function title(): string
    {
        return match ($this->toStatus) {
            'initial_screening' => 'Your application is being screened',
            'primary_requirements_complete' => 'Your primary requirements are complete',
            'pending_final_requirements' => 'Medical requirements are now needed',
            'ready_for_deployment' => 'You are ready for deployment',
            'training_scheduled' => 'You have been scheduled for training',
            'training_completed' => 'Training completed',
            'client_evaluation' => 'Your profile has been endorsed to a client company',
            'approved' => 'You have been approved for placement',
            'deployed', 'active' => 'You have been deployed',
            default => 'Your application status has changed',
        };
    }

    protected function body(): string
    {
        return match ($this->toStatus) {
            'pending_final_requirements' => 'Please complete your medical examination and submit the results at the office.',
            'ready_for_deployment' => 'All your requirements are verified. You will be contacted once a placement is available.',
            'approved' => 'The client company has approved your application. Deployment details will follow.',
            'deployed', 'active' => 'Welcome. Your employment details are available in your profile.',
            default => 'You can view the details of your application in the portal.',
        };
    }

    protected function link(): ?string
    {
        return '/portal';
    }

    protected function severity(): string
    {
        return in_array($this->toStatus, ['ready_for_deployment', 'approved', 'deployed', 'active'], true)
            ? 'success'
            : 'info';
    }
}
