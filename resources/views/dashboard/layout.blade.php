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
</head>
<body class="lf">
    <header class="lf-header">
        <a class="lf-brand" href="{{ route('laraflow.dashboard') }}">
            <span class="lf-brand__mark" aria-hidden="true"></span>
            Laraflow
        </a>
        <nav class="lf-nav" aria-label="Dashboard">
            <a href="{{ route('laraflow.dashboard') }}" @class(['lf-nav__link', 'is-active' => request()->routeIs('laraflow.dashboard')])>Overview</a>
            <a href="{{ route('laraflow.executions') }}" @class(['lf-nav__link', 'is-active' => request()->routeIs('laraflow.executions', 'laraflow.executions.*')])>Executions</a>
        </nav>
        @isset($now)
            <time class="lf-header__clock" datetime="{{ $now->format(DATE_ATOM) }}">{{ $now->format('Y-m-d H:i:s') }}</time>
        @endisset
    </header>

    <main class="lf-main">
        @yield('content')
    </main>

    <footer class="lf-footer">
        Every row here is read through Laraflow's public <code>ExecutionRepository</code> contract — the same one the console uses.
    </footer>
</body>
</html>
