<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laraflow_step_executions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_execution_id')
                ->constrained('laraflow_workflow_executions')
                ->cascadeOnDelete();

            $table->string('step');
            $table->unsignedInteger('position');
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->string('error_class')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Deadline of the current attempt (started_at + the step
            // policy's timeout); null when the step declares no timeout.
            $table->timestamp('timeout_at')->nullable();

            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            // Also the index behind the foreign key and behind every
            // "steps of this execution" read, via its leftmost prefix.
            // PostgreSQL, SQLite, and SQL Server create no index for a
            // foreign key by themselves, so if these columns are ever
            // reordered, workflow_execution_id needs its own index.
            //
            // status is deliberately unindexed here: no query filters
            // step rows by status alone — progress reads aggregate over
            // workflow_execution_id, which this prefix serves.
            $table->unique([
                'workflow_execution_id',
                'position',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraflow_step_executions');
    }
};
