<?php

namespace Laraflow\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StepExecutionModel extends Model
{
    protected $table = 'laraflow_step_executions';

    protected $guarded = [];

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function workflowExecution(): BelongsTo
    {
        return $this->belongsTo(
            WorkflowExecutionModel::class,
            'workflow_execution_id'
        );
    }
}