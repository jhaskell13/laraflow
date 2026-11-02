{{--
    The step table `laraflow:show` prints, with the same (overdue)
    marker on a running step past its deadline.

    @var \Laraflow\Workflow\StepExecution[] $steps
    @var \DateTimeImmutable $now
--}}
@use('Laraflow\Support\Formats')
@use('Laraflow\Workflow\AttentionScan')

<div class="lf-table-wrap">
    <table class="lf-table">
        <thead>
            <tr>
                <th scope="col" class="lf-num">#</th>
                <th scope="col">Step</th>
                <th scope="col">Status</th>
                <th scope="col" class="lf-num">Attempts</th>
                <th scope="col">Started</th>
                <th scope="col">Next retry</th>
                <th scope="col">Output</th>
                <th scope="col">Error</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($steps as $step)
                <tr>
                    <td class="lf-num">{{ $step->position }}</td>
                    <td><span title="{{ $step->step }}">{{ class_basename($step->step) }}</span></td>
                    <td>
                        @include('laraflow::dashboard.partials.status', ['status' => $step->status])
                        @if (AttentionScan::isOverdue($step, $now))
                            <span class="lf-tag lf-tag--warn">overdue</span>
                        @endif
                    </td>
                    <td class="lf-num">{{ $step->attempts }}</td>
                    <td>{{ Formats::date($step->startedAt) }}</td>
                    <td>{{ $step->nextRetryAt !== null ? Formats::date($step->nextRetryAt) : '' }}</td>
                    <td class="lf-cell-wrap">
                        @if ($step->output === null)
                            <span class="lf-muted">{{ Formats::NONE }}</span>
                        @else
                            <details class="lf-details">
                                <summary class="lf-mono">{{ Formats::value($step->output, 60) }}</summary>
                                @include('laraflow::dashboard.partials.json', ['value' => $step->output])
                            </details>
                        @endif
                    </td>
                    <td class="lf-cell-wrap lf-error">{{ $step->error ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
