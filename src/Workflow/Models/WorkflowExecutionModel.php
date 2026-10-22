<?php

namespace Laraflow\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 */
class WorkflowExecutionModel extends Model
{
    protected $table = 'laraflow_workflow_executions';

    protected $guarded = [];

    protected $casts = [
        'input' => 'array',
        'context' => 'array',
        'claimed_at' => 'datetime',
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