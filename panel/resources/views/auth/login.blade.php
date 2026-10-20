<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Giriş · Servik') }}</title>
        <script>
            try { const t = localStorage.getItem('servik-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="grid min-h-screen place-items-center px-4 py-10">
        <main class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <x-logo />
                <span class="text-[19px] font-semibold tracking-tight">servik</span>
            </div>

            <form method="POST" action="{{ route('login') }}" class="rounded-xl border border-line bg-surface">
                @csrf
                <h1 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Panele giriş yapın') }}</h1>
                <div class="space-y-4 p-5 text-[13.5px]">
                    <div>
                        <label for="email" class="mb-1.5 block">{{ __('E-posta') }}</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                               class="h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('email') ? 'border-crit' : 'border-line' }}">
                        @error('email')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="password" class="mb-1.5 block">{{ __('Parola') }}</label>
                        <input id="password" name="password" type="password" required autocomplete="current-password"
                               class="h-9 w-full rounded-md border border-line bg-surface px-3 outline-none focus:border-accent">
                        @error('password')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @enderror
                    </div>
                    <label class="flex items-center gap-2 text-[13px]">
                        <input type="checkbox" name="remember" value="1" class="size-4 accent-accent">
                        {{ __('Beni hatırla') }}
                    </label>
                </div>
                <div class="border-t border-line px-5 py-3">
                    <button type="submit" class="h-9 w-full rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Giriş yap') }}</button>
                </div>
            </form>
            <p class="mt-4 text-center text-2xs text-muted"><x-t text="Yönetici hesabı sunucuda :code ile açılır; müşteriler size iletilen giriş bağlantısıyla şifrelerini belirler."><x-slot:code><code class="font-mono">php artisan servik:admin</code></x-slot:code></x-t></p>
            <x-locale-links />
        </main>
    </body>
</html>
