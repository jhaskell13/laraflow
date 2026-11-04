<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signals that arrived before anything waited on them — the durable
     * inbox behind WorkflowRunner::signal() (docs/advanced-workflows.md).
     *
     * A signal for an unfinished execution that is not, at that moment,
     * parked on the name used to be refused, so an approval posted while
     * the step that asks for it was still running was lost to a race the
     * sender could not see. It is kept here instead, and the park that
     * asks for it consumes it in the same transaction and resumes at
     * once. At most one row per execution and name: a later signal
     * overwrites the earlier one — latest wins, as it does for a signal
     * already on a waiting step — so the inbox is bounded by the names
     * a sender uses, never by how often it sends.
     *
     * The unique key is on a SHA-256 of the name, not the name itself:
     * MySQL, MariaDB, and SQL Server compare strings case-insensitively
     * by default, and `Approve` and `approve` are two signals. The name
     * is kept beside it, and matched byte for byte in PHP, for the same
     * reason wait_signal is.
     *
     * Leading with the execution id, the unique index also serves every
     * read by execution — the park's lookup, retention's orphan delete —
     * so the foreign key needs no index of its own. The cascade is
     * declared; retention deletes orphans itself regardless, as it does
     * for every child table, because SQLite enforces it only when asked.
     */
    public function up(): void
    {
        Schema::create('laraflow_signal_inbox', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workflow_execution_id')
                ->constrained('laraflow_workflow_executions')
                ->cascadeOnDelete();

            // As the sender wrote it; 255, the bound every signal name
            // already has (Wait::MAX_SIGNAL_LENGTH).
            $table->string('name');
            $table->char('name_hash', 64);

            $table->json('payload');

            // When the latest delivery of this name was recorded.
            $table->timestamp('received_at');

            $table->unique(['workflow_execution_id', 'name_hash'], 'laraflow_signal_inbox_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraflow_signal_inbox');
    }
};
