{{-- A new password, shown once with what an application needs to connect. --}}
@if ($credentials)
    @php
        $env = $credentials['server'] === 'postgresql' ? 'pgsql' : 'mysql';
        $rows = array_filter([
            __('Sunucu') => '127.0.0.1',
            __('Port') => (string) $credentials['port'],
            __('Veritabanı') => $credentials['database'],
            __('Kullanıcı') => $credentials['user'],
            __('Parola') => $credentials['password'],
        ]);
        $dotenv = "DB_CONNECTION={$env}\nDB_HOST=127.0.0.1\nDB_PORT={$credentials['port']}\n".($credentials['database'] ? "DB_DATABASE={$credentials['database']}\n" : '')."DB_USERNAME={$credentials['user']}\nDB_PASSWORD={$credentials['password']}";
    @endphp
    <div x-data class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="$wire.set('credentials', null)">
        <div class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Bağlantı bilgileri') }}">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Bağlantı bilgileri') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <p class="flex gap-2 rounded-lg bg-warn-soft p-3 text-[13px]">
                    <x-icon name="key-round" class="mt-0.5 shrink-0 text-warn" />
                    <span>{{ __('Parolayı şimdi kopyalayın. Serfex parolaları saklamaz; bu pencere kapanınca bir daha gösterilmez, gerekirse yenisini belirlersiniz.') }}</span>
                </p>
                <dl class="divide-y divide-line rounded-lg border border-line">
                    @foreach ($rows as $label => $value)
                        <div class="flex items-center gap-3 px-3 py-2">
                            <dt class="w-24 shrink-0 text-muted">{{ $label }}</dt>
                            <dd class="min-w-0 flex-1 truncate font-mono text-[13px]">{{ $value }}</dd>
                            <button type="button" x-data="{ done: false }" @click="navigator.clipboard.writeText(@js($value)); done = true; setTimeout(() => done = false, 1500)"
                                    class="shrink-0 text-[12.5px] text-accent hover:underline" :aria-label="@js(__(':label kopyala', ['label' => $label]))">
                                <span x-show="!done">{{ __('Kopyala') }}</span><span x-show="done" x-cloak>{{ __('Kopyalandı') }}</span>
                            </button>
                        </div>
                    @endforeach
                </dl>
                <div>
                    <div class="mb-1.5 flex items-center justify-between">
                        <span class="text-[13px] text-muted">Laravel <span class="font-mono">.env</span></span>
                        <button type="button" x-data="{ done: false }" @click="navigator.clipboard.writeText(@js($dotenv)); done = true; setTimeout(() => done = false, 1500)" class="text-[12.5px] text-accent hover:underline">
                            <span x-show="!done">{{ __('Kopyala') }}</span><span x-show="done" x-cloak>{{ __('Kopyalandı') }}</span>
                        </button>
                    </div>
                    <pre class="overflow-x-auto rounded-lg bg-surface-2 p-3 font-mono text-[12.5px] leading-relaxed">{{ $dotenv }}</pre>
                </div>
            </div>
            <div class="flex justify-end border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('credentials', null)" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Kopyaladım, kapat') }}</button>
            </div>
        </div>
    </div>
@endif
