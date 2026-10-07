<?php

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
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

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