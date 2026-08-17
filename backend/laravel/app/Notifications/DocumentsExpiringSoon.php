<?php

namespace App\Notifications;

/**
 * Warns HR that verified documents are about to lapse.
 *
 * Expiry is otherwise silent: an applicant sits in the deployment-ready folder
 * while their police clearance quietly goes stale, and nobody discovers it until
 * the client asks for the paperwork.
 */
class DocumentsExpiringSoon extends EmpowerNotification
{
    public function __construct(
        private readonly int $expiringCount,
        private readonly int $alreadyExpiredCount,
        private readonly int $windowDays,
    ) {
    }

    protected function category(): string
    {
        return 'requirements';
    }

    protected function title(): string
    {
        if ($this->alreadyExpiredCount > 0 && $this->expiringCount === 0) {
            return $this->alreadyExpiredCount.' document(s) have expired';
        }

        return $this->expiringCount.' document(s) expiring within '.$this->windowDays.' days';
    }

    protected function body(): string
    {
        $parts = [];

        if ($this->expiringCount > 0) {
            $parts[] = $this->expiringCount.' will lapse in the next '.$this->windowDays.' days';
        }

        if ($this->alreadyExpiredCount > 0) {
            $parts[] = $this->alreadyExpiredCount.' have already lapsed and no longer count toward deployment readiness';
        }

        return ucfirst(implode('; ', $parts)).'.';
    }

    protected function link(): ?string
    {
        return '/applicants?expiring=1';
    }

    protected function severity(): string
    {
        return $this->alreadyExpiredCount > 0 ? 'warning' : 'info';
    }
}
