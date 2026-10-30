<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resignation requests from the portal, and separation notices to the employee.
 *
 * The resignation and termination tables already carried the agency's side of
 * a separation. What they could not say was the employee's side: what the
 * person asked for (as opposed to what HR confirmed), what they were told, and
 * whether they have seen a termination notice. Every column added here is one
 * of those, so the HR record is untouched and nothing is moved or rewritten.
 *
 * - resignations: who filed it and from where, the placement it concerns, the
 *   last working day the employee asked for, the employee's own note, the
 *   message HR writes for the employee, and who closed it and why.
 * - terminations: the message written for the employee, kept apart from the
 *   reason and remarks, which stay internal; when the notice was viewed and
 *   acknowledged; and the clearance, which CDE requires on every separation
 *   but which was only ever recorded for resignations. It is empty until the
 *   termination is finalised, and on terminations finalised before this.
 * - separation_events: the history of each case, with the entries the employee
 *   may see marked as such, so the portal shows a timeline without reading the
 *   audit log.
 *
 * `open_employee_id` is the database's own guard against two open resignations
 * for one person, in the same form as deployments.active_employee_id: a plain
 * column holding the employee's id while the case is open, under a unique
 * index that allows any number of empty values. It refuses to run, changing
 * nothing, if an employee already has two open resignations.
 */
return new class extends Migration
{
    private const OPEN = ['filed', 'accepted'];

    public function up(): void
    {
        $conflicts = DB::table('resignations')
            ->whereIn('status', self::OPEN)
            ->select('employee_id')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($conflicts > 0) {
            throw new RuntimeException(
                "{$conflicts} employee(s) have more than one open resignation. Complete or withdraw the "
                .'superseded one for each before running this migration. Nothing has been changed.'
            );
        }

        Schema::table('resignations', function (Blueprint $table) {
            // office | portal
            $table->string('source', 20)->default('office')->after('employee_id');
            $table->foreignId('filed_by')->nullable()->after('source')->constrained('users');
            $table->foreignId('deployment_id')->nullable()->after('filed_by')->constrained('deployments');

            // What the employee asked for. exit_date stays the date HR confirms.
            $table->date('requested_exit_date')->nullable()->after('exit_date');
            $table->text('employee_remarks')->nullable()->after('remarks');
            // Written by HR for the employee. remarks stays internal.
            $table->text('employee_message')->nullable()->after('employee_remarks');

            $table->foreignId('withdrawn_by')->nullable()->after('employee_message')->constrained('users');
            $table->timestamp('withdrawn_at')->nullable()->after('withdrawn_by');
            $table->string('withdrawal_reason', 255)->nullable()->after('withdrawn_at');

            $table->unsignedBigInteger('open_employee_id')->nullable();
        });

        DB::table('resignations')
            ->whereIn('status', self::OPEN)
            ->update(['open_employee_id' => DB::raw('employee_id')]);

        Schema::table('resignations', function (Blueprint $table) {
            $table->unique('open_employee_id', 'uq_resignations_one_open_per_employee');
        });

        Schema::table('terminations', function (Blueprint $table) {
            $table->text('employee_message')->nullable()->after('remarks');
            // pending | in_progress | cleared, from finalisation on.
            $table->string('clearance_status', 40)->nullable()->after('status');
            $table->uuid('notice_notification_id')->nullable()->after('withdrawal_reason');
            $table->timestamp('notice_viewed_at')->nullable()->after('notice_notification_id');
            $table->timestamp('notice_acknowledged_at')->nullable()->after('notice_viewed_at');
        });

        Schema::create('separation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // resignation | termination, and the row it belongs to.
            $table->string('case_type', 20);
            $table->unsignedBigInteger('case_id');

            // filed | info_requested | employee_responded | accepted | reopened |
            // exit_date_changed | clearance_changed | withdrawn | completed |
            // finalised | notice_viewed | notice_acknowledged
            $table->string('event', 40);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();

            // The words the employee sees with this entry, if any: HR's message
            // or the employee's own reply. Internal detail goes in `details`.
            $table->text('employee_note')->nullable();
            $table->json('details')->nullable();
            $table->boolean('visible_to_employee')->default(false);

            $table->foreignId('actor_id')->nullable()->constrained('users');
            // employee | staff
            $table->string('actor_role', 20);
            // Nullable: MariaDB would otherwise make it ON UPDATE CURRENT_TIMESTAMP.
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['case_type', 'case_id'], 'idx_separation_events_case');
            $table->index('employee_id', 'idx_separation_events_employee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('separation_events');

        Schema::table('terminations', function (Blueprint $table) {
            $table->dropColumn([
                'employee_message', 'clearance_status', 'notice_notification_id',
                'notice_viewed_at', 'notice_acknowledged_at',
            ]);
        });

        Schema::table('resignations', function (Blueprint $table) {
            $table->dropUnique('uq_resignations_one_open_per_employee');
            $table->dropConstrainedForeignId('filed_by');
            $table->dropConstrainedForeignId('deployment_id');
            $table->dropConstrainedForeignId('withdrawn_by');
            $table->dropColumn([
                'source', 'requested_exit_date', 'employee_remarks', 'employee_message',
                'withdrawn_at', 'withdrawal_reason', 'open_employee_id',
            ]);
        });
    }
};
