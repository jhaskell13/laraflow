<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per piece of idempotent work a step has begun — the
     * store behind WorkflowContext::remember().
     *
     * The marker row is inserted *before* the work runs and the value
     * written after, so a row left `pending` is positive evidence that
     * work started and no evidence that it finished: the one state the
     * application cannot tell apart from "never ran" on its own. The
     * unique key per execution is the concurrency guarantee — two live
     * workers in the same step both insert, exactly one succeeds, and
     * the other is told the outcome is uncertain rather than repeating
     * the side effect. See docs/idempotency.md.
     */
    public function up(): void
    {
        Schema::create('laraflow_idempotency_keys', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_execution_id')
                ->constrained('laraflow_workflow_executions')
                ->cascadeOnDelete();

            $table->unsignedInteger('step_position');

            // The fully scoped key: laraflow:{execution}:{position}:{key}.
            // 255 so the composite below fits every engine's key limit
            // with the same arithmetic the other string columns use.
            $table->string('key');

            // pending | settled | failed
            $table->string('status');

            $table->json('value')->nullable();
            $table->string('error_class')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['workflow_execution_id', 'key']);

            // Explicit for the same reason as the attempts table: not
            // every engine indexes a foreign key on its own, and both
            // the per-execution read and retention's scans use it.
            $table->index('workflow_execution_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraflow_idempotency_keys');
    }
};
