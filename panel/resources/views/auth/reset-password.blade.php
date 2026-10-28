<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="no-referrer">
        <title>{{ __('Yeni parola · Serfex') }}</title>
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

            @if (! $valid)
                <div class="rounded-xl border border-line bg-surface">
                    <h1 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Bağlantı çalışmıyor') }}</h1>
                    <p class="p-5 text-[13.5px] text-muted" role="status">{{ __('Bu sıfırlama bağlantısı geçersiz ya da süresi dolmuş. Yeni bir bağlantı isteyin.') }}</p>
                    <div class="border-t border-line px-5 py-3">
                        <a href="{{ route('password.request') }}" class="flex h-9 w-full items-center justify-center rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Yeni bağlantı iste') }}</a>
                    </div>
                </div>
            @else
                <form method="POST" action="{{ route('password.update') }}" class="rounded-xl border border-line bg-surface">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="email" value="{{ $email }}">
                    <h1 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Yeni parola belirleyin') }}</h1>
                    <div class="space-y-4 p-5 text-[13.5px]">
                        <p class="text-muted"><x-t text=":email hesabı için yeni parolayı yazın. Kaydettiğinizde açık oturumlarınız kapanır."><x-slot:email><span class="font-mono text-fg">{{ $email }}</span></x-slot:email></x-t></p>
                        <input type="email" value="{{ $email }}" autocomplete="username" class="hidden" readonly tabindex="-1" aria-hidden="true">
                        <div>
                            <label for="password" class="mb-1.5 block">{{ __('Yeni parola') }}</label>
                            <input id="password" name="password" type="password" required autofocus autocomplete="new-password"
                                   class="h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('password') ? 'border-crit' : 'border-line' }}">
                            @error('password')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ $minLength === 12 ? __('En az 12 karakter.') : __('En az 10 karakter.') }}</p>@enderror
                        </div>
                        <div>
                            <label for="password_confirmation" class="mb-1.5 block">{{ __('Parola (tekrar)') }}</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                                   class="h-9 w-full rounded-md border border-line bg-surface px-3 outline-none focus:border-accent">
                        </div>
                    </div>
                    <div class="border-t border-line px-5 py-3">
                        <button type="submit" class="h-9 w-full rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Parolayı kaydet') }}</button>
                    </div>
                </form>
            @endif
            <x-locale-links />
        </main>
    </body>
</html>
