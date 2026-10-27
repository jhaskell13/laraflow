<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Core\LaraflowManager;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Workflow\Step;
use Laraflow\Workflow\WorkflowContext;

/**
 * A step that calls a connector and treats an error response as a
 * failure, the way a real step would.
 */
class ConnectorStep implements Step
{
    public static string $connector = 'crm';

    public static ?string $connection = null;

    public static int $runs = 0;

    public function __construct(protected LaraflowManager $laraflow) {}

    public function handle(WorkflowContext $context): mixed
    {
        static::$runs++;

        return $this->laraflow
            ->connector(static::$connector, static::$connection)
            ->request(new ConnectorRequest('GET', '/contacts'))
            ->throw()
            ->toArray();
    }
}
