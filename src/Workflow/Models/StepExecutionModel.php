<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property Carbon|null $timeout_at
 * @property Carbon|null $next_retry_at
 * @property string|null $wait_signal
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkflowExecutionModel $workflowExecution
 * @property-read Collection<int, StepAttemptModel> $attemptHistory
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
        'timeout_at',
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
     * @return HasMany<StepAttemptModel, $this>
     */
    public function attemptHistory(): HasMany
    {
        return $this->hasMany(StepAttemptModel::class, 'step_execution_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Integer casts on the foreign keys as well as the own
            // columns: pdo_sqlsrv returns every column as a string, and
            // a handle must expose the same types on every engine.
            'workflow_execution_id' => 'integer',
            'position' => 'integer',
            'attempts' => 'integer',
            'input' => 'array',
            'output' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'timeout_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }
}
