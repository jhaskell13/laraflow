{{--
    The one layout every dashboard page extends. Server-rendered, works
    without script, styled by the single stylesheet the package serves.
    See docs/dashboard.md.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · Laraflow</title>
    <link rel="stylesheet" href="{{ route('laraflow.assets.stylesheet') }}">
    <script src="{{ route('laraflow.assets.script') }}" defer></script>
</head>
@php($refresh = max(0, (int) config('laraflow.dashboard.refresh', 10)))
<body class="lf"{!! $refresh > 0 ? ' data-lf-refresh="'.$refresh.'"' : '' !!}>
    <header class="lf-header">
        <a class="lf-brand" href="{{ route('laraflow.dashboard') }}">
            <span class="lf-brand__mark" aria-hidden="true"></span>
            Laraflow
        </a>
        <nav class="lf-nav" aria-label="Dashboard">
            <a href="{{ route('laraflow.dashboard') }}" @class(['lf-nav__link', 'is-active' => request()->routeIs('laraflow.dashboard')])>Overview</a>
            <a href="{{ route('laraflow.executions') }}" @class(['lf-nav__link', 'is-active' => request()->routeIs('laraflow.executions', 'laraflow.executions.*')])>Executions</a>
        </nav>
        <div class="lf-header__right">
            @if ($refresh > 0)
                {{-- Shown by the script, which owns its state; without script there is nothing to toggle. --}}
                <button type="button" class="lf-live" data-lf-refresh-toggle hidden aria-pressed="true">Live · {{ $refresh }}s</button>
            @endif
            @isset($now)
                <time class="lf-header__clock" datetime="{{ $now->format(DATE_ATOM) }}">{{ $now->format('Y-m-d H:i:s') }}</time>
            @endisset
        </div>
    </header>

    <main class="lf-main">
        @if (request()->hasSession() && session()->has(\Laraflow\Dashboard\Controllers\ActionController::FLASH))
            @php($flash = session(\Laraflow\Dashboard\Controllers\ActionController::FLASH))
            <div class="lf-notice lf-notice--{{ $flash['level'] }} lf-flash" role="status">{{ $flash['message'] }}</div>
        @endif

        @yield('content')
    </main>

    <footer class="lf-footer">
        Every row here is read through Laraflow's public <code>ExecutionRepository</code> contract — the same one the console uses.
    </footer>
</body>
</html>
