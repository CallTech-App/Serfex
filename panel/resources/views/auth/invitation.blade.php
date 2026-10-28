<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Daveti kabul et · Serfex') }}</title>
        <script>
            try { const t = localStorage.getItem('serfex-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="grid min-h-screen place-items-center px-4 py-10">
        <main class="w-full max-w-sm">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <x-logo />
                <span class="text-[19px] font-semibold tracking-tight">serfex</span>
            </div>

            <form method="POST" action="{{ url('/invite/'.$token) }}" class="rounded-xl border border-line bg-surface">
                @csrf
                <h1 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Hesabınızı açın') }}</h1>
                <div class="space-y-4 p-5 text-[13.5px]">
                    <p class="text-muted"><x-t text="Laravel uygulamalarında çalışmak için davet edildiniz. Bir parola belirleyin; sonra :email ile giriş yaparsınız."><x-slot:email><span class="font-mono text-fg">{{ $user->email }}</span></x-slot:email></x-t></p>
                    <div>
                        <label for="name" class="mb-1.5 block">{{ __('Ad soyad') }}</label>
                        <input id="name" name="name" value="{{ old('name', $user->name) }}" required autocomplete="name"
                               class="h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('name') ? 'border-crit' : 'border-line' }}">
                        @error('name')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="password" class="mb-1.5 block">{{ __('Parola') }}</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password"
                               class="h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('password') ? 'border-crit' : 'border-line' }}">
                        @error('password')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('En az 10 karakter.') }}</p>@enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="mb-1.5 block">{{ __('Parola (tekrar)') }}</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                               class="h-9 w-full rounded-md border border-line bg-surface px-3 outline-none focus:border-accent">
                    </div>
                </div>
                <div class="border-t border-line px-5 py-3">
                    <button type="submit" class="h-9 w-full rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Hesabı aç') }}</button>
                </div>
            </form>
            <x-locale-links />
        </main>
    </body>
</html>
