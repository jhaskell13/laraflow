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

            // Application-supplied identifiers tying this execution to the
            // request, job, tenant, or external operation that caused it.
            // Indexed columns rather than a metadata blob because listing
            // by them must stay a bounded, indexed query on every engine.
            $table->string('correlation_id')->nullable();
            $table->string('tenant_id')->nullable();

            // dispatched_at: when the execution was handed to the queue.
            // available_at: when the queue was asked to make it runnable —
            // equal to dispatched_at for an immediate push, later for a
            // delayed retry. Queue lag is claimed_at minus available_at,
            // which is meaningless without the delay distinction.
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('available_at')->nullable();

            // The single terminal timestamp: set on completed, failed, and
            // cancelled alike, cleared when the execution runs again.
            // Retention and "finished between" filters would otherwise need
            // a three-way coalesce that no index can serve.
            $table->timestamp('finished_at')->nullable();

            // Computed when the execution finishes, while both instants
            // are still in memory. The timestamp columns hold whole
            // seconds, so a duration derived from them after a reload
            // reports 0 ms for anything under a second — which is most
            // executions. The attempt history has stored its duration
            // for the same reason since Milestone 3.
            $table->unsignedBigInteger('duration_ms')->nullable();

            // When retention removed this execution's input, context, and
            // step outputs. The execution record itself survives as an
            // audit trail; the payloads it carried do not.
            $table->timestamp('payloads_scrubbed_at')->nullable();

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

            // Supports listing and retention scans: correlating an
            // execution with an application request or tenant, filtering
            // a workflow's executions by status, paginating by id within
            // a creation window, and finding terminal work old enough to
            // prune. Every supported listing filter has an index behind
            // it, because an unbounded table scan is how observability
            // tooling takes an application down.
            $table->index('correlation_id');
            $table->index('tenant_id');
            $table->index('created_at');
            $table->index(['workflow', 'status']);
            $table->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraflow_workflow_executions');
    }
};
