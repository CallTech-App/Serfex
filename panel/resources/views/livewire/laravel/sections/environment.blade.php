@php
    use App\Services\Laravel\EnvFile;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2';
    $input = 'h-8 w-full rounded-md border bg-surface px-2.5 text-[13px] outline-none focus:border-accent';
@endphp
<form wire:submit="save" x-data="{ q: '' }">
    @if ($agentError)
        <p class="border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px] text-crit">{{ $agentError }}</p>
    @endif
    @if ($original === null)
        <p class="px-5 py-6 text-[13.5px] text-muted">.env okunamadı.</p>
    @else
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            @if ($mode === 'rows')
                <button type="button" wire:click="addRow" class="{{ $btn }}"><x-icon name="plus" />Değişken ekle</button>
            @endif
            @if ($example !== null)
                <button type="button" wire:click="$toggle('comparing')" class="{{ $btn }}"><x-icon name="git-compare" />.env.example ile karşılaştır
                    @if (count($diff['missing']))<span class="rounded-full bg-warn-soft px-1.5 text-2xs font-medium text-warn">{{ count($diff['missing']) }}</span>@endif
                </button>
            @endif
            <button type="button" wire:click="$set('showingHistory', true)" class="{{ $btn }}"><x-icon name="history" />Geçmiş</button>
            @if ($canConnectRedis)
                <button type="button" wire:click="connectRedis" wire:loading.attr="disabled" wire:target="connectRedis" class="{{ $btn }}"><x-icon name="zap" />Redis bağla</button>
            @elseif ($app->redis_port)
                <span class="inline-flex h-9 items-center gap-1.5 text-[13px] text-muted" title="Uygulamanın kendi Redis örneği"><x-icon name="zap" class="size-3.5" />Redis <span class="font-mono">127.0.0.1:{{ $app->redis_port }}</span></span>
            @endif
            @if ($canCreateDatabase)
                <button type="button" wire:click="createDatabase" wire:loading.attr="disabled" wire:target="createDatabase" class="{{ $btn }}"><x-icon name="database" />Veritabanı oluştur ve bağla</button>
            @endif
            <div class="inline-flex rounded-md border border-line p-0.5 text-[13px]" role="tablist" aria-label="Görünüm">
                @foreach (['rows' => 'Tablo', 'text' => 'Metin'] as $key => $label)
                    <button type="button" role="tab" wire:click="$set('mode', '{{ $key }}')" aria-selected="{{ $mode === $key ? 'true' : 'false' }}"
                            class="h-7 rounded px-2.5 {{ $mode === $key ? 'bg-accent-soft font-medium text-accent' : 'text-muted hover:text-fg' }}">{{ $label }}</button>
                @endforeach
            </div>
            @if ($mode === 'rows')
                <label class="relative ml-auto w-full sm:w-56">
                    <span class="sr-only">Değişken ara</span>
                    <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                    <input type="search" x-model="q" placeholder="Ara" class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                </label>
            @endif
        </div>

        @if ($missing)
            <p class="border-b border-line bg-warn-soft/40 px-5 py-2.5 text-[13px]">Uygulamanın <span class="font-mono">.env</span> dosyası yok. Kaydettiğinizde oluşturulur.</p>
        @endif

        @if ($comparing && $example !== null)
            <div class="space-y-1 border-b border-line bg-warn-soft/40 px-5 py-3 text-[13px]">
                @if ($diff['missing'] === [] && $diff['extra'] === [])
                    <p>.env ve .env.example aynı değişkenleri içeriyor.</p>
                @endif
                @if ($diff['missing'])
                    <p><span class="font-medium">.env içinde eksik:</span> <span class="font-mono">{{ implode(', ', $diff['missing']) }}</span>
                        @if ($mode === 'rows')<button type="button" wire:click="addMissing" class="ml-1 text-accent hover:underline">.env.example değerleriyle ekle</button>@endif</p>
                @endif
                @if ($diff['extra'])
                    <p class="text-muted"><span class="font-medium text-fg">Yalnızca .env içinde:</span> <span class="font-mono">{{ implode(', ', $diff['extra']) }}</span> (.env.example dosyasına eklemeyi unutmayın)</p>
                @endif
            </div>
        @endif

        @if ($mode === 'rows')
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[560px] text-[13px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="w-[38%] py-2.5 pr-3 pl-5 font-medium">Anahtar</th>
                            <th class="px-3 py-2.5 font-medium">Değer</th>
                            <th class="px-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line font-mono">
                        @forelse ($rows as $i => $row)
                            @php $secret = EnvFile::isSecret($row['key']); @endphp
                            <tr wire:key="env-{{ $i }}" x-show="! q || @js(strtolower($row['key'])).includes(q.toLowerCase())">
                                <td class="py-2 pr-3 pl-5">
                                    <input wire:model="rows.{{ $i }}.key" aria-label="Anahtar" placeholder="YENI_ANAHTAR" spellcheck="false" class="{{ $input }} uppercase {{ $errors->has('rows.'.$i.'.key') ? 'border-crit' : 'border-line' }}">
                                    @error('rows.'.$i.'.key')<p class="mt-1 font-sans text-2xs text-crit">{{ $message }}</p>@enderror
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2" x-data="{ shown: false }">
                                        <input wire:model="rows.{{ $i }}.value" @if ($secret) :type="shown ? 'text' : 'password'" type="password" autocomplete="off" @endif aria-label="{{ $row['key'] }} değeri" spellcheck="false" class="{{ $input }} min-w-0 border-line">
                                        @if ($secret)
                                            <button type="button" @click="shown = ! shown" :aria-label="shown ? 'Gizle' : 'Göster'" class="text-muted hover:text-fg"><x-icon name="eye" x-show="! shown" /><x-icon name="eye-off" x-show="shown" x-cloak /></button>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-right font-sans"><button type="button" wire:click="removeRow({{ $i }})" class="text-[13px] text-muted hover:text-crit">Sil</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-5 py-8 text-center font-sans text-muted">Değişken yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="p-5">
                <textarea wire:model="text" rows="22" spellcheck="false" aria-label=".env"
                          class="w-full rounded-md border border-line bg-surface-2 p-3 font-mono text-[12.5px] outline-none focus:border-accent"></textarea>
                @error('text')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3">
            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Kaydet</button>
            <button type="button" wire:click="load" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
            <span class="text-2xs text-muted">Gizli değerler maskelenir. Kaydedince önceki hâli geçmişe yazılır, uygulama yapılandırmayı önbellekliyorsa <span class="font-mono">config:cache</span> yenilenir ve kuyruk işçileri yeniden başlar.</span>
        </div>
    @endif

    {{-- Geçmiş --}}
    <x-modal title=".env geçmişi" show="showingHistory">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">.env geçmişi</h2>
        <ul class="scroll-thin max-h-96 divide-y divide-line overflow-y-auto px-5 text-[13.5px]">
            @forelse ($history as $v)
                <li wire:key="envv-{{ $v->id }}" class="flex items-center gap-3 py-3">
                    <div class="min-w-0">
                        <p class="truncate">{{ $v->summary }}</p>
                        <p class="text-2xs text-muted">{{ \App\Support\When::withTime($v->created_at) }}@if ($v->user_name) · {{ $v->user_name }}@endif</p>
                    </div>
                    <button type="button" wire:click="restore({{ $v->id }})" wire:confirm="Bu değişiklikten önceki .env geri yüklensin mi?" class="ml-auto shrink-0 text-[13px] text-accent hover:underline">Öncesine dön</button>
                </li>
            @empty
                <li class="py-6 text-center text-muted">Panelden henüz kayıt yapılmadı.</li>
            @endforelse
        </ul>
        <div class="flex justify-end border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('showingHistory', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Kapat</button>
        </div>
    </x-modal>
</form>
