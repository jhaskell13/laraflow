<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * One piece of idempotent work a step began under remember(). The row
 * is written before the work runs and settled after, so a row still
 * `pending` on a later attempt means the outcome is unknown.
 *
 * @property int $id
 * @property int $workflow_execution_id
 * @property int $step_position
 * @property string $key
 * @property string $status
 * @property mixed $value
 * @property string|null $error_class
 * @property Carbon $started_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class IdempotencyKeyModel extends Model
{
    protected $table = 'laraflow_idempotency_keys';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_execution_id',
        'step_position',
        'key',
        'status',
        'value',
        'error_class',
        'started_at',
        'settled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // pdo_sqlsrv returns every column as a string.
            'workflow_execution_id' => 'integer',
            'step_position' => 'integer',
            // A settled value is any durable value — a scalar as well
            // as an array — and the array cast decodes either.
            'value' => 'array',
            'started_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
