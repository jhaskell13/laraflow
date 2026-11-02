{{--
    What an operator should know first about this execution, said the
    way the console says it: a wait as a wait with what wakes it, a
    retry with when, an overdue step as hung-not-dead.

    @var \Laraflow\Workflow\WorkflowExecution $execution
    @var list<array{step: \Laraflow\Workflow\StepExecution, kind: string, childId: int|null}> $waits
    @var \Laraflow\Workflow\StepExecution[] $overdue
    @var \DateTimeImmutable $now
--}}
@use('Laraflow\Support\Formats')

@foreach ($waits as $waiting)
    <div class="lf-notice lf-notice--info">
        <strong>Waiting.</strong>
        {{ class_basename($waiting['step']->step) }}
        @switch($waiting['kind'])
            @case('child')
                is waiting on <a href="{{ route('laraflow.executions.show', $waiting['childId']) }}">child execution {{ $waiting['childId'] }}</a> (next check {{ Formats::date($execution->nextRetryAt) }}).
                @break
            @case('malformed')
                is waiting on a malformed child signal <code>{{ $waiting['step']->waitSignal }}</code> — a row altered outside the package.
                @break
            @case('timed')
                resumes at {{ Formats::date($waiting['step']->nextRetryAt) }}; <code>laraflow:recover</code> delivers it when due.
                @break
            @case('signal')
                is waiting for signal <code>{{ $waiting['step']->waitSignal }}</code>, until {{ Formats::date($waiting['step']->timeoutAt) }}; the application delivers it through <code>WorkflowRunner::signal()</code>.
                @break
            @default
                is waiting for signal <code>{{ $waiting['step']->waitSignal }}</code>, indefinitely; nothing but <code>WorkflowRunner::signal()</code> wakes it.
        @endswitch
    </div>
@endforeach

@if ($execution->isRetrying())
    <div class="lf-notice lf-notice--info">
        <strong>Retrying.</strong>
        A step failed and its retry policy scheduled another attempt for {{ Formats::date($execution->nextRetryAt) }}.
        <code>laraflow:recover</code> delivers it when due; <code>laraflow:execute {{ $execution->id }} --now</code> skips the wait.
    </div>
@endif

@if ($execution->isFailed())
    <div class="lf-notice lf-notice--error">
        <strong>Failed.</strong>
        The failure is persisted and the completed steps are kept. Recover from the checkpoint with <code>laraflow:execute {{ $execution->id }}</code>.
    </div>
@endif

@foreach ($overdue as $step)
    <div class="lf-notice lf-notice--warn">
        <strong>Overdue.</strong>
        {{ class_basename($step->step) }} is running past its deadline of {{ Formats::date($step->timeoutAt) }}.
        Laraflow does not interrupt running PHP, so nothing acts on it while the claim is fresh; it is bounded by your queue worker's timeout.
    </div>
@endforeach
