<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per registered schedule — the durable half of a
     * declaration that lives on the workflow class. The row is a cache
     * of the class, synchronised on every tick run, and never the
     * authority: cron, timezone, policy, and the overlap flag are
     * written from code each time, so editing the row changes nothing
     * for longer than a minute. What the row alone holds is the part
     * code cannot: last_tick_at, the high-water mark everything before
     * which is not due. See docs/schedules.md.
     */
    public function up(): void
    {
        Schema::create('laraflow_schedules', function (Blueprint $table) {
            $table->id();

            // The workflow class is the schedule's identity: a workflow
            // has at most one schedule, and renaming the class is a new
            // schedule (docs/schedules.md, "Removal and renaming").
            // Unique, and the only lookup the registry ever makes.
            $table->string('workflow')->unique();

            $table->string('cron');
            $table->string('timezone');

            // skip | catch_up
            $table->string('missed_tick_policy');
            $table->boolean('without_overlapping')->default(false);

            // Recorded on every execution this schedule starts; null for
            // a schedule that serves no tenant. 255 like every other
            // application-supplied identifier (ExecutionOptions).
            $table->string('tenant_id')->nullable();

            // The most recent tick instant claimed — or, before any has
            // been, the instant of first synchronisation or of the last
            // resume, which is how a new or resumed schedule never
            // backfills. UTC, like every Laraflow timestamp.
            $table->timestamp('last_tick_at')->nullable();

            $table->timestamp('paused_at')->nullable();

            // When the registry last saw a declaration for this row. A
            // row whose declared_at is older than the current run is an
            // orphan: the class was unlisted, renamed, or lost its
            // schedule(), and the listing reports it rather than
            // deleting history behind the operator's back.
            $table->timestamp('declared_at')->nullable();

            // Bumped by every repository write, for the same reason the
            // executions table carries one: MySQL and MariaDB count rows
            // *changed*, and a compare-and-set that rewrites identical
            // values must still be told it matched.
            $table->unsignedBigInteger('version')->default(0);

            $table->timestamps();

            // No further indexes: the registry holds a handful of rows,
            // read whole on every tick run and by class name through the
            // unique above. A scan over registered schedules is bounded
            // by how many an application declares, not by history —
            // history is the ticks table.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraflow_schedules');
    }
};
