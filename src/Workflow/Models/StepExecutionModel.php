<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * @property int $id
 * @property int $workflow_execution_id
 * @property string $step
 * @property int $position
 * @property string $status
 * @property int $attempts
 * @property mixed $input
 * @property mixed $output
 * @property string|null $error
 * @property string|null $error_class
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkflowExecutionModel $workflowExecution
 */
class StepExecutionModel extends Model
{
    protected $table = 'laraflow_step_executions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_execution_id',
        'step',
        'position',
        'status',
        'attempts',
        'input',
        'output',
        'error',
        'error_class',
        'started_at',
        'completed_at',
        'failed_at',
        'next_retry_at',
    ];

    /**
     * @return BelongsTo<WorkflowExecutionModel, $this>
     */
    public function workflowExecution(): BelongsTo
    {
        return $this->belongsTo(WorkflowExecutionModel::class, 'workflow_execution_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'attempts' => 'integer',
            'input' => 'array',
            'output' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }
}
