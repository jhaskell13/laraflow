{{--
    One execution in full: what `laraflow:show --attempts` prints.

    @var \Laraflow\Workflow\WorkflowExecution $execution
    @var \Laraflow\Workflow\StepExecution[] $steps
    @var \Laraflow\Workflow\StepAttempt[] $attempts
    @var array<int, string> $stepNames           step id => class
    @var \Laraflow\Workflow\IdempotencyRecord[] $remembered
    @var \Laraflow\Workflow\ExecutionPage $children
    @var \Laraflow\Workflow\Scheduling\ScheduleTick|null $tick
    @var \Laraflow\Workflow\StepExecution[] $overdue
    @var list<array{step: \Laraflow\Workflow\StepExecution, kind: string, childId: int|null}> $waits
    @var bool $queueIsSync
    @var bool $canAct
    @var \DateTimeImmutable $now
--}}
@use('Laraflow\Support\Formats')

@extends('laraflow::dashboard.layout')

@section('title', 'Execution '.$execution->id)

@section('content')
    <nav class="lf-crumbs" aria-label="Breadcrumb">
        <a href="{{ route('laraflow.executions') }}">Executions</a>
        <span aria-hidden="true">/</span>
        <span>{{ $execution->id }}</span>
    </nav>

    <div class="lf-page-head">
        <h1 class="lf-title">
            <span class="lf-id">#{{ $execution->id }}</span>
            <span title="{{ $execution->workflow }}">{{ class_basename($execution->workflow) }}</span>
            @include('laraflow::dashboard.partials.status', ['status' => $execution->status])
        </h1>
    </div>

    @include('laraflow::dashboard.partials.notices', [
        'execution' => $execution,
        'waits' => $waits,
        'overdue' => $overdue,
        'now' => $now,
    ])

    @if ($canAct)
        @include('laraflow::dashboard.partials.actions', [
            'execution' => $execution,
            'offers' => $offers,
            'queueIsSync' => $queueIsSync,
        ])
    @endif

    <div class="lf-grid">
        <section class="lf-card">
            <h2 class="lf-card__title">Record</h2>
            <dl class="lf-dl">
                <dt>Workflow</dt>
                <dd class="lf-mono">{{ $execution->workflow }}</dd>
                @if ($execution->planVersion > 1)
                    <dt>Plan version</dt>
                    <dd>{{ $execution->planVersion }}</dd>
                @endif
                <dt>Created</dt>
                <dd>{{ Formats::date($execution->createdAt) }}</dd>
                <dt>Started</dt>
                <dd>{{ Formats::date($execution->startedAt) }}</dd>
                <dt>Finished</dt>
                <dd>{{ Formats::date($execution->finishedAt) }}</dd>
                @if ($execution->durationMs() !== null)
                    <dt>Duration</dt>
                    <dd title="End to end, including any time spent failed or waiting out a backoff">{{ Formats::duration($execution->durationMs()) }}</dd>
                @endif
                @if ($execution->correlationId !== null)
                    <dt>Correlation</dt>
                    <dd class="lf-mono">{{ $execution->correlationId }}</dd>
                @endif
                @if ($execution->tenantId !== null)
                    <dt>Tenant</dt>
                    <dd class="lf-mono">{{ $execution->tenantId }}</dd>
                @endif
                @if ($execution->parentExecutionId !== null)
                    <dt>Parent</dt>
                    <dd><a href="{{ route('laraflow.executions.show', $execution->parentExecutionId) }}">execution {{ $execution->parentExecutionId }}</a> <span class="lf-muted">(this is a sub-workflow)</span></dd>
                @endif
                @if ($tick !== null)
                    <dt>Started by</dt>
                    <dd>
                        schedule tick {{ $tick->tickAt->format('Y-m-d H:i') }} UTC
                        @if ($tick->outcome === \Laraflow\Workflow\Scheduling\TickOutcome::CaughtUp)
                            <span class="lf-muted">(caught up)</span>
                        @endif
                        @if ($tick->missed > 0)
                            <span class="lf-muted">({{ $tick->missed }} missed before it)</span>
                        @endif
                    </dd>
                @endif
                @if ($execution->error !== null)
                    <dt>Error</dt>
                    <dd class="lf-error">{{ $execution->error }}@if ($execution->errorClass !== null) <span class="lf-muted lf-mono">{{ $execution->errorClass }}</span>@endif</dd>
                @endif
            </dl>
        </section>

        <section class="lf-card">
            <h2 class="lf-card__title">Queue and claim</h2>
            <dl class="lf-dl">
                @if ($execution->dispatchedAt === null)
                    <dt>Dispatched</dt>
                    <dd class="lf-muted">never — run in process</dd>
                @else
                    <dt>Dispatched</dt>
                    <dd>{{ Formats::date($execution->dispatchedAt) }}</dd>
                    @if ($execution->availableAt !== null && $execution->availableAt != $execution->dispatchedAt)
                        <dt>Available</dt>
                        <dd>{{ Formats::date($execution->availableAt) }} <span class="lf-muted">(held back by its retry backoff)</span></dd>
                    @endif
                    @if ($execution->isPending() && $execution->queueLagSeconds($now) !== null)
                        <dt>Queue wait</dt>
                        <dd class="lf-warn">{{ Formats::seconds($execution->queueLagSeconds($now)) }} so far — no worker has claimed it</dd>
                    @elseif ($execution->claimedAt !== null && $execution->queueLagSeconds($execution->claimedAt) !== null)
                        <dt>Queue wait</dt>
                        <dd>{{ Formats::seconds($execution->queueLagSeconds($execution->claimedAt)) }}</dd>
                    @endif
                @endif
                @if ($execution->claimToken !== null)
                    <dt>Claimed at</dt>
                    <dd>{{ Formats::date($execution->claimedAt) }} <span class="lf-muted">(a worker owns or owned this execution)</span></dd>
                @endif
                @if ($execution->fruitlessClaims > 1)
                    <dt>Claims without progress</dt>
                    <dd class="lf-warn">{{ $execution->fruitlessClaims }} <span class="lf-muted">(claimed repeatedly without reaching a step)</span></dd>
                @endif
                @if ($execution->isRetrying() && $execution->nextRetryAt !== null)
                    <dt>Next retry</dt>
                    <dd>{{ Formats::date($execution->nextRetryAt) }}</dd>
                @endif
                @if ($execution->isCancelled())
                    <dt>Cancelled</dt>
                    <dd>{{ Formats::date($execution->cancelledAt) }}@if ($execution->cancelReason !== null) — {{ $execution->cancelReason }}@endif</dd>
                @elseif ($execution->cancelRequestedAt !== null && $execution->isRunning())
                    <dt>Cancellation requested</dt>
                    <dd>{{ Formats::date($execution->cancelRequestedAt) }} <span class="lf-muted">(the worker stops at its next step boundary)</span></dd>
                @endif
                @if ($execution->payloadsScrubbedAt !== null)
                    <dt>Payloads scrubbed</dt>
                    <dd>{{ Formats::date($execution->payloadsScrubbedAt) }} <span class="lf-muted">(by retention; input, outputs, and context are gone)</span></dd>
                @endif
            </dl>
        </section>
    </div>

    <section class="lf-card">
        <h2 class="lf-card__title">Steps</h2>
        @include('laraflow::dashboard.partials.steps', ['steps' => $steps, 'now' => $now])
    </section>

    <section class="lf-card">
        <h2 class="lf-card__title">Attempts <span class="lf-muted lf-count">{{ count($attempts) }}</span></h2>
        @include('laraflow::dashboard.partials.attempts', ['attempts' => $attempts, 'stepNames' => $stepNames])
    </section>

    @if ($remembered !== [])
        <section class="lf-card">
            <h2 class="lf-card__title">Remembered work <span class="lf-muted lf-count">{{ count($remembered) }}</span></h2>
            @include('laraflow::dashboard.partials.remembered', ['remembered' => $remembered, 'stepNamesByPosition' => $stepNamesByPosition])
        </section>
    @endif

    @unless ($children->isEmpty())
        <section class="lf-card">
            <h2 class="lf-card__title">Children <span class="lf-muted lf-count">{{ count($children) }}{{ $children->hasMore() ? '+' : '' }}</span></h2>
            <ul class="lf-list">
                @foreach ($children as $child)
                    <li>
                        <a href="{{ route('laraflow.executions.show', $child->id) }}"><span class="lf-id">#{{ $child->id }}</span> {{ class_basename($child->workflow) }}</a>
                        @include('laraflow::dashboard.partials.status', ['status' => $child->status])
                    </li>
                @endforeach
            </ul>
            @if ($children->hasMore())
                <p class="lf-muted">More children than are shown here.</p>
            @endif
        </section>
    @endunless

    {{-- Shown in full, unlike the console's one truncated line, because
         reading a payload is what an operator opens this page for. Both
         are collapsed: they are also where an application that puts a
         secret in its workflow input would put it on screen, and that
         should take a deliberate click. See docs/dashboard.md. --}}
    <div class="lf-grid">
        <section class="lf-card">
            <details class="lf-payload">
                <summary class="lf-card__title lf-disclosure">Input <span class="lf-muted lf-count">{{ $execution->input === [] ? 'none' : count($execution->input).' key'.(count($execution->input) === 1 ? '' : 's') }}</span></summary>
                @include('laraflow::dashboard.partials.json', ['value' => $execution->input === [] ? null : $execution->input])
            </details>
        </section>
        <section class="lf-card">
            <details class="lf-payload">
                <summary class="lf-card__title lf-disclosure">Context <span class="lf-muted lf-count">{{ $execution->context === [] ? 'none' : count($execution->context).' key'.(count($execution->context) === 1 ? '' : 's') }}</span></summary>
                @include('laraflow::dashboard.partials.json', ['value' => $execution->context === [] ? null : $execution->context])
            </details>
        </section>
    </div>
@endsection
