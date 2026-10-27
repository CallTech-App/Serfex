{{-- Shown while Servik updates itself (artisan down --render=errors::updating).
     Pre-rendered and self-contained: the release's built assets change
     during the update. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="refresh" content="10">
        <title>{{ __('Servik güncelleniyor') }}</title>
        <script>
            try { const t = localStorage.getItem('servik-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
        </script>
        <style>
            :root { color-scheme: light dark; --bg: #f6f7f9; --fg: #16181d; --muted: #5d6470; --line: #e3e6eb; --surface: #fff; --accent: #0f8b8d; }
            :root[data-theme='light'] { color-scheme: light; }
            @media (prefers-color-scheme: dark) { :root:not([data-theme='light']) { --bg: #0f1115; --fg: #e8eaee; --muted: #9aa1ad; --line: #262a33; --surface: #161920; --accent: #3cc3c4; } }
            :root[data-theme='dark'] { color-scheme: dark; --bg: #0f1115; --fg: #e8eaee; --muted: #9aa1ad; --line: #262a33; --surface: #161920; --accent: #3cc3c4; }
            * { box-sizing: border-box; }
            body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--fg); font: 14px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
            main { width: 100%; max-width: 420px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); padding: 28px 24px; text-align: center; }
            .spin { width: 28px; height: 28px; margin: 0 auto 16px; border: 3px solid var(--line); border-top-color: var(--accent); border-radius: 50%; animation: spin 1s linear infinite; }
            @keyframes spin { to { transform: rotate(360deg); } }
            h1 { margin: 0 0 6px; font-size: 17px; font-weight: 600; }
            p { margin: 0; color: var(--muted); }
        </style>
    </head>
    <body>
        <main>
            <div class="spin" aria-hidden="true"></div>
            <h1>{{ __('Servik güncelleniyor') }}</h1>
            <p>{{ __('Panel birkaç dakika içinde geri gelecek; bu sayfa kendiliğinden yenilenir. Siteleriniz ve e-postanız bu sırada çalışmaya devam eder.') }}</p>
        </main>
    </body>
</html>
