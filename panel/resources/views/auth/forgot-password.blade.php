<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Parolamı unuttum · Serfex') }}</title>
        <x-favicon />
        <script>
            try { const t = localStorage.getItem('serfex-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="grid min-h-screen place-items-center px-4 py-10">
        <main class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <x-logo class="size-8" />
                <span class="text-[20px] font-semibold tracking-[-0.015em]">serfex</span>
            </div>

            @if ($mailProblem)
                <div class="rounded-xl border border-line bg-surface">
                    <h1 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Parolanızı sıfırlayın') }}</h1>
                    <div class="space-y-3 p-5 text-[13.5px]" role="status">
                        <p>{{ __('Bu sunucu şu an e-posta gönderemiyor, bu yüzden sıfırlama bağlantısı e-postayla gelemez.') }}</p>
                        <p class="text-muted"><x-t text="Sunucu yöneticisiyseniz SSH ile bağlanıp :code komutuyla bir sıfırlama bağlantısı alın."><x-slot:code><code class="font-mono text-fg">sudo serfex reset-password {{ old('email') ?: '<'.__('e-posta').'>' }}</code></x-slot:code></x-t></p>
                        <p class="text-muted">{{ __('Müşteriyseniz ya da sunucuya erişiminiz yoksa sunucu yöneticinizden bağlantıyı isteyin.') }}</p>
                    </div>
                    <div class="border-t border-line px-5 py-3">
                        <a href="{{ route('login') }}" class="flex h-9 w-full items-center justify-center rounded-md border border-line px-3.5 text-[13.5px] font-medium hover:bg-surface-2">{{ __('Girişe dön') }}</a>
                    </div>
                </div>
            @else
                <form method="POST" action="{{ route('password.email') }}" class="rounded-xl border border-line bg-surface">
                    @csrf
                    <h1 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Parolanızı sıfırlayın') }}</h1>
                    <div class="space-y-4 p-5 text-[13.5px]">
                        @if (session('status'))
                            <p class="rounded-md bg-ok-soft px-3 py-2 text-[13px]" role="status">{{ session('status') }}</p>
                        @else
                            <p class="text-muted">{{ __('Panele giriş yaptığınız e-posta adresini yazın; yeni parola belirlemeniz için bir bağlantı gönderelim. Bağlantı :minutes dakika geçerlidir.', ['minutes' => $minutes]) }}</p>
                        @endif
                        <div>
                            <label for="email" class="mb-1.5 block">{{ __('E-posta') }}</label>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                                   class="h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('email') ? 'border-crit' : 'border-line' }}">
                            @error('email')
                                <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                    <div class="border-t border-line px-5 py-3">
                        <button type="submit" class="h-9 w-full rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Sıfırlama bağlantısı gönder') }}</button>
                    </div>
                </form>
                <p class="mt-4 text-center text-[13px]"><a href="{{ route('login') }}" class="text-accent hover:underline">{{ __('Girişe dön') }}</a></p>
            @endif
            <x-locale-links />
        </main>
    </body>
</html>
