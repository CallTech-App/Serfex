<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('İki adımlı doğrulama · Servik') }}</title>
        <script>
            try { const t = localStorage.getItem('servik-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="grid min-h-screen place-items-center px-4 py-10">
        <main class="w-full max-w-sm" x-data="{ recovery: {{ $errors->has('code') && strlen((string) old('code')) > 7 ? 'true' : 'false' }} }">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <x-logo />
                <span class="text-[19px] font-semibold tracking-tight">servik</span>
            </div>

            <form method="POST" action="{{ route('login.two-factor') }}" class="rounded-xl border border-line bg-surface">
                @csrf
                <h1 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('İki adımlı doğrulama') }}</h1>
                <div class="space-y-4 p-5 text-[13.5px]">
                    <p class="text-muted" x-show="! recovery">{{ __('Doğrulama uygulamanızdaki 6 haneli kodu yazın.') }}</p>
                    <p class="text-muted" x-show="recovery" x-cloak>{{ __('Telefonunuz yanınızda değilse kurtarma kodlarınızdan birini yazın. Her kod bir kez kullanılır.') }}</p>
                    <div>
                        <label for="code" class="mb-1.5 block" x-text="recovery ? @js(__('Kurtarma kodu')) : @js(__('Doğrulama kodu'))">{{ __('Doğrulama kodu') }}</label>
                        <input id="code" name="code" required autofocus autocomplete="one-time-code" spellcheck="false"
                               :inputmode="recovery ? 'text' : 'numeric'" :placeholder="recovery ? 'XXXX-XXXX' : '123456'"
                               class="h-9 w-full rounded-md border bg-surface px-3 font-mono tracking-widest outline-none focus:border-accent {{ $errors->has('code') ? 'border-crit' : 'border-line' }}">
                        @error('code')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="button" @click="recovery = ! recovery; $nextTick(() => document.getElementById('code').focus())" class="text-[13px] text-accent hover:underline"
                            x-text="recovery ? @js(__('Uygulamadaki kodu kullan')) : @js(__('Kurtarma kodu kullan'))">{{ __('Kurtarma kodu kullan') }}</button>
                </div>
                <div class="flex items-center gap-3 border-t border-line px-5 py-3">
                    <a href="{{ route('login') }}" class="text-[13px] text-muted hover:text-fg">{{ __('Geri') }}</a>
                    <button type="submit" class="ml-auto h-9 rounded-md bg-accent px-4 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Doğrula') }}</button>
                </div>
            </form>
            <x-locale-links />
        </main>
    </body>
</html>
