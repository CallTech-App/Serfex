<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="no-referrer">
        <title>{{ __('Kurulum bağlantısı · Serfex') }}</title>
        <x-favicon />
        <script>
            try { const t = localStorage.getItem('serfex-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="grid min-h-screen place-items-center px-4 py-10">
        <main class="w-full max-w-md">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <x-logo class="size-8" />
                <span class="text-[20px] font-semibold tracking-[-0.015em]">serfex</span>
            </div>
            <div class="rounded-xl border border-line bg-surface">
                <h1 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">
                    {{ $used ? __('Bu panel zaten kuruldu') : __('Kurulum bağlantısı geçersiz') }}
                </h1>
                <div class="space-y-3 p-5 text-[13.5px]">
                    @if ($used)
                        <p class="text-muted">{{ __('Yönetici hesabı oluşturuldu; kurulum bağlantısı artık çalışmaz. Kendi e-posta ve parolanızla giriş yapın.') }}</p>
                    @else
                        <p class="text-muted">{{ __('Bağlantının süresi dolmuş ya da yerine yenisi oluşturulmuş. Sunucuya SSH ile bağlanıp yeni bir bağlantı alın:') }}</p>
                        <pre class="rounded-md bg-surface-2 px-3 py-2 font-mono text-[13px]">sudo serfex setup-url</pre>
                    @endif
                </div>
                <div class="border-t border-line px-5 py-3">
                    <a href="{{ route('login') }}" class="flex h-9 w-full items-center justify-center rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Giriş sayfasına git') }}</a>
                </div>
            </div>
            <x-locale-links />
        </main>
    </body>
</html>
