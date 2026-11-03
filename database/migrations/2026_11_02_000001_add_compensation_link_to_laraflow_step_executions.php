<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A compensation is an ordinary step row appended after the plan,
     * carrying a link to the row it undoes. That one decision is what
     * makes a saga retryable, attempt-tracked, and inspectable without
     * a line of new machinery — it *is* a step, so everything steps
     * already have applies (docs/advanced-workflows.md, "Compensation").
     *
     * The plan is then the rows where this column is null, which is how
     * plan validation keeps comparing like with like.
     *
     * A plain column, not a foreign key: a self-reference inside the
     * same table would add a second cascade path from the executions
     * row, which SQL Server refuses — the same reason
     * laraflow_step_attempts.workflow_execution_id does not cascade
     * there (docs/compatibility.md). The rows share a parent and are
     * deleted together by it, so nothing is orphaned.
     *
     * Indexed because the compensator asks "what has already been
     * undone" by it on every claim of a compensating execution.
     */
    public function up(): void
    {
        Schema::table('laraflow_step_executions', function (Blueprint $table) {
            $table->unsignedBigInteger('compensates_step_execution_id')->nullable()->after('position');

            $table->index('compensates_step_execution_id', 'laraflow_steps_compensates_index');
        });
    }

    public function down(): void
    {
        Schema::table('laraflow_step_executions', function (Blueprint $table) {
            $table->dropIndex('laraflow_steps_compensates_index');
            $table->dropColumn('compensates_step_execution_id');
        });
    }
};
