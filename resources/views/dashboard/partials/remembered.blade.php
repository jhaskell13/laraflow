{{--
    What the execution has remembered: step, key, status, and when.
    Values are never rendered — they are the results of side effects
    and may carry anything (docs/idempotency.md). A pending record is an
    unresolved outcome, and is said so.

    @var \Laraflow\Workflow\IdempotencyRecord[] $remembered
    @var array<int, string> $stepNamesByPosition
--}}
@use('Laraflow\Support\Formats')
@use('Laraflow\Workflow\IdempotencyStatus')

<div class="lf-table-wrap">
    <table class="lf-table">
        <thead>
            <tr>
                <th scope="col">Step</th>
                <th scope="col">Key</th>
                <th scope="col">Status</th>
                <th scope="col">Began</th>
                <th scope="col">Settled</th>
                <th scope="col">Error</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($remembered as $record)
                <tr>
                    <td>{{ isset($stepNamesByPosition[$record->stepPosition]) ? class_basename($stepNamesByPosition[$record->stepPosition]) : 'position '.$record->stepPosition }}</td>
                    <td class="lf-mono">{{ $record->key }}</td>
                    <td>
                        @if ($record->status === IdempotencyStatus::Pending)
                            <span class="lf-status lf-status--running">pending</span> <span class="lf-warn">outcome unknown</span>
                        @else
                            <span class="lf-status lf-status--{{ $record->status === IdempotencyStatus::Settled ? 'completed' : 'failed' }}">{{ $record->status->value }}</span>
                        @endif
                    </td>
                    <td>{{ Formats::date($record->startedAt) }}</td>
                    <td>{{ $record->settledAt !== null ? Formats::date($record->settledAt) : '' }}</td>
                    <td class="lf-mono lf-muted">{{ $record->errorClass ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
