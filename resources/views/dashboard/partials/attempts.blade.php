{{--
    The append-only attempt history `laraflow:show --attempts` prints:
    why each attempt ended, how long it took, and which failures
    scheduled retries.

    @var \Laraflow\Workflow\StepAttempt[] $attempts
    @var array<int, string> $stepNames   step id => class
--}}
@use('Laraflow\Support\Formats')

@if ($attempts === [])
    <p class="lf-muted">No finished attempts recorded yet.</p>
@else
    <div class="lf-table-wrap">
        <table class="lf-table">
            <thead>
                <tr>
                    <th scope="col">Step</th>
                    <th scope="col" class="lf-num">Attempt</th>
                    <th scope="col">Outcome</th>
                    <th scope="col">Started</th>
                    <th scope="col">Duration</th>
                    <th scope="col">Scheduled retry</th>
                    <th scope="col">Error</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($attempts as $attempt)
                    <tr>
                        <td>{{ isset($stepNames[$attempt->stepExecutionId]) ? class_basename($stepNames[$attempt->stepExecutionId]) : '(removed step)' }}</td>
                        <td class="lf-num">{{ $attempt->attempt }}</td>
                        <td><span class="lf-status lf-status--{{ $attempt->status->value }}">{{ str_replace('_', ' ', $attempt->status->value) }}</span></td>
                        <td>{{ Formats::date($attempt->startedAt) }}</td>
                        <td>{{ Formats::duration($attempt->durationMs()) }}</td>
                        <td>{{ $attempt->nextRetryAt !== null ? Formats::date($attempt->nextRetryAt) : '' }}</td>
                        <td class="lf-cell-wrap lf-error">{{ $attempt->error ?? '' }}@if ($attempt->errorClass !== null) <span class="lf-muted lf-mono">{{ $attempt->errorClass }}</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
