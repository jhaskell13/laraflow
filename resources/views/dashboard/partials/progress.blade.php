{{--
    Steps done over steps planned, as `laraflow:list` prints it. Null
    means the repository returned no row for this execution: no plan,
    which is told apart from "nothing done yet" on purpose.

    @var array{total: int, completed: int, skipped: int}|null $counts
--}}
@if ($counts === null)
    <span class="lf-muted">—</span>
@else
    <span class="lf-progress" title="{{ $counts['completed'] }} completed{{ $counts['skipped'] > 0 ? ', '.$counts['skipped'].' skipped' : '' }} of {{ $counts['total'] }}">
        {{ $counts['completed'] + $counts['skipped'] }}<span class="lf-muted">/{{ $counts['total'] }}</span>
    </span>
@endif
