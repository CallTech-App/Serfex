{{-- The panel's own error pages. Self-contained like errors::updating, so
     they render even when the built assets or the session are missing. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }} · Serfex</title>
        <x-favicon />
        <script>
            try { const t = localStorage.getItem('serfex-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
        </script>
        <style>
            :root { color-scheme: light dark; --bg: #f6f7f9; --fg: #16181d; --muted: #5d6470; --line: #e3e6eb; --surface: #fff; --accent: #0f8b8d; }
            :root[data-theme='light'] { color-scheme: light; }
            @media (prefers-color-scheme: dark) { :root:not([data-theme='light']) { --bg: #0f1115; --fg: #e8eaee; --muted: #9aa1ad; --line: #262a33; --surface: #161920; --accent: #3cc3c4; } }
            :root[data-theme='dark'] { color-scheme: dark; --bg: #0f1115; --fg: #e8eaee; --muted: #9aa1ad; --line: #262a33; --surface: #161920; --accent: #3cc3c4; }
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--fg); font: 14px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
            main { width: 100%; max-width: 420px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); padding: 28px 24px; text-align: center; }
            .code { margin: 0 0 8px; font: 600 13px/1 ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--muted); letter-spacing: .06em; }
            h1 { margin: 0 0 6px; font-size: 17px; font-weight: 600; }
            p { margin: 0; color: var(--muted); }
            a { display: inline-block; margin-top: 18px; color: var(--accent); text-decoration: none; font-weight: 500; }
            a:hover { text-decoration: underline; }
        </style>
    </head>
    <body>
        <main>
            <p class="code">{{ $code }}</p>
            <h1>{{ $title }}</h1>
            <p>{{ $text }}</p>
            <a href="{{ url('/') }}">{{ __('Panele dön') }}</a>
        </main>
    </body>
</html>
