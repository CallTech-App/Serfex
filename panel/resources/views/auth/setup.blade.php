<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="no-referrer">
        <title>{{ __('Kurulum · Serfex') }}</title>
        <script>
            try { const t = localStorage.getItem('serfex-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}
        </script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="grid min-h-screen place-items-center px-4 py-10">
        <main class="w-full max-w-md">
            <div class="mb-6 flex items-center justify-center gap-2.5">
                <x-logo />
                <span class="text-[19px] font-semibold tracking-tight">serfex</span>
            </div>

            <form method="POST" action="{{ url('/install/'.$token) }}" class="rounded-xl border border-line bg-surface">
                @csrf
                <div class="border-b border-line px-5 py-4">
                    <p class="text-2xs font-medium tracking-wide text-muted uppercase">{{ __('Kurulum · 1. adım') }}</p>
                    <h1 class="mt-1 text-[16px] font-semibold">{{ __('Yönetici hesabını oluşturun') }}</h1>
                    <p class="mt-1 text-[13px] text-muted">{{ __('Panele bundan sonra bu e-posta ve parolayla girersiniz. Sonraki adımlarda sunucunun temel ayarlarını yaparız.') }}</p>
                </div>
                <div class="space-y-4 p-5 text-[13.5px]">
                    <div>
                        <label for="locale" class="mb-1.5 block">{{ __('Panelin dili') }}</label>
                        <select id="locale" name="locale" data-url="{{ request()->fullUrlWithQuery(['lang' => '__LANG__']) }}" onchange="location.href = this.dataset.url.replace('__LANG__', this.value)" class="h-9 w-full rounded-md border border-line bg-surface px-3 outline-none focus:border-accent">
                            @foreach (\App\Support\Locales::all() as $code => $name)
                                <option value="{{ $code }}" @selected(old('locale', app()->getLocale()) === $code)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-2xs text-muted">{{ __('Sunucunun varsayılan dili olur; her yönetici ve müşteri kendi dilini ayrıca seçebilir.') }}</p>
                    </div>
                    <div>
                        <label for="name" class="mb-1.5 block">{{ __('Ad soyad') }}</label>
                        <input id="name" name="name" value="{{ old('name') }}" required autofocus autocomplete="name"
                               class="h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('name') ? 'border-crit' : 'border-line' }}">
                        @error('name')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="email" class="mb-1.5 block">{{ __('E-posta') }}</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username"
                               class="h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('email') ? 'border-crit' : 'border-line' }}">
                        @error('email')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Bildirimler de bu adrese gelir.') }}</p>@enderror
                    </div>
                    <div>
                        <label for="password" class="mb-1.5 block">{{ __('Parola') }}</label>
                        <input id="password" name="password" type="password" required autocomplete="new-password"
                               class="h-9 w-full rounded-md border bg-surface px-3 outline-none focus:border-accent {{ $errors->has('password') ? 'border-crit' : 'border-line' }}">
                        @error('password')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('En az 12 karakter. Sunucunun root parolasını kullanmayın.') }}</p>@enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="mb-1.5 block">{{ __('Parola (tekrar)') }}</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                               class="h-9 w-full rounded-md border border-line bg-surface px-3 outline-none focus:border-accent">
                    </div>
                </div>
                <div class="border-t border-line px-5 py-3">
                    <button type="submit" class="h-9 w-full rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Hesabı oluştur ve devam et') }}</button>
                </div>
            </form>
            <p class="mt-4 text-center text-2xs text-muted">{{ __('Bu bağlantı yalnızca bir kez kullanılır.') }}</p>
            <x-locale-links />
        </main>
    </body>
</html>
