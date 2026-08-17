# Matching Engine Tool (Standalone)

This small utility demonstrates the rule-based Competency Scoring Engine used by EMPOWER. It is standalone PHP code and does not require Laravel to run.

Prerequisites:
- PHP 8.0+ installed and available on your PATH.

Run locally:

```bash
# from workspace root
php backend/tools/matching/run_matching.php backend/tools/matching/sample_job_request.json backend/tools/matching/sample_applicants.json
```

Output: JSON object with ranked results including breakdown per criterion, percentage score, hard-pass flag, and recommendation level.

Integration notes:
- The same `MatchingEngine` logic should be extracted into the Laravel `app/Services/MatchingService.php` for production and wired to DB models.
- The tool demonstrates tie-breaker ordering and hard-filter enforcement.
