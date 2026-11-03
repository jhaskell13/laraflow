<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * @property int $id
 * @property string $workflow
 * @property string $cron
 * @property string $timezone
 * @property string $missed_tick_policy
 * @property bool $without_overlapping
 * @property string|null $tenant_id
 * @property Carbon|null $last_tick_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $declared_at
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ScheduleModel extends Model
{
    protected $table = 'laraflow_schedules';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow',
        'cron',
        'timezone',
        'missed_tick_policy',
        'without_overlapping',
        'tenant_id',
        'last_tick_at',
        'paused_at',
        'declared_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'without_overlapping' => 'boolean',
            'last_tick_at' => 'datetime',
            'paused_at' => 'datetime',
            'declared_at' => 'datetime',
            // pdo_sqlsrv returns every column as a string.
            'version' => 'integer',
        ];
    }
}
