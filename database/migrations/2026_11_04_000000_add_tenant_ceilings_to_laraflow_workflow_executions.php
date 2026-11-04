<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-tenant ceilings, enforced at claim time (docs/tenancy.md).
     *
     * Two columns record a refusal without changing anything else: a
     * claim a ceiling turned away leaves the execution's status exactly
     * as it was, writes when its delivery will next be tried and how
     * many times it has been turned away, and the first successful
     * claim clears both — so an operator reading the record sees
     * "waiting for a tenant slot" and not a dispatch that went missing.
     *
     * Two indexes. (tenant_id, status, claimed_at) serves the one count
     * every bounded claim makes — this tenant's executions holding a
     * worker right now, which is running or compensating with a fresh
     * claim — so the ceiling costs one indexed query. deferred_until
     * serves the attention scan's "what is deferred right now", which
     * would otherwise walk the table on every summary.
     *
     * Both index a populated table. An index build is not online on
     * PostgreSQL inside a migration transaction, nor on SQL Server
     * outside Enterprise; see docs/upgrading.md.
     */
    public function up(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->timestamp('deferred_until')->nullable();
            $table->unsignedInteger('deferrals')->default(0);

            $table->index(['tenant_id', 'status', 'claimed_at'], 'laraflow_executions_tenant_slot_index');
            $table->index('deferred_until', 'laraflow_executions_deferred_index');
        });
    }

    public function down(): void
    {
        Schema::table('laraflow_workflow_executions', function (Blueprint $table) {
            $table->dropIndex('laraflow_executions_deferred_index');
            $table->dropIndex('laraflow_executions_tenant_slot_index');
            $table->dropColumn(['deferred_until', 'deferrals']);
        });
    }
};
