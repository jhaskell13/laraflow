<?php

declare(strict_types=1);

namespace Laraflow\Triggers;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal The signed endpoint's gate. Verifies the request against
 * the configured keys before the controller reads anything from the
 * database, and answers every failure the same way — `401
 * {"status": "refused"}` — so the caller learns nothing from the
 * answer. The reason goes to the log and the event.
 */
final class VerifyTriggerSignature
{
    public const TRIGGER = 'signed';

    public function __construct(
        private Config $config,
        private ClockInterface $clock,
        private TriggerReporter $reporter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $executionId = (int) $request->route('id');
        $signal = (string) $request->route('signal');

        $reason = $this->refusal($request, $executionId, $signal);

        if ($reason !== null) {
            $this->reporter->refused(self::TRIGGER, $reason, $executionId, $signal);

            return new JsonResponse(['status' => 'refused'], 401);
        }

        return $next($request);
    }

    /**
     * Why this request fails verification, or null when it passes. In
     * the documented order: shape, then time, then signature — and
     * every path through here is a pure function of the request, the
     * clock, and the configuration, with no database behind it.
     */
    private function refusal(Request $request, int $executionId, string $signal): ?TriggerRefusalReason
    {
        $timestamp = $request->header(TriggerSignature::TIMESTAMP_HEADER);
        $signature = $request->header(TriggerSignature::SIGNATURE_HEADER);

        if (! is_string($timestamp) || ! is_string($signature) || ! ctype_digit($timestamp)) {
            return TriggerRefusalReason::Unsigned;
        }

        $presented = TriggerSignature::parse($signature);

        if ($presented === null) {
            return TriggerRefusalReason::Unsigned;
        }

        $tolerance = $this->tolerance();
        $now = $this->clock->now()->getTimestamp();

        if (abs($now - (int) $timestamp) > $tolerance) {
            return TriggerRefusalReason::Stale;
        }

        $matched = TriggerSignature::matches(
            $presented,
            TriggerSignature::keys($this->config),
            (int) $timestamp,
            $executionId,
            $signal,
            $request->getContent(),
        );

        if ($matched === null) {
            return TriggerRefusalReason::InvalidSignature;
        }

        if ($matched > 0) {
            $this->reporter->verifiedWithPreviousKey(self::TRIGGER, $matched, $executionId, $signal);
        }

        return null;
    }

    private function tolerance(): int
    {
        $value = $this->config->get('laraflow.triggers.tolerance');

        return $value === null || $value === '' ? 300 : max(0, (int) $value);
    }
}
