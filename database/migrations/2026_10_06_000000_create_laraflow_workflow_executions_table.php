<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laraflow_workflow_executions', function (Blueprint $table) {
            $table->id();
            $table->string('workflow');
            $table->string('status')->index();
            $table->json('input')->nullable();
            $table->json('context')->nullable();
            $table->text('error')->nullable();
            $table->string('error_class')->nullable();
            $table->string('claim_token', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // When a step's retry policy schedules another attempt, the
            // due time is mirrored here from the retrying step so the
            // recovery scan stays a single-table indexed query. The step
            // row remains the authoritative per-attempt record.
            $table->timestamp('next_retry_at')->nullable();

            // Cancellation is cooperative for a running execution: the
            // request is recorded here and the owning worker performs the
            // transition at its next step boundary.
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->timestamps();

            // Supports finding stale running executions for reclaim/recovery.
            $table->index(['status', 'claimed_at']);

            // Supports finding retrying executions whose retry is due.
            $table->index(['status', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraflow_workflow_executions');
    }
};
