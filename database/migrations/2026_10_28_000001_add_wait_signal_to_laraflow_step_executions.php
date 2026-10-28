<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The name of the signal a waiting step is parked on; null for a
     * timed wait and for every step that is not waiting. Unindexed: it
     * is read per execution, and signal delivery matches it inside a
     * query already bounded by the execution id.
     *
     * The rest of a wait reuses existing columns — next_retry_at for a
     * timed wait's resume instant, timeout_at for a signal wait's
     * deadline — so this is the only schema the feature needs.
     */
    public function up(): void
    {
        Schema::table('laraflow_step_executions', function (Blueprint $table) {
            $table->string('wait_signal')->nullable()->after('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::table('laraflow_step_executions', function (Blueprint $table) {
            $table->dropColumn('wait_signal');
        });
    }
};
