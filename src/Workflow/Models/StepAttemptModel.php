<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * One finished step attempt. Rows are append-only and written in the
 * same transaction as the step state change they describe, so history
 * can never disagree with the step row.
 *
 * @property int $id
 * @property int $workflow_execution_id
 * @property int $step_execution_id
 * @property int $attempt
 * @property string $status
 * @property string|null $error
 * @property string|null $error_class
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_ms
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StepExecutionModel $stepExecution
 * @property-read WorkflowExecutionModel $workflowExecution
 */
class StepAttemptModel extends Model
{
    protected $table = 'laraflow_step_attempts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_execution_id',
        'step_execution_id',
        'attempt',
        'status',
        'error',
        'error_class',
        'started_at',
        'ended_at',
        'duration_ms',
        'next_retry_at',
    ];

    /**
     * @return BelongsTo<StepExecutionModel, $this>
     */
    public function stepExecution(): BelongsTo
    {
        return $this->belongsTo(StepExecutionModel::class, 'step_execution_id');
    }

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
            'attempt' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }
}
