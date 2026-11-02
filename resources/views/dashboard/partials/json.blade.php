{{--
    A persisted value, pretty-printed. Never raw: a value that is not
    JSON-renderable says so rather than leaking its PHP shape, as the
    console's formatter does.

    @var mixed $value
--}}
@php
    $json = $value === null ? null : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp
@if ($value === null)
    <p class="lf-muted">{{ \Laraflow\Support\Formats::NONE }}</p>
@elseif ($json === false)
    <p class="lf-muted">(unrenderable)</p>
@else
    <pre class="lf-code lf-json">{{ $json }}</pre>
@endif
