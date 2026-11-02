{{--
    The operator actions for one execution, each a form posting to the
    service the console command calls. Rendered only when the action
    routes exist — a read-only mount has none — and only the actions
    this execution's state admits.

    Confirmation: with script, a dialog before the post; without, each
    action sits behind this disclosure and its own labelled button, so
    nothing happens on a stray click either way.

    @var \Laraflow\Workflow\WorkflowExecution $execution
    @var array{step: \Laraflow\Workflow\StepExecution, kind: string, childId: int|null}|null $waiting
    @var bool $queueIsSync
--}}
@php
    $canExecute = $execution->isPending() || $execution->isFailed() || $execution->isRetrying() || $execution->isWaiting();
    $canCancel = ! $execution->isCompleted() && ! $execution->isCancelled();
    $canSignal = $waiting !== null && in_array($waiting['kind'], ['signal', 'signal-indefinite'], true);
    $early = $execution->isRetrying() || ($waiting !== null && $waiting['kind'] === 'timed');
@endphp

@if ($canExecute || $canCancel || $canSignal)
    <details class="lf-card lf-actions"{{ $canSignal ? ' open' : '' }}>
        <summary class="lf-card__title lf-actions__summary">Actions</summary>

        <div class="lf-actions__grid">
            @if ($canExecute)
                <form method="post" action="{{ route('laraflow.actions.execute', $execution->id) }}" class="lf-action" data-lf-confirm="{{ $execution->isFailed() ? 'Recover this execution from its checkpoint? Completed steps are skipped; the failed step runs again.' : 'Run this execution now?' }}">
                    @csrf
                    <h3 class="lf-action__title">{{ $execution->isFailed() ? 'Recover' : 'Execute' }}</h3>
                    <p class="lf-muted lf-action__help">
                        @if ($execution->isFailed())
                            Completed steps are skipped; the failed step runs again from the checkpoint. The browser form of <code>laraflow:execute {{ $execution->id }}</code>.
                        @elseif ($execution->isRetrying())
                            The step's retry policy owns the next attempt; this runs it ahead of schedule. <code>laraflow:execute {{ $execution->id }} --now</code>.
                        @elseif ($execution->isWaiting())
                            Resumes a timed wait early, or re-delivers a recorded signal. <code>laraflow:execute {{ $execution->id }} --now</code>.
                        @else
                            Runs the pending plan. <code>laraflow:execute {{ $execution->id }}</code>.
                        @endif
                    </p>
                    <label class="lf-check">
                        <input type="checkbox" name="queue" value="1" @checked(! $queueIsSync)>
                        Dispatch to the queue instead of running in this request
                        @if ($queueIsSync)<span class="lf-muted">(the queue connection is <code>sync</code>, so it would run here anyway)</span>@endif
                    </label>
                    @if ($early)
                        <label class="lf-check">
                            <input type="checkbox" name="now" value="1" checked>
                            Ignore the schedule and run immediately
                        </label>
                    @endif
                    <button type="submit" class="lf-button lf-button--primary">{{ $execution->isFailed() ? 'Recover' : 'Execute' }}</button>
                </form>
            @endif

            @if ($canSignal)
                <form method="post" action="{{ route('laraflow.actions.signal', $execution->id) }}" class="lf-action" data-lf-confirm="Record this signal for the waiting step?">
                    @csrf
                    <h3 class="lf-action__title">Signal</h3>
                    <p class="lf-muted lf-action__help">
                        The waiting step resumes with the payload as its output, under the signal's name in the context. The browser form of <code>WorkflowRunner::signal()</code>.
                    </p>
                    <label class="lf-field">
                        <span class="lf-filters__label">Signal</span>
                        <input type="text" name="signal" value="{{ $waiting['step']->waitSignal }}" required spellcheck="false">
                    </label>
                    <label class="lf-field">
                        <span class="lf-filters__label">Payload <span class="lf-muted">(JSON object, optional)</span></span>
                        <textarea name="payload" rows="3" placeholder='{"approved_by": "ops@example.com"}' spellcheck="false"></textarea>
                    </label>
                    <label class="lf-check">
                        <input type="checkbox" name="deliver" value="1" checked>
                        Dispatch it for delivery now <span class="lf-muted">(recording is not delivery; otherwise <code>laraflow:recover</code> picks it up)</span>
                    </label>
                    <button type="submit" class="lf-button lf-button--primary">Signal</button>
                </form>
            @endif

            @if ($canCancel)
                <form method="post" action="{{ route('laraflow.actions.cancel', $execution->id) }}" class="lf-action" data-lf-confirm="Cancel this execution? Completed steps are kept; this cannot be undone.">
                    @csrf
                    <h3 class="lf-action__title">Cancel</h3>
                    <p class="lf-muted lf-action__help">
                        @if ($execution->isRunning())
                            Cooperative: the worker stops at its next step boundary. Cancelled is terminal.
                        @else
                            Cancelled is terminal: it cannot be executed or recovered afterwards. Completed steps are kept.
                        @endif
                        The browser form of <code>laraflow:cancel {{ $execution->id }}</code>.
                    </p>
                    <label class="lf-field">
                        <span class="lf-filters__label">Reason <span class="lf-muted">(optional)</span></span>
                        <input type="text" name="reason" maxlength="255" placeholder="customer withdrew the order">
                    </label>
                    <button type="submit" class="lf-button lf-button--danger">Cancel execution</button>
                </form>
            @endif
        </div>
    </details>
@endif
