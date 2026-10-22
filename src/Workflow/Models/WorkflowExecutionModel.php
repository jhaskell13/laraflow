<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * @property int $id
 * @property string $workflow
 * @property string $status
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $context
 * @property string|null $error
 * @property string|null $error_class
 * @property string|null $claim_token
 * @property Carbon|null $claimed_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, StepExecutionModel> $steps
 */
class WorkflowExecutionModel extends Model
{
    protected $table = 'laraflow_workflow_executions';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow',
        'status',
        'input',
        'context',
        'error',
        'error_class',
        'claim_token',
        'claimed_at',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    /**
     * @return HasMany<StepExecutionModel, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(StepExecutionModel::class, 'workflow_execution_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input' => 'array',
            'context' => 'array',
            'claimed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
