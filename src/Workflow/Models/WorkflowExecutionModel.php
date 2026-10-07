<?php

namespace Laraflow\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowExecutionModel extends Model
{
    protected $table = 'laraflow_workflow_executions';

    protected $guarded = [];

    protected $casts = [
        'input' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(
            StepExecutionModel::class,
            'workflow_execution_id'
        );
    }
}