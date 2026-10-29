<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A child execution's link to the parent that spawned it: the parent
     * execution and the exact step row waiting on it. Plain columns, not
     * foreign keys, on purpose: retention may delete a long-finished
     * parent while its children's records live on, and a dangling id in
     * an audit record is the documented trade-off — a constraint would
     * either forbid that prune or cascade it (docs/advanced-workflows.md).
     *
     * parent_execution_id is indexed for the parent filter and the
     * cancellation cascade. parent_step_execution_id is not: it is only
     * ever read within one parent's already-bounded children.
     */
    public function up(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_execution_id')->nullable()->after('tenant_id');
            $table->unsignedBigInteger('parent_step_execution_id')->nullable()->after('parent_execution_id');

            $table->index('parent_execution_id');
        });
    }

    public function down(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->dropIndex(['parent_execution_id']);
            $table->dropColumn(['parent_execution_id', 'parent_step_execution_id']);
        });
    }
};
