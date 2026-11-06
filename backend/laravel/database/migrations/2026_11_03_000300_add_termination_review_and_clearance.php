<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A termination becomes a two-stage case: HR requests it, an administrator
 * reviews it, and HR finalises it only after the pre-termination clearance.
 *
 * Until now the one row carried a single step - an administrator "finalised"
 * the request and the employee was terminated in the same moment, with
 * clearance recorded afterwards. The row stays the one record of the case;
 * what is added is what the review and the clearance need to say:
 *
 * - terminations: when it was submitted, the placement it concerns, who
 *   approved it and when (approved_by already existed, but held whoever
 *   finalised), the latest review decision and note, who finalised it, and
 *   the same one-open-case guard resignations have (open_employee_id).
 * - termination_clearance_items: the checklist HR completes between approval
 *   and finalisation, one row per item, with who verified it and when.
 * - separation.review_termination: approving, rejecting, or returning a
 *   request, held by administrators. separation.finalize_termination moves to
 *   HR as well, since finalising is now the processing step after approval.
 *
 * Existing rows are described, not reinterpreted. A termination finalised
 * under the one-step process was finalised by the administrator recorded in
 * approved_by, so that person is recorded as having finalised it; it is given
 * no approval date and no checklist, because neither existed. A request sent
 * for review was submitted when it was filed, since there was no other way to
 * reach that status. Nothing else is backfilled, and no case changes status.
 *
 * Refuses to run, changing nothing, if an employee already has two open
 * terminations.
 */
return new class extends Migration
{
    private const OPEN = ['draft', 'for_review'];

    public function up(): void
    {
        $conflicts = DB::table('terminations')
            ->whereIn('status', self::OPEN)
            ->select('employee_id')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($conflicts > 0) {
            throw new RuntimeException(
                "{$conflicts} employee(s) have more than one open termination. Withdraw the superseded "
                .'one for each before running this migration. Nothing has been changed.'
            );
        }

        Schema::table('terminations', function (Blueprint $table) {
            // The placement the request concerns, as it stood when filed.
            $table->foreignId('deployment_id')->nullable()->after('employee_id')->constrained('deployments');
            // Nullable throughout: MariaDB would otherwise make the first one
            // ON UPDATE CURRENT_TIMESTAMP.
            $table->timestamp('submitted_at')->nullable()->after('created_by');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            // The latest decision on the request - approve, reject, or return -
            // and the administrator's note. Each decision is also its own entry
            // in separation_events.
            $table->foreignId('reviewed_by')->nullable()->after('approved_at')->constrained('users');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_note', 1000)->nullable()->after('reviewed_at');
            $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users');

            $table->unsignedBigInteger('open_employee_id')->nullable();
        });

        DB::table('terminations')->where('status', 'finalized')->whereNotNull('approved_by')
            ->update(['finalized_by' => DB::raw('approved_by')]);

        DB::table('terminations')->where('status', 'for_review')->whereNull('submitted_at')
            ->update(['submitted_at' => DB::raw('created_at')]);

        DB::table('terminations')->whereIn('status', self::OPEN)
            ->update(['open_employee_id' => DB::raw('employee_id')]);

        Schema::table('terminations', function (Blueprint $table) {
            $table->unique('open_employee_id', 'uq_terminations_one_open_per_employee');
        });

        Schema::create('termination_clearance_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('termination_id')->constrained('terminations')->cascadeOnDelete();

            // Copied from the checklist in force when the request was approved,
            // so a later change to the checklist does not rewrite a case.
            $table->string('item_key', 60);
            $table->string('label', 150);
            // clearance | obligation | record_review
            $table->string('kind', 30);
            $table->string('description', 500)->nullable();
            $table->boolean('is_mandatory')->default(true);
            // Cleared only with a written note of what was verified, and with
            // whom: for items EMPOWER holds no record of its own to show.
            $table->boolean('requires_remarks')->default(false);

            // pending_review | requires_action | awaiting_verification |
            // cleared | not_applicable
            $table->string('status', 30)->default('pending_review');
            $table->text('remarks')->nullable();
            // A document number, the office that signed, a reference of any kind.
            $table->string('reference', 190)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->unique(['termination_id', 'item_key'], 'uq_termination_clearance_item');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table termination_clearance_items enable row level security');
        }

        $this->grantPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('termination_clearance_items');

        Schema::table('terminations', function (Blueprint $table) {
            $table->dropUnique('uq_terminations_one_open_per_employee');
            $table->dropConstrainedForeignId('deployment_id');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn(['submitted_at', 'approved_at', 'reviewed_at', 'review_note', 'open_employee_id']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::where('name', 'separation.review_termination')->delete();
        Role::where('name', 'hr')->where('guard_name', 'web')->first()
            ?->revokePermissionTo('separation.finalize_termination');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function grantPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::updateOrCreate(
            ['name' => 'separation.review_termination', 'guard_name' => 'web'],
            ['module_key' => 'separation', 'action_key' => 'review_termination',
                'description' => 'Approve, reject, or return a termination request'],
        );
        Permission::updateOrCreate(
            ['name' => 'separation.finalize_termination', 'guard_name' => 'web'],
            ['module_key' => 'separation', 'action_key' => 'finalize_termination',
                'description' => 'Complete the clearance and finalise an approved termination'],
        );

        Role::where('name', 'admin')->where('guard_name', 'web')->first()
            ?->givePermissionTo(['separation.review_termination', 'separation.finalize_termination']);
        Role::where('name', 'hr')->where('guard_name', 'web')->first()
            ?->givePermissionTo('separation.finalize_termination');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
