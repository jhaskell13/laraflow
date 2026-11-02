{{--
    The executions list: what `laraflow:list` prints, as a table.

    @var \Laraflow\Workflow\ExecutionPage $page
    @var int $total
    @var array<int, array{total: int, completed: int, skipped: int}> $progress
    @var \DateTimeImmutable $now
--}}
@use('Laraflow\Support\Formats')

@extends('laraflow::dashboard.layout')

@section('title', 'Executions')

@section('content')
    <div class="lf-page-head">
        <h1 class="lf-title">Executions</h1>
        <p class="lf-muted">
            @if ($total === 0)
                No executions yet.
            @else
                Showing {{ count($page) }} of {{ number_format($total) }} execution{{ $total === 1 ? '' : 's' }}, newest first.
            @endif
        </p>
    </div>

    @if ($page->isEmpty())
        <div class="lf-empty">
            <p>Nothing has run. Start something and it appears here:</p>
            <pre class="lf-code">php artisan laraflow:start App\Workflows\YourWorkflow --input='{"key": "value"}'</pre>
            <p class="lf-muted">Or the bundled demo: <code>php artisan laraflow:demo</code></p>
        </div>
    @else
        <div class="lf-table-wrap">
            <table class="lf-table">
                <thead>
                    <tr>
                        <th scope="col" class="lf-num">Id</th>
                        <th scope="col">Workflow</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="lf-num">Steps</th>
                        <th scope="col">Age</th>
                        <th scope="col">Duration</th>
                        <th scope="col">Correlation</th>
                        <th scope="col">Tenant</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($page as $execution)
                        <tr>
                            <td class="lf-num"><span class="lf-id">{{ $execution->id }}</span></td>
                            <td>
                                <span class="lf-workflow" title="{{ $execution->workflow }}">{{ class_basename($execution->workflow) }}</span>
                                @if ($execution->parentExecutionId !== null)
                                    <span class="lf-tag" title="A sub-workflow of execution {{ $execution->parentExecutionId }}">child of {{ $execution->parentExecutionId }}</span>
                                @endif
                                @if ($execution->planVersion > 1)
                                    <span class="lf-tag" title="Plan version">v{{ $execution->planVersion }}</span>
                                @endif
                            </td>
                            <td>@include('laraflow::dashboard.partials.status', ['status' => $execution->status])</td>
                            <td class="lf-num">
                                @include('laraflow::dashboard.partials.progress', ['counts' => $progress[$execution->id] ?? null])
                            </td>
                            <td>
                                <time datetime="{{ $execution->createdAt?->format(DATE_ATOM) }}" title="Created {{ Formats::date($execution->createdAt) }}">{{ Formats::age($execution->createdAt, $now) }}</time>
                            </td>
                            <td>{{ Formats::duration($execution->durationMs()) }}</td>
                            <td class="lf-mono">{{ $execution->correlationId ?? Formats::NONE }}</td>
                            <td class="lf-mono">{{ $execution->tenantId ?? Formats::NONE }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <nav class="lf-pagination" aria-label="Pages">
            @if (request()->query('cursor'))
                <a class="lf-button" href="{{ route('laraflow.executions') }}">« First page</a>
            @endif
            @if ($page->hasMore())
                <a class="lf-button" href="{{ route('laraflow.executions', ['cursor' => $page->nextCursor]) }}" rel="next">Next page »</a>
            @else
                <span class="lf-muted">End of list</span>
            @endif
        </nav>
    @endif
@endsection
