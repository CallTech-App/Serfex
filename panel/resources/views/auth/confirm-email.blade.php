<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="referrer" content="no-referrer">
        <title>{{ __('E-posta adresini doğrula · Serfex') }}</title>
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

            @if ($user === null)
                <div class="rounded-xl border border-line bg-surface">
                    <h1 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Bağlantı çalışmıyor') }}</h1>
                    <p class="p-5 text-[13.5px] text-muted" role="status">{{ $error ?? __('Bu doğrulama bağlantısı geçersiz, kullanılmış ya da süresi dolmuş. Panelde Hesap bilgileri sayfasından yeni bir bağlantı isteyin.') }}</p>
                    <div class="border-t border-line px-5 py-3">
                        <a href="{{ route('account.profile') }}" class="flex h-9 w-full items-center justify-center rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Hesap bilgilerine git') }}</a>
                    </div>
                </div>
            @else
                <form method="POST" action="{{ route('account.email.confirm', ['token' => $token]) }}" class="rounded-xl border border-line bg-surface">
                    @csrf
                    <h1 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('E-posta adresini doğrulayın') }}</h1>
                    <div class="space-y-3 p-5 text-[13.5px]">
                        <p><x-t text=":name hesabının e-posta adresi :email olacak."><x-slot:name><span class="font-medium">{{ $user->name }}</span></x-slot:name><x-slot:email><span class="font-mono">{{ $user->pending_email }}</span></x-slot:email></x-t></p>
                        <p class="text-muted"><x-t text="Bundan sonra bu adresle giriş yapılacak; parola sıfırlama bağlantıları ve panelin bildirimleri bu adrese gelecek. Şimdiki adres :old artık kullanılmayacak."><x-slot:old><span class="font-mono text-fg">{{ $user->email }}</span></x-slot:old></x-t></p>
                    </div>
                    <div class="border-t border-line px-5 py-3">
                        <button type="submit" class="h-9 w-full rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Adresi doğrula') }}</button>
                    </div>
                </form>
            @endif
            <x-locale-links />
        </main>
    </body>
</html>
