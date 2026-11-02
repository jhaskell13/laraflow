{{--
    Nothing to show, and why: no executions at all, or none matching.

    @var \Laraflow\Dashboard\Support\FilterRequest $filters
--}}
<div class="lf-empty">
    @if ($filters->isEmpty())
        <p>Nothing has run. Start something and it appears here:</p>
        <pre class="lf-code">php artisan laraflow:start App\Workflows\YourWorkflow --input='{"key": "value"}'</pre>
        <p class="lf-muted">Or the bundled demo: <code>php artisan laraflow:demo</code></p>
    @else
        <p>No execution matches every filter above.</p>
    @endif
</div>
