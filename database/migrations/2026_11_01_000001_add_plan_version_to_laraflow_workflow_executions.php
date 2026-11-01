<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which version of its workflow's plan this execution is running.
     *
     * Workflow::version() defaults to 1, so a row that predates this
     * column reads as version 1, which is exactly what it is. A
     * declared plan migration may carry an execution forward from the
     * version recorded here to the version the code now declares; see
     * docs/workflow-versioning.md.
     *
     * Unindexed: it is read per claimed execution, never scanned.
     */
    public function up(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->unsignedInteger('plan_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->dropColumn('plan_version');
        });
    }
};
