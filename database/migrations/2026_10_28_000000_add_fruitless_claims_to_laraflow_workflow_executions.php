<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The first migration after the schema freeze: additive, reversible,
     * and safe to run while workers hold claims.
     *
     * fruitless_claims counts consecutive claims on this execution that
     * ended without a single step-row write. claim() increments it; the
     * first step write under the claim zeroes it. Recovery reads it as
     * a second abandonment bound, because the step attempt counter —
     * the first bound — never moves for a worker that fails between
     * claiming and its first step: a listener throwing on the started
     * event, a definition that no longer matches the persisted plan.
     * Without this column such an execution is re-dispatched by every
     * recovery run forever.
     *
     * Not nullable: a row that predates the column has made no
     * fruitless claims, and 0 says so without a coalesce in the scan.
     */
    public function up(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->unsignedInteger('fruitless_claims')->default(0)->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->dropColumn('fruitless_claims');
        });
    }
};
