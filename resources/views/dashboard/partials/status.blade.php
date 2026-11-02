{{--
    One status, as a badge. The colours follow the console's
    coloredStatus(): green done, red failed, yellow running, cyan for
    the two parked states, magenta cancelled, grey for the rest.

    @var \Laraflow\Workflow\ExecutionStatus|\Laraflow\Workflow\StepStatus $status
--}}
<span class="lf-status lf-status--{{ $status->value }}">{{ $status->value }}</span>
