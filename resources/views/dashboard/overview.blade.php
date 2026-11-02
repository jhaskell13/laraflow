{{--
    The front door: what `laraflow:summary` prints. Counts by status for
    the scope, and what needs attention — from the same AttentionScan
    the command runs.

    @var int $total
    @var array<string, int> $counts   status value => count
    @var \Laraflow\Workflow\AttentionReport|null $report
    @var \Laraflow\Dashboard\Support\FilterRequest $filters
    @var \DateTimeImmutable $now
--}}
@use('Laraflow\Support\Formats')
@use('Laraflow\Workflow\ExecutionStatus')

@extends('laraflow::dashboard.layout')

@section('title', 'Overview')

@section('content')
    <div class="lf-page-head">
        <h1 class="lf-title">Overview</h1>
        <p class="lf-muted">
            @if ($total === 0)
                {{ $filters->isEmpty() ? 'No executions yet.' : 'No executions match that filter.' }}
            @else
                {{ number_format($total) }} {{ $filters->isEmpty() ? '' : 'matching ' }}execution{{ $total === 1 ? '' : 's' }}.
            @endif
        </p>
    </div>

    @include('laraflow::dashboard.partials.filters', [
        'filters' => $filters,
        'workflowsSeen' => [],
        'withStatus' => false,
        'action' => route('laraflow.dashboard'),
    ])

    @if ($total === 0)
        <div class="lf-empty">
            @if ($filters->isEmpty())
                <p>Nothing has run. Start something and it appears here:</p>
                <pre class="lf-code">php artisan laraflow:start App\Workflows\YourWorkflow --input='{"key": "value"}'</pre>
                <p class="lf-muted">Or the bundled demo: <code>php artisan laraflow:demo</code></p>
            @else
                <p>No execution matches every filter above.</p>
            @endif
        </div>
    @else
        <div class="lf-counts">
            @foreach (ExecutionStatus::cases() as $status)
                <a class="lf-count-card lf-count-card--{{ $status->value }}" href="{{ route('laraflow.executions', $filters->query() + ['status' => [$status->value]]) }}">
                    <span class="lf-count-card__value">{{ number_format($counts[$status->value]) }}</span>
                    <span class="lf-count-card__label">{{ $status->value }}</span>
                    <span class="lf-count-card__share">{{ sprintf('%.1f%%', $counts[$status->value] / $total * 100) }}</span>
                </a>
            @endforeach
        </div>

        @if ($report !== null)
            <section class="lf-card">
                <h2 class="lf-card__title">
                    Needs attention
                    @if ($report->needsAttention())
                        <span class="lf-tag lf-tag--warn">yes</span>
                    @else
                        <span class="lf-tag lf-tag--ok">nothing</span>
                    @endif
                </h2>

                @if (! $report->needsAttention())
                    <p class="lf-muted">No retries or waits due and no step past its deadline.</p>
                @endif

                @if ($report->dueRetries !== [])
                    <div class="lf-notice lf-notice--warn">
                        <strong>{{ count($report->dueRetries) }} {{ count($report->dueRetries) === 1 ? 'retry is' : 'retries are' }} due now.</strong>
                        The most overdue is <a href="{{ route('laraflow.executions.show', $report->dueRetries[0]->id) }}">execution {{ $report->dueRetries[0]->id }}</a>, due {{ Formats::date($report->dueRetries[0]->nextRetryAt) }}.
                        Deliver them with <code>laraflow:recover</code>.
                    </div>
                @endif

                @if ($report->dueWaits !== [])
                    <div class="lf-notice lf-notice--warn">
                        <strong>{{ count($report->dueWaits) }} {{ count($report->dueWaits) === 1 ? 'wait is' : 'waits are' }} due for delivery.</strong>
                        The most overdue is <a href="{{ route('laraflow.executions.show', $report->dueWaits[0]->id) }}">execution {{ $report->dueWaits[0]->id }}</a>, due {{ Formats::date($report->dueWaits[0]->nextRetryAt) }}.
                        Deliver them with <code>laraflow:recover</code>.
                    </div>
                @endif

                @if ($report->overdueSteps !== [])
                    <div class="lf-notice lf-notice--warn">
                        <strong>{{ count($report->overdueSteps) }} execution{{ count($report->overdueSteps) === 1 ? ' has' : 's have' }} a running step past its deadline.</strong>
                        @foreach (array_slice($report->overdueSteps, 0, 5) as $row)
                            <a href="{{ route('laraflow.executions.show', $row['execution']->id) }}">[{{ $row['execution']->id }}] {{ class_basename($row['step']->step) }}</a>@if (! $loop->last), @endif
                        @endforeach
                        @if (count($report->overdueSteps) > 5) and more. @endif
                        Laraflow does not interrupt running PHP; these are bounded by your queue worker timeout.
                    </div>
                @endif

                @if ($report->oldestUnfinished !== null)
                    <p>
                        Oldest unfinished execution:
                        <a href="{{ route('laraflow.executions.show', $report->oldestUnfinished->id) }}">[{{ $report->oldestUnfinished->id }}] {{ class_basename($report->oldestUnfinished->workflow) }}</a>,
                        created {{ Formats::date($report->oldestUnfinished->createdAt) }}
                        <span class="lf-muted">({{ Formats::age($report->oldestUnfinished->createdAt, $now) }} ago)</span>.
                    </p>
                @endif
            </section>
        @endif
    @endif
@endsection
