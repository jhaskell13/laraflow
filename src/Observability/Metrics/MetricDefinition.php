<?php

declare(strict_types=1);

namespace Laraflow\Observability\Metrics;

/**
 * @experimental The metrics model is settled by the bridges of
 * Milestone 11; see docs/metrics.md.
 *
 * One series family as docs/metrics.md defines it: its name, type, the
 * labels every series in it carries — in a fixed order — and, for a
 * histogram, its default buckets. A sink registers a family from this
 * and nothing else, so two bridges cannot describe one family two ways.
 */
final readonly class MetricDefinition
{
    /**
     * @param  list<string>  $labels  Label names, in the order every series carries them.
     * @param  list<float>  $buckets  Default upper bounds in seconds; histograms only. A bridge may let the application change them.
     */
    public function __construct(
        public string $name,
        public MetricType $type,
        public string $help,
        public array $labels = [],
        public array $buckets = [],
    ) {}
}
