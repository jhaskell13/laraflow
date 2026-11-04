<?php

declare(strict_types=1);

namespace Laraflow\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @internal Implementation detail; not covered by the compatibility policy.
 *
 * A signal recorded for an execution that was not yet parked on its
 * name, held until a park asks for it. One row per execution and name;
 * a later delivery overwrites it. received_at is written from the
 * runner's clock rather than by Eloquent — the instant is data — so
 * there are no timestamps to maintain.
 *
 * @property int $id
 * @property int $workflow_execution_id
 * @property string $name
 * @property string $name_hash
 * @property array<array-key, mixed> $payload
 * @property Carbon $received_at
 */
class SignalInboxModel extends Model
{
    public $timestamps = false;

    protected $table = 'laraflow_signal_inbox';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workflow_execution_id',
        'name',
        'name_hash',
        'payload',
        'received_at',
    ];

    /** The unique key's form of a name: byte-exact on every engine. */
    public static function hashName(string $name): string
    {
        return hash('sha256', $name);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // pdo_sqlsrv returns every column as a string.
            'workflow_execution_id' => 'integer',
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }
}
