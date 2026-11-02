{{--
    Start a workflow: the browser form of `laraflow:start`, through the
    same resolution, validation, and services. Rendered only when the
    action routes exist. Opens itself when the form was just refused, so
    the message and the filled-in fields are in view.

    @var list<string> $workflowsSeen
    @var bool $queueIsSync
--}}
@php
    $hasSession = request()->hasSession();
    $reopen = request()->query('start') !== null;
    $old = fn (string $key, string $default = ''): string => $hasSession ? (string) old($key, $default) : $default;
@endphp

<details class="lf-card lf-start"{{ $reopen ? ' open' : '' }}>
    <summary class="lf-card__title lf-actions__summary">Start a workflow</summary>

    <form method="post" action="{{ route('laraflow.actions.start') }}" class="lf-action lf-start__form" data-lf-confirm="Start a new execution of this workflow?">
        @csrf
        <p class="lf-muted lf-action__help">
            Creates an execution of an existing workflow class and runs it, or queues it. The browser form of <code>laraflow:start</code>; it edits nothing.
        </p>
        <div class="lf-filters__fields">
            <label class="lf-field lf-field--wide">
                <span class="lf-filters__label">Workflow</span>
                <input type="text" name="workflow" value="{{ $old('workflow') }}" list="lf-workflows-seen" placeholder="App\Workflows\FulfilOrder, or FulfilOrder" required spellcheck="false">
            </label>
            <label class="lf-field">
                <span class="lf-filters__label">Correlation <span class="lf-muted">(optional)</span></span>
                <input type="text" name="correlation" value="{{ $old('correlation') }}" spellcheck="false">
            </label>
            <label class="lf-field">
                <span class="lf-filters__label">Tenant <span class="lf-muted">(optional)</span></span>
                <input type="text" name="tenant" value="{{ $old('tenant') }}" spellcheck="false">
            </label>
        </div>
        <label class="lf-field">
            <span class="lf-filters__label">Input <span class="lf-muted">(JSON object, optional)</span></span>
            <textarea name="input" rows="3" placeholder='{"order_id": 42}' spellcheck="false">{{ $old('input') }}</textarea>
        </label>
        <label class="lf-check">
            <input type="checkbox" name="queue" value="1" @checked($hasSession && old('queue') !== null ? (bool) old('queue') : ! $queueIsSync)>
            Dispatch to the queue instead of running in this request
        </label>
        <div class="lf-filters__actions">
            <button type="submit" class="lf-button lf-button--primary">Start</button>
        </div>
    </form>
</details>
