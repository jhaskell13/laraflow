<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only history: one row per finished step attempt.
     *
     * The step row always reflects the latest attempt, so without this
     * table a retry would erase the diagnostics of the attempt it
     * replaces. Rows are written in the same transaction as the step
     * state change they describe, so history can never disagree with it.
     *
     * This is the table that grows under retry policies. It is pruned
     * by laraflow:prune under laraflow.retention.prune_attempts_after_days,
     * and the index on workflow_execution_id below is what lets that
     * scan ask "does this execution still have history?" without a
     * marker column.
     */
    public function up(): void
    {
        Schema::create('laraflow_step_attempts', function (Blueprint $table) {
            $table->id();

            // Denormalised from the step so history for a whole execution
            // can be read and pruned without a join.
            //
            // Not cascading on SQL Server: with the cascade that already
            // flows executions -> step_executions -> here, this second
            // path is one SQL Server refuses outright ("may cause cycles
            // or multiple cascade paths"). The step path covers every
            // row — step_execution_id is NOT NULL — and retention's
            // deletes never relied on cascades on any engine.
            $workflowFk = $table->foreignId('workflow_execution_id')
                ->constrained('laraflow_workflow_executions');

            if (Schema::getConnection()->getDriverName() !== 'sqlsrv') {
                $workflowFk->cascadeOnDelete();
            }

            $table->foreignId('step_execution_id')
                ->constrained('laraflow_step_executions')
                ->cascadeOnDelete();

            $table->unsignedInteger('attempt');

            // completed | failed | timed_out | interrupted
            $table->string('status');

            $table->text('error')->nullable();
            $table->string('error_class')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            // One record per logical attempt, enforced by the database:
            // a duplicate delivery cannot double-count an attempt.
            $table->unique([
                'step_execution_id',
                'attempt',
            ]);

            // Not redundant: PostgreSQL, SQLite, and SQL Server create
            // no index for a foreign key on their own, and the prune
            // scan and attemptHistory() both read by this column. On
            // MySQL and MariaDB the foreign key reuses this index
            // rather than creating a second one.
            $table->index('workflow_execution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraflow_step_attempts');
    }
};
