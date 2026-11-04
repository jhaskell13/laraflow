<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laraflow\Workflow\Models\Concerns\StoresTimestampsInUtc;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * Insert-only: a tick's outcome is decided in the transaction that
 * claims it, so there is no updated_at to maintain, and created_at is
 * written explicitly from the scheduler's clock rather than by
 * Eloquent — the claim instant is data (scheduler lag reads it), not
 * bookkeeping.
 *
 * @property int $id
 * @property int $schedule_id
 * @property Carbon $tick_at
 * @property string $outcome
 * @property int|null $workflow_execution_id
 * @property int|null $missed
 * @property Carbon|null $missed_from
 * @property int|null $overlapped_execution_id
 * @property Carbon|null $created_at
 */
class ScheduleTickModel extends Model
{
    use StoresTimestampsInUtc;

    public $timestamps = false;

    protected $table = 'laraflow_schedule_ticks';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'schedule_id',
        'tick_at',
        'outcome',
        'workflow_execution_id',
        'missed',
        'missed_from',
        'overlapped_execution_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tick_at' => 'datetime',
            'missed_from' => 'datetime',
            'created_at' => 'datetime',
            // pdo_sqlsrv returns every column as a string.
            'schedule_id' => 'integer',
            'workflow_execution_id' => 'integer',
            'missed' => 'integer',
            'overlapped_execution_id' => 'integer',
        ];
    }
}
