@php
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $tool = 'grid size-8 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-40';
    $dir = $site->document_root ?: $account?->home;
    $labels = [
        'copied' => __('Kopyalandı'),
        'closed' => __('Oturum kapatıldı'),
        'failed' => __('Bağlantı kurulamadı'),
    ];
    $mobileKeys = [['Esc', "\e"], ['Tab', "\t"], ['←', "\e[D"], ['↑', "\e[A"], ['↓', "\e[B"], ['→', "\e[C"], ['|', '|'], ['~', '~'], ['/', '/']];
@endphp
<div class="space-y-6">
    <div>
        <x-site-header :site="$site" active="overview" :tool="__('SSH terminali')" />
        <div class="mt-6 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <h1 class="text-[18px] font-semibold tracking-tight">{{ __('SSH terminali') }}</h1>
            <a href="{{ route('sites.ssh', $site) }}" wire:navigate class="{{ $btn }}"><x-icon name="key-round" />{{ __('SSH erişimi') }}</a>
        </div>
        @if ($account)
            <p class="mt-1 max-w-3xl text-[13px] text-muted">
                <x-t text="Tarayıcıda, sitenin kullanıcısı :user olarak :dir klasöründe açılan bir kabuk. Komutlar doğrudan sunucuda çalışır ve geri alınamaz."><x-slot:user><span class="font-mono text-fg">{{ $account->name }}</span></x-slot:user><x-slot:dir><span class="font-mono text-fg">{{ $dir }}</span></x-slot:dir></x-t>
            </p>
        @endif
    </div>

    @if ($refusal)
        <section class="rounded-xl border border-line bg-surface px-5 py-8 text-center">
            <span class="mx-auto grid size-10 place-items-center rounded-full bg-surface-2 text-muted"><x-icon name="lock" /></span>
            <p class="mx-auto mt-3 max-w-md text-[13.5px]">{{ $refusal }}</p>
            @if ($account && $account->ssh_access !== \App\Services\Files\SshAccess::SHELL && auth()->user()->isFullAdmin())
                <a href="{{ route('sites.ssh', $site) }}" wire:navigate class="{{ $primary }} mx-auto mt-4 w-fit">{{ __('SSH erişimini aç') }}</a>
            @endif
        </section>
    @else
        <div x-data="webTerminal(@js($labels))" class="space-y-6">
            <section x-ref="wrap" class="relative min-w-0 overflow-hidden rounded-xl border border-line bg-surface fullscreen:rounded-none fullscreen:border-0">
                {{-- Araç çubuğu --}}
                <div class="flex h-12 items-center gap-2 border-b border-line px-3 sm:px-4">
                    <span class="flex min-w-0 items-center gap-2 text-[13px]">
                        <span class="size-2 shrink-0 rounded-full" :class="{ 'bg-ok': state === 'open', 'bg-warn animate-pulse': state === 'connecting', 'bg-muted/60': state === 'ready' || state === 'closed' }"></span>
                        <span x-show="state === 'open'" class="font-medium">{{ __('Bağlı') }}</span>
                        <span x-show="state === 'connecting'" x-cloak class="font-medium">{{ __('Bağlanıyor…') }}</span>
                        <span x-show="state === 'ready' || state === 'closed'" x-cloak class="font-medium text-muted">{{ __('Kapalı') }}</span>
                        <span class="hidden truncate font-mono text-[12.5px] text-muted sm:inline">{{ $account->name }}{{ '@' }}{{ $site->domain }}</span>
                    </span>
                    <span x-show="hint" x-cloak x-transition.opacity class="ml-1 rounded bg-ok-soft px-2 py-0.5 text-2xs text-ok" x-text="hint"></span>
                    <div class="ml-auto flex items-center gap-0.5">
                        <button type="button" @click="copy(true)" :disabled="state !== 'open'" class="{{ $tool }}" title="{{ __('Kopyala (Ctrl+C, seçiliyken)') }}" aria-label="{{ __('Kopyala') }}"><x-icon name="copy" /></button>
                        <button type="button" @click="pasteFromClipboard()" :disabled="state !== 'open'" class="{{ $tool }}" title="{{ __('Yapıştır (Ctrl+V)') }}" aria-label="{{ __('Yapıştır') }}"><x-icon name="clipboard-paste" /></button>
                        <span class="mx-1 hidden h-5 w-px bg-line sm:block"></span>
                        <button type="button" @click="zoom(-1)" class="{{ $tool }} hidden sm:grid" title="{{ __('Yazıyı küçült') }}" aria-label="{{ __('Yazıyı küçült') }}"><x-icon name="a-arrow-down" /></button>
                        <button type="button" @click="zoom(1)" class="{{ $tool }} hidden sm:grid" title="{{ __('Yazıyı büyüt') }}" aria-label="{{ __('Yazıyı büyüt') }}"><x-icon name="a-arrow-up" /></button>
                        <div class="relative" x-data="{ open: false }">
                            <button type="button" @click="open = !open" class="{{ $tool }}" title="{{ __('Terminal ayarları') }}" aria-label="{{ __('Terminal ayarları') }}"><x-icon name="settings-2" /></button>
                            <div x-show="open" x-cloak @click.outside="open = false" class="absolute top-full right-0 z-30 mt-1 w-64 rounded-lg border border-line bg-surface p-3 text-[13px] shadow-lg">
                                <label class="flex items-start gap-2.5">
                                    <input type="checkbox" x-model="copyOnSelect" class="mt-0.5 size-4 accent-accent">
                                    <span>{{ __('Seçince kopyala') }}<span class="block text-2xs text-muted">{{ __('Fareyle seçilen metin hemen panoya alınır.') }}</span></span>
                                </label>
                                <div class="mt-3 flex items-center justify-between border-t border-line pt-3 sm:hidden">
                                    <span>{{ __('Yazı boyutu') }}</span>
                                    <span class="flex gap-1">
                                        <button type="button" @click="zoom(-1)" class="{{ $tool }}" aria-label="{{ __('Yazıyı küçült') }}"><x-icon name="a-arrow-down" /></button>
                                        <button type="button" @click="zoom(1)" class="{{ $tool }}" aria-label="{{ __('Yazıyı büyüt') }}"><x-icon name="a-arrow-up" /></button>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <button type="button" @click="fullscreen()" class="{{ $tool }} hidden sm:grid" title="{{ __('Tam ekran') }}" aria-label="{{ __('Tam ekran') }}"><x-icon name="maximize-2" /></button>
                        <button type="button" x-show="state === 'open' || state === 'connecting'" x-cloak @click="stop()" class="ml-1 flex h-8 items-center gap-1.5 rounded-md border border-line px-2.5 text-[13px] hover:bg-surface-2"><x-icon name="power" class="size-3.5" />{{ __('Kapat') }}</button>
                    </div>
                </div>

                {{-- Ekran --}}
                <div class="relative bg-[#0b1012]">
                    <div x-show="idleSeconds > 0 && state === 'open'" x-cloak class="absolute inset-x-0 top-0 z-10 flex items-center justify-center gap-2 bg-warn px-3 py-1.5 text-[12.5px] font-medium text-on-warn">
                        <x-icon name="clock" class="size-3.5" />
                        <span x-text="@js(__('İşlem yapılmazsa :seconds saniye içinde kapanacak. Bir tuşa basmanız yeter.', ['seconds' => '__S__'])).replace('__S__', idleSeconds)"></span>
                    </div>
                    <div wire:ignore x-ref="screen" class="h-[clamp(380px,calc(100dvh-330px),900px)] px-2 py-2 fullscreen:h-[calc(100dvh-48px)]" @click="menu.show = false"></div>

                    {{-- Açılmadan önce ve kapandıktan sonra --}}
                    <div x-show="state === 'ready' || state === 'closed'" class="absolute inset-0 z-10 grid place-items-center bg-[#0b1012]/92 p-4">
                        <div class="max-w-md text-center text-[#e4ebec]">
                            <span class="mx-auto grid size-11 place-items-center rounded-full bg-white/8 text-[#34b8bc]"><x-icon name="square-terminal" class="size-5" /></span>
                            <p x-show="state === 'closed'" x-cloak class="mt-3 text-[14px] font-semibold" x-text="reason"></p>
                            <p x-show="state === 'ready'" class="mt-3 text-[14px] font-semibold">{{ __('Terminal kapalı') }}</p>
                            <p class="mt-1.5 text-[12.5px] leading-relaxed text-[#8c9ca0]">
                                <x-t text="Komutlar :user olarak çalışır; yaptıkları geri alınamaz. :idle dakika işlem yapılmazsa ya da :max saat dolunca oturum kapanır."><x-slot:user><span class="font-mono text-[#e4ebec]">{{ $account->name }}</span></x-slot:user><x-slot:idle>{{ $idle }}</x-slot:idle><x-slot:max>{{ intdiv($max, 60) }}</x-slot:max></x-t>
                            </p>
                            <button type="button" @click="start()" class="{{ $primary }} mx-auto mt-4">
                                <x-icon name="square-terminal" class="size-4" />
                                <span x-show="state === 'ready'">{{ __('Terminali aç') }}</span>
                                <span x-show="state === 'closed'" x-cloak>{{ __('Yeniden aç') }}</span>
                            </button>
                        </div>
                    </div>

                    {{-- Sağ tık menüsü --}}
                    <div x-show="menu.show" x-cloak @click.outside="menu.show = false" @keydown.escape.window="menu.show = false" :style="`left:${menu.x}px;top:${menu.y}px`"
                         class="absolute z-20 w-44 rounded-lg border border-line bg-surface py-1 text-[13px] text-fg shadow-lg">
                        <button type="button" @click="menu.show = false; copy(true)" :disabled="!hasSelection" class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-surface-2 disabled:opacity-40"><x-icon name="copy" class="size-3.5" />{{ __('Kopyala') }}<span class="ml-auto text-2xs text-muted">Ctrl+C</span></button>
                        <button type="button" @click="pasteFromClipboard()" class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-surface-2"><x-icon name="clipboard-paste" class="size-3.5" />{{ __('Yapıştır') }}<span class="ml-auto text-2xs text-muted">Ctrl+V</span></button>
                        <button type="button" @click="selectAll()" class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-surface-2"><x-icon name="list-checks" class="size-3.5" />{{ __('Tümünü seç') }}</button>
                        <div class="my-1 border-t border-line"></div>
                        <button type="button" @click="clearScreen()" class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-surface-2"><x-icon name="trash-2" class="size-3.5" />{{ __('Ekranı temizle') }}</button>
                    </div>
                </div>

                {{-- Telefon tuşları --}}
                <div x-show="state === 'open'" x-cloak class="flex gap-1 overflow-x-auto border-t border-line bg-surface-2 px-2 py-1.5 sm:hidden">
                    <button type="button" @click="toggleCtrl()" :class="ctrl ? 'bg-accent text-accent-fg' : 'bg-surface'" class="h-8 shrink-0 rounded-md border border-line px-2.5 font-mono text-[12.5px]">Ctrl</button>
                    @foreach ($mobileKeys as [$label, $seq])
                        <button type="button" @click="key(@js($seq))" class="h-8 min-w-9 shrink-0 rounded-md border border-line bg-surface px-2 font-mono text-[12.5px]">{{ $label }}</button>
                    @endforeach
                    <button type="button" @click="pasteFromClipboard()" class="flex h-8 shrink-0 items-center gap-1 rounded-md border border-line bg-surface px-2.5 text-[12.5px]"><x-icon name="clipboard-paste" class="size-3.5" />{{ __('Yapıştır') }}</button>
                </div>

                <p class="border-t border-line px-4 py-2.5 text-2xs text-muted">
                    {{ __('Seçtiğiniz metin kopyalanır. Ctrl+C seçim varken kopyalar, yokken çalışan komutu durdurur. Ctrl+V, Shift+Insert ya da sağ tık ile yapıştırın. Açılış ve kapanış işlem günlüğüne yazılır; yazdıklarınız kaydedilmez.') }}
                </p>

                {{-- Yapıştırma kutusu: tarayıcı panoyu okutmadığında --}}
                <div x-show="pasteBox" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="pasteBox = false">
                    <form @submit.prevent="submitPasteBox()" @click.outside="pasteBox = false" class="w-full max-w-xl rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Yapıştır') }}">
                        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Terminale yapıştır') }}</h2>
                        <div class="p-5">
                            <textarea x-ref="pasteArea" x-model="pasteValue" rows="7" spellcheck="false" autocapitalize="off" autocomplete="off" placeholder="{{ __('Metni buraya yapıştırın (Ctrl+V ya da uzun basıp Yapıştır)') }}" class="w-full rounded-md border border-line bg-surface px-3 py-2 font-mono text-[12.5px] outline-none focus:border-accent"></textarea>
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Tarayıcı panoya doğrudan erişime izin vermedi; metin buradan terminale gönderilir.') }}</p>
                        </div>
                        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                            <button type="button" @click="pasteBox = false" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                            <button type="submit" :disabled="pasteValue === ''" class="{{ $primary }}">{{ __('Terminale gönder') }}</button>
                        </div>
                    </form>
                </div>

                {{-- Çok satırlı yapıştırma onayı --}}
                <div x-show="confirmLines > 0" x-cloak class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" @keydown.escape.window="cancelPaste()">
                    <div class="w-full max-w-md rounded-xl border border-line bg-surface p-5 text-[13.5px] shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Çok satırlı yapıştırma') }}">
                        <p class="font-semibold" x-text="@js(__(':lines satır yapıştırılsın mı?', ['lines' => '__N__'])).replace('__N__', confirmLines)"></p>
                        <p class="mt-1 text-[13px] text-muted">{{ __('Terminaldeki program satırları tek tek çalıştırır: her satır, Enter\'a basılmış gibi hemen çalışır.') }}</p>
                        <pre class="mt-3 max-h-40 overflow-auto rounded-md bg-surface-2 px-3 py-2 font-mono text-[12px]" x-text="pending"></pre>
                        <div class="mt-4 flex justify-end gap-2">
                            <button type="button" @click="cancelPaste()" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                            <button type="button" @click="confirmPaste()" class="{{ $primary }}">{{ __('Yapıştır') }}</button>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Bu kullanıcının açık oturumları --}}
            @if ($open !== [])
                <section class="rounded-xl border border-line bg-surface" x-show="@js(collect($open)->pluck('id')->all()).some(id => id !== sessionId)">
                    <h2 class="flex h-11 items-center border-b border-line px-5 text-[14px] font-semibold">{{ __('Açık oturumlar') }}</h2>
                    <ul class="divide-y divide-line text-[13.5px]">
                        @foreach ($open as $session)
                            <li wire:key="session-{{ $session['id'] }}" x-show="sessionId !== @js($session['id'])" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-2.5">
                                <x-icon name="square-terminal" class="text-muted" />
                                <span class="font-medium">{{ $session['actor'] ?: __('Bilinmiyor') }}</span>
                                <span class="num font-mono text-[12.5px] text-muted">{{ $session['ip'] }}</span>
                                <span class="num text-[12.5px] text-muted">{{ $session['state'] === 'open' ? __(':time tarihinden beri açık', ['time' => \App\Support\When::dateTime(\Carbon\Carbon::parse($session['started'] ?? $session['created']))]) : __('Bağlanmayı bekliyor') }}</span>
                                <button type="button" wire:click="endSession(@js($session['id']))" class="ml-auto text-[13px] text-crit hover:underline">{{ __('Kapat') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif
</div>
