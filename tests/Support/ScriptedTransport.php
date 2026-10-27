<?php

declare(strict_types=1);

namespace Laraflow\Tests\Support;

use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Integration\Http\HttpOptions;
use Laraflow\Integration\Http\HttpTransport;
use LogicException;
use Throwable;

/**
 * A transport that answers from a script — a response to return or an
 * exception to throw, per call, in order — and records what it was sent.
 */
class ScriptedTransport implements HttpTransport
{
    /**
     * @var list<ConnectorRequest>
     */
    public array $sent = [];

    /**
     * @param  list<ConnectorResponse|Throwable>  $script
     */
    public function __construct(public array $script) {}

    public function send(ConnectorRequest $request, HttpOptions $options): ConnectorResponse
    {
        $this->sent[] = $request;

        $next = array_shift($this->script) ?? throw new LogicException('The transport script is exhausted.');

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}
