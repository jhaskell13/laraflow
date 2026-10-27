<?php

declare(strict_types=1);

namespace Laraflow\Integration\Http;

use Laraflow\Exceptions\TransportException;
use Laraflow\Integration\ConnectorRequest;
use Laraflow\Integration\ConnectorResponse;
use Laraflow\Support\Sleeper;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Repeats a wire request within one step attempt, as its TransportPolicy
 * allows. The response reports how many attempts it took.
 *
 * @internal Assembled by TransportPipeline when a policy enables retries.
 */
final class RetryingTransport implements HttpTransport
{
    public function __construct(
        private HttpTransport $inner,
        private TransportPolicy $policy,
        private Sleeper $sleeper,
        private ClockInterface $clock,
    ) {}

    public function send(#[SensitiveParameter] ConnectorRequest $request, HttpOptions $options): ConnectorResponse
    {
        $attempt = 1;
        // Hashed only, never emitted; a query string in it is harmless.
        $seed = "{$request->method} {$request->uri}";

        while (true) {
            try {
                $response = $this->inner->send($request, $options);
            } catch (TransportException $exception) {
                if (! $this->policy->shouldRetryFailure($request, $attempt)) {
                    throw $exception->afterAttempts($attempt);
                }

                $this->sleeper->sleep($this->policy->delayMs($attempt, null, $seed));
                $attempt++;

                continue;
            }

            $retryAfter = $response->retryAfterSeconds($this->clock->now());

            if (! $this->policy->shouldRetryResponse($response, $request, $attempt, $retryAfter)) {
                return $response->withAttempts($attempt);
            }

            $this->sleeper->sleep($this->policy->delayMs($attempt, $retryAfter, $seed));
            $attempt++;
        }
    }
}
