{{--
    The filter form: exactly the criteria ExecutionFilter offers, which
    are the ones the schema serves from an index (docs/dashboard.md,
    "Filtering"). A GET form, so a filtered page is a URL that can be
    shared, refreshed, and bookmarked.

    @var \Laraflow\Dashboard\Support\FilterRequest $filters
    @var list<string> $workflowsSeen
    @var bool $withStatus   whether to offer the status checkboxes
    @var string $action     the route the form submits to
--}}
@php
    $values = $filters->values;
    $chosen = (array) ($values['status'] ?? []);
    $workflowValue = implode(', ', (array) ($values['workflow'] ?? []));
@endphp

<form class="lf-filters" method="get" action="{{ $action }}">
    @if ($withStatus)
        <fieldset class="lf-filters__statuses">
            <legend class="lf-filters__label">Status</legend>
            @foreach (\Laraflow\Workflow\ExecutionStatus::cases() as $status)
                <label class="lf-check">
                    <input type="checkbox" name="status[]" value="{{ $status->value }}" @checked(in_array($status->value, $chosen, true))>
                    <span class="lf-status lf-status--{{ $status->value }}">{{ $status->value }}</span>
                </label>
            @endforeach
        </fieldset>
    @endif

    <div class="lf-filters__fields">
        <label class="lf-field">
            <span class="lf-filters__label">Workflow</span>
            <input type="text" name="workflow" value="{{ $workflowValue }}" list="lf-workflows-seen" placeholder="App\Workflows\FulfilOrder" spellcheck="false">
            <datalist id="lf-workflows-seen">
                @foreach ($workflowsSeen as $workflow)
                    <option value="{{ $workflow }}">{{ class_basename($workflow) }}</option>
                @endforeach
            </datalist>
        </label>
        <label class="lf-field">
            <span class="lf-filters__label">Correlation</span>
            <input type="text" name="correlation" value="{{ $values['correlation'] ?? '' }}" spellcheck="false">
        </label>
        <label class="lf-field">
            <span class="lf-filters__label">Tenant</span>
            <input type="text" name="tenant" value="{{ $values['tenant'] ?? '' }}" spellcheck="false">
        </label>
        <label class="lf-field">
            <span class="lf-filters__label">Created since</span>
            <input type="text" name="since" value="{{ $values['since'] ?? '' }}" placeholder="-2 hours, or 2026-11-02 09:00">
        </label>
        <label class="lf-field">
            <span class="lf-filters__label">Created until</span>
            <input type="text" name="until" value="{{ $values['until'] ?? '' }}" placeholder="exclusive">
        </label>
        <div class="lf-filters__actions">
            {{-- With script the form filters as it is typed, so the
                 button is the path for a browser without it. --}}
            <button type="submit" class="lf-button lf-button--primary lf-no-js">Filter</button>
            @unless ($filters->isEmpty())
                <a class="lf-button" href="{{ $action }}" data-lf-page>Clear</a>
            @endunless
            <span class="lf-filters__busy" aria-hidden="true"></span>
        </div>
    </div>

    @if ($filters->problems !== [])
        <ul class="lf-problems" role="alert">
            @foreach ($filters->problems as $problem)
                <li>{{ $problem }}</li>
            @endforeach
        </ul>
    @endif
</form>
