<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per tick claimed, and the unique key below is the whole
     * "exactly one execution per tick across any number of schedulers"
     * guarantee: every scheduler that reads the same minute computes
     * the same tick instant, every one of them tries the same insert,
     * and the database lets exactly one through — the same shape as the
     * execution claim, with the index where the compare-and-set was.
     * No lock to lose, no leader to elect. See docs/schedules.md.
     *
     * Rows are insert-only — a tick's outcome is decided in the
     * transaction that claims it — so there is no updated_at and no
     * version counter: nothing ever comes back to a tick to write it
     * again.
     */
    public function up(): void
    {
        Schema::create('laraflow_schedule_ticks', function (Blueprint $table) {
            $table->id();

            // Deleting a schedule (the listing's --forget, allowed only
            // for orphans) takes its tick history with it; retention
            // prunes ticks on their own clock without touching the
            // schedule either way.
            $table->foreignId('schedule_id')
                ->constrained('laraflow_schedules')
                ->cascadeOnDelete();

            // The instant this tick names, UTC — as distinct from
            // created_at, which is when a scheduler claimed it; the
            // difference is scheduler lag.
            $table->timestamp('tick_at');

            // started | caught_up | skipped_overlap
            $table->string('outcome');

            // The execution this tick started; null for skipped_overlap.
            // Deliberately NOT a foreign key: retention may delete the
            // execution long before the tick history goes, and "this
            // tick started execution 4812" stays true after 4812 is
            // gone — the same trade-off as the sub-workflow parent
            // links (docs/retention.md).
            $table->unsignedBigInteger('workflow_execution_id')->nullable();

            // Under MissedTickPolicy::Skip, how many due instants passed
            // unclaimed before this tick ran, and the earliest of them —
            // an hour's outage on a per-minute schedule is one row with
            // missed = 59, not fifty-nine rows. Null when nothing was
            // missed.
            $table->unsignedInteger('missed')->nullable();
            $table->timestamp('missed_from')->nullable();

            // For skipped_overlap: the still-running execution the
            // schedule declined to run beside.
            $table->unsignedBigInteger('overlapped_execution_id')->nullable();

            $table->timestamp('created_at')->nullable();

            // The once-per-tick guarantee. Its leftmost prefix also
            // serves the foreign key and the "latest tick of this
            // schedule" read the overlap check makes, so schedule_id
            // needs no index of its own on any engine. Named, because
            // the scheduler catches the unique-violation by asking the
            // database "did my insert lose the race", and a constraint
            // it refers to should have a name chosen rather than
            // generated.
            $table->unique(['schedule_id', 'tick_at'], 'laraflow_schedule_ticks_unique');

            // The origin lookup (what started execution N), the overlap
            // check's "is the execution I started still running", and
            // the scheduler's own re-dispatch scan for pending
            // executions its ticks created.
            $table->index('workflow_execution_id');

            // Retention's prune-ticks scan ages rows by created_at
            // (docs/schedules.md, "Persistence"); the bound ships with
            // the retention step, the index with the table, so enabling
            // it never needs a second migration.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraflow_schedule_ticks');
    }
};
