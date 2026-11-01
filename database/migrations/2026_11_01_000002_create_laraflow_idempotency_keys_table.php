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

            // The key as the step wrote it. 255 so the unique index
            // below fits every engine's key limit with the same
            // arithmetic the other string columns use.
            $table->string('key');

            // pending | settled | failed
            $table->string('status');

            $table->json('value')->nullable();
            $table->string('error_class')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            // Scoped to the step by the index, not by the string: two
            // steps may both remember 'charge'. Leading with the
            // execution id, this one index also serves every read by
            // execution — the count behind the cap, the show command,
            // retention's scrub and orphan deletes — so the foreign key
            // needs no index of its own on any engine. Named explicitly:
            // the name Laravel would generate is 72 characters, and
            // MySQL and MariaDB refuse identifiers past 64.
            $table->unique(['workflow_execution_id', 'step_position', 'key'], 'laraflow_idempotency_keys_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraflow_idempotency_keys');
    }
};
