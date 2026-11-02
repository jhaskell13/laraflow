<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A fan-out step asks how its members are doing — counts by status
     * for every child of one parent *step* — on every heartbeat, and a
     * group may be a thousand wide. parent_step_execution_id was left
     * unindexed in Milestone 7 because it was only ever read for one
     * known child, found through the parent_execution_id index and a
     * comparison in PHP; a thousand members read that way is a thousand
     * rows walked to answer "are you done". This index lets the
     * repository's childProgress() aggregate answer in one indexed
     * query, which is the whole reason fan-out can afford to heartbeat
     * (docs/advanced-workflows.md, "Fan-out and fan-in").
     *
     * An index build is not online on PostgreSQL inside a migration
     * transaction, nor on SQL Server outside Enterprise; see
     * docs/upgrading.md.
     */
    public function up(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->index('parent_step_execution_id', 'laraflow_executions_join_index');
        });
    }

    public function down(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->dropIndex('laraflow_executions_join_index');
        });
    }
};
