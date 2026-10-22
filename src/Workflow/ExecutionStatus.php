<?php

declare(strict_types=1);

namespace Laraflow\Workflow;

enum ExecutionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
