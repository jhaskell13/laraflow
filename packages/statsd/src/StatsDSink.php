<?php

declare(strict_types=1);

namespace Laraflow\StatsD;

use InvalidArgumentException;
use Laraflow\Observability\Metrics\MetricDefinition;
use Laraflow\Observability\Metrics\MetricsSink;

/**
 * Laraflow's metrics as StatsD datagrams, one per observation.
 *
 * **Labels become tags**, in one of two dialects:
 *
 * - `dogstatsd` (the default): `name:1|c|#workflow:App\Foo,outcome:completed`
 *   — Datadog's agent, Telegraf, and most modern collectors read it.
 * - `graphite`: `name;workflow=App\Foo;outcome=completed:1|c` — Graphite
 *   1.1 tags, which etsy statsd 0.9+ forwards.
 *
 * Plain, untagged StatsD is not offered: it would have to fold labels
 * into the name, and docs/metrics.md forbids renaming a series.
 *
 * **Types.** Counters are `|c`; histograms are `|h` with the value in
 * seconds, as the series name says — collectors treat `|h` as a timer
 * whose unit is the caller's; gauges are `|g`. An empty label value —
 * no tenant, no status class — is dropped rather than sent empty.
 */
final class StatsDSink implements MetricsSink
{
    public function __construct(
        private readonly Transport $transport,
        private readonly string $dialect = 'dogstatsd',
    ) {
        if (! in_array($dialect, ['dogstatsd', 'graphite'], true)) {
            throw new InvalidArgumentException("laraflow-statsd.dialect must be dogstatsd or graphite; got [{$dialect}].");
        }
    }

    public function increment(MetricDefinition $metric, array $labels, float $by = 1.0): void
    {
        $this->transport->send($this->line($metric->name, $by, 'c', $labels));
    }

    public function observe(MetricDefinition $metric, array $labels, float $value): void
    {
        $this->transport->send($this->line($metric->name, $value, 'h', $labels));
    }

    /**
     * Sends every series given. A series the last publish sent and this
     * one does not is simply not sent: StatsD has no way to delete a
     * gauge, and a collector stops reporting one it is no longer given
     * (DogStatsD always; etsy statsd with `deleteGauges: true`). Zero
     * would be a claim — an age of zero says something was just created.
     */
    public function replaceGauge(MetricDefinition $metric, array $series): void
    {
        foreach ($series as $one) {
            $this->transport->send($this->line($metric->name, $one['value'], 'g', $one['labels']));
        }
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function line(string $name, float $value, string $type, array $labels): string
    {
        $labels = array_filter($labels, fn (string $value): bool => $value !== '');
        $number = $this->number($value);

        if ($this->dialect === 'graphite') {
            $tags = '';

            foreach ($labels as $key => $tag) {
                // `;` separates tags and `~` is reserved in Graphite.
                $tags .= ';'.$key.'='.strtr($tag, [';' => '_', '~' => '_', ' ' => '_', "\n" => '_']);
            }

            return "{$name}{$tags}:{$number}|{$type}";
        }

        $tags = [];

        foreach ($labels as $key => $tag) {
            // `,` separates tags and `|` sections; neither may appear.
            $tags[] = $key.':'.strtr($tag, [',' => '_', '|' => '_', "\n" => '_']);
        }

        return "{$name}:{$number}|{$type}".($tags === [] ? '' : '|#'.implode(',', $tags));
    }

    private function number(float $value): string
    {
        // No exponent notation: StatsD parsers read plain decimals only.
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') ?: '0';
    }
}
