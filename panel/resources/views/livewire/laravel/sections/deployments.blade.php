@php
    use App\Livewire\Laravel\LaravelIndex;
    use App\Support\When;
    $dl = 'grid gap-1 py-3 sm:grid-cols-[230px_1fr] sm:gap-6';
    $check = 'size-4 accent-[rgb(var(--accent))]';
    $link = 'text-[13px] whitespace-nowrap text-muted hover:text-fg';
    $providerIcon = ['github' => 'github', 'gitlab' => 'gitlab'][$app->provider()] ?? 'git-branch';
@endphp
<div>
    @if ($busy)
        <div wire:poll.3s hidden></div>
    @endif
    @if ($agentError)
        <p class="border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px] text-crit">{{ $agentError }}</p>
    @endif

    <dl class="divide-y divide-line px-5 py-2 text-[13.5px]">
        <div class="{{ $dl }}">
            <dt class="text-muted">Kaynak</dt>
            <dd class="flex flex-wrap items-center gap-2">
                @if ($app->repository)
                    <x-icon :name="$providerIcon" />
                    <span class="font-mono">{{ $app->repositoryName() }}</span>
                    <span class="text-muted">· {{ $app->providerLabel() }}@if ($app->branch) · dal <span class="font-mono">{{ $app->branch }}</span>@endif</span>
                    <button type="button" wire:click="showKey" class="text-[13px] text-accent hover:underline">Dağıtım anahtarı</button>
                    <button type="button" wire:click="openConnect" class="text-[13px] text-accent hover:underline">Değiştir</button>
                @else
                    <span class="text-muted">Git deposu bağlı değil{{ $app->source === 'create' ? '; uygulama yeni proje olarak kuruldu' : '' }}.</span>
                    <button type="button" wire:click="openConnect" class="text-[13px] text-accent hover:underline">Depo bağla</button>
                @endif
            </dd>
        </div>
        <div class="{{ $dl }}">
            <dt class="text-muted">Otomatik dağıtım</dt>
            <dd class="space-y-1">
                <label class="flex items-center gap-2.5">
                    <input type="checkbox" @checked($app->auto_deploy) wire:change="setting('auto_deploy', $event.target.checked)" @disabled(! $app->repository) class="{{ $check }}">
                    <span>@if ($app->branch)<span class="font-mono">{{ $app->branch }}</span> dalına @endif push gelince dağıt</span>
                </label>
                <p class="text-2xs text-muted" x-data="{ copied: false }">
                    Webhook: <span class="font-mono break-all">{{ $app->webhookUrl() }}</span>
                    <button type="button" @click="navigator.clipboard.writeText(@js($app->webhookUrl())); copied = true; setTimeout(() => copied = false, 2000)" class="text-accent hover:underline"><span x-show="! copied">Kopyala</span><span x-show="copied" x-cloak>Kopyalandı</span></button>
                </p>
                <p class="text-2xs text-muted">Adresi deponun webhook ayarlarına (push olayı) ekleyin. CI'dan dağıtmak için aynı adrese boş bir <span class="font-mono">POST</span> gönderin; <span class="font-mono">?ref=v1.2.0</span> ile dal veya etiket seçilir.</p>
            </dd>
        </div>
        <div class="{{ $dl }}">
            <dt class="text-muted">Sıfır kesintili dağıtım</dt>
            <dd class="flex flex-wrap items-center gap-x-2 gap-y-1">
                @if ($app->isManaged())
                    <span>Açık <span class="text-muted">· her sürüm <span class="font-mono">releases/</span> altına kurulur, <span class="font-mono">current</span> bağlantısı tek adımda değişir · son</span></span>
                    <select wire:change="setting('keep_releases', $event.target.value)" aria-label="Saklanan sürüm" class="h-7 rounded-md border border-line bg-surface px-1.5 text-[13px]">
                        @foreach ([2, 3, 5, 10, 20] as $n)
                            <option value="{{ $n }}" @selected($app->keep_releases === $n)>{{ $n }}</option>
                        @endforeach
                    </select>
                    <span class="text-muted">sürüm saklanır</span>
                @else
                    <span class="text-muted">İlk dağıtımda uygulama <span class="font-mono">laravel/releases/</span> düzenine taşınır; <span class="font-mono">.env</span> ve <span class="font-mono">storage</span> {{ $app->path }} içinden kopyalanır. O ana kadar uygulama yerinde çalışır.</span>
                @endif
            </dd>
        </div>
        <div class="{{ $dl }}">
            <dt class="text-muted">Güvenlik ağı</dt>
            <dd class="space-y-1.5">
                <label class="flex items-center gap-2.5"><input type="checkbox" @checked($app->auto_rollback) wire:change="setting('auto_rollback', $event.target.checked)" class="{{ $check }}">Sağlık kontrolü başarısızsa önceki sürüme otomatik dön</label>
                <label class="flex items-center gap-2.5"><input type="checkbox" @checked($app->backup_before_deploy) wire:change="setting('backup_before_deploy', $event.target.checked)" class="{{ $check }}">Her dağıtımdan önce veritabanını yedekle</label>
                <form wire:submit="saveHealthPath" class="flex flex-wrap items-center gap-2">
                    <span class="text-muted">Sağlık adresi</span>
                    <input wire:model="healthPath" aria-label="Sağlık adresi" class="h-8 w-40 rounded-md border bg-surface px-2.5 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('healthPath') ? 'border-crit' : 'border-line' }}">
                    <button type="submit" class="h-8 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">Kaydet</button>
                    @error('healthPath')<span class="text-[13px] text-crit">{{ $message }}</span>@enderror
                </form>
                <p class="text-2xs text-muted">Geçiş sonrası bu adres 3 kez istenir; 2xx dışında yanıt gelirse kod eski sürüme döner. Veritabanı geçişleri geri alınmaz; gerekirse dağıtım öncesi yedeği <button type="button" wire:click="$dispatch('show-section', { section: 'yedekler' })" class="text-accent hover:underline">Yedekler</button> bölümünden geri yükleyin. Yedek alınamazsa dağıtım başlamaz.</p>
            </dd>
        </div>
    </dl>

    <div class="relative overflow-x-auto border-t border-line">
        <table class="w-full min-w-[720px] text-[13.5px]">
            <thead>
                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                    <th class="py-2.5 pr-3 pl-5 font-medium">Sürüm</th>
                    <th class="px-3 py-2.5 font-medium">Commit</th>
                    <th class="px-3 py-2.5 font-medium">Tetikleyen</th>
                    <th class="px-3 py-2.5 font-medium">Süre</th>
                    <th class="px-3 py-2.5 font-medium">Durum</th>
                    <th class="px-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($deployments as $d)
                    <tr wire:key="dep-{{ $d->id }}" class="hover:bg-surface-2/60">
                        <td class="py-3 pr-3 pl-5 font-mono text-[13px] whitespace-nowrap">
                            #{{ $d->number }}
                            @if ($d->release === $app->current_release && $d->status->isLive() || ($d->kind === 'rollback' && $d->release === $app->current_release && $loop->first))<span class="ml-1 font-sans text-2xs text-accent">yayında</span>@endif
                            <div class="font-sans text-2xs text-muted">{{ When::withTime($d->started_at ?? $d->created_at) }}</div>
                        </td>
                        <td class="px-3 py-3">
                            @if ($d->kind === 'rollback')
                                <span class="text-muted">releases/{{ $d->release }} sürümüne dönüş</span>
                            @else
                                @if ($d->shortCommit())<span class="font-mono text-[13px] text-accent">{{ $d->shortCommit() }}</span>@endif
                                <span class="text-muted">{{ $d->message ?? ($d->kind === 'install' ? 'Kurulum' : ($d->ref ? $d->ref.' dağıtımı' : 'Dağıtım')) }}</span>
                                @if ($d->ref)<div class="font-mono text-2xs text-muted">{{ $d->ref }}</div>@endif
                            @endif
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">{{ $d->triggered_by ?? 'Servik' }}</td>
                        <td class="num px-3 py-3 whitespace-nowrap text-muted">{{ $d->durationLabel() ?? '…' }}</td>
                        <td class="px-3 py-3">
                            <x-status-badge :status="$d->status" />
                            @if ($d->error)<p class="mt-1 max-w-xs truncate text-2xs text-muted" title="{{ $d->error }}">{{ $d->error }}</p>@endif
                        </td>
                        <td class="space-x-3 px-3 py-3 text-right whitespace-nowrap">
                            <button type="button" wire:click="showLog({{ $d->id }})" class="{{ $link }}">Günlük</button>
                            @if ($canReturn($d))
                                <button type="button" wire:click="confirmRollback({{ $d->id }})" class="{{ $link }}">Bu sürüme dön</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-muted">Henüz dağıtım yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <details class="border-t border-line px-5 py-4 text-[13px]" @if ($errors->has('hooks.*')) open @endif>
        <summary class="cursor-pointer text-accent">Dağıtım adımları ve kancalar</summary>
        <form wire:submit="saveHooks" class="mt-3 space-y-3">
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                @foreach (['before_build' => ['Derlemeden önce', 'Kod çekilip storage ve .env bağlandıktan sonra'], 'before_activate' => ['Yayına almadan önce', 'Derleme ve migrate bitti, current değişmeden hemen önce'], 'after_activate' => ['Yayına aldıktan sonra', 'current değişti; başarısız olursa geri dönülür']] as $key => [$title, $desc])
                    <label class="block">
                        <span class="font-medium">{{ $title }}</span> <span class="text-2xs text-muted">{{ $desc }}</span>
                        <textarea wire:model="hooks.{{ $key }}" rows="5" spellcheck="false" placeholder="# komut yok"
                                  class="mt-1.5 w-full rounded-md border border-line bg-surface-2 p-3 font-mono text-[12.5px] outline-none focus:border-accent"></textarea>
                    </label>
                @endforeach
            </div>
            <p class="text-2xs text-muted">Servik her dağıtımda sırayla şunları yapar: kodu çeker, <span class="font-mono">storage</span> ve <span class="font-mono">.env</span> bağlar, <span class="font-mono">composer install --no-dev</span>, ön yüz derlemesi, <span class="font-mono">migrate --force</span>, <span class="font-mono">optimize</span>, <span class="font-mono">current</span> geçişi, PHP-FPM yenileme ve işçileri yeniden başlatma. Buraya yalnızca kendi adımlarınızı yazarsınız; betikler sitenin kullanıcısıyla, sürüm dizininde <span class="font-mono">bash</span> ile çalışır. SSH gerekmez.</p>
            <button type="submit" class="h-8 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">Adımları kaydet</button>
        </form>
    </details>

    {{-- Dağıtım günlüğü --}}
    @if ($log)
        <div x-data @keydown.escape.window="$wire.closeLog()" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
            <div class="w-full max-w-3xl min-w-0 rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="Dağıtım günlüğü">
                <h2 class="flex h-12 items-center gap-3 border-b border-line px-5 text-[14.5px] font-semibold">
                    {{ $log->kind === 'rollback' ? 'Sürüme dönüş' : 'Dağıtım' }} #{{ $log->number }}
                    <x-status-badge :status="$log->status" />
                </h2>
                <div class="p-5">
                    <x-run-log :run="$log->run" class="h-96" />
                </div>
                <div class="flex justify-end border-t border-line px-5 py-3">
                    <button type="button" wire:click="closeLog" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Kapat</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Bu sürüme dön --}}
    @if ($rollbackTo)
        <div x-data @keydown.escape.window="$wire.set('rollbackId', null)" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
            <div class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="Sürüme dön">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">#{{ $rollbackTo->number }} sürümüne dönülsün mü?</h2>
                <p class="p-5 text-[13.5px] text-muted">Kod bu sürüme döner, PHP-FPM yenilenir ve kuyruk işçileri yeniden başlar. Kesinti olmaz. Veritabanı geçişleri geri alınmaz.</p>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('rollbackId', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                    <button type="button" wire:click="rollback" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">Sürüme dön</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Dağıtım anahtarı --}}
    <x-modal title="Dağıtım anahtarı" show="showingKey">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Dağıtım anahtarı</h2>
        <div class="space-y-2 p-5 text-[13.5px]" x-data="{ copied: false }">
            <p class="text-muted">Özel depolar bu anahtarla çekilir. Açık anahtarı deponun <span class="font-medium text-fg">Deploy keys</span> bölümüne <span class="font-medium text-fg">salt okunur</span> olarak ekleyin.@if ($app->deployKeysUrl()) <a href="{{ $app->deployKeysUrl() }}" target="_blank" rel="noopener" class="text-accent hover:underline">{{ $app->providerLabel() }}'da aç</a>@endif</p>
            <pre class="rounded-md border border-line bg-surface-2 p-3 font-mono text-[12px] break-all whitespace-pre-wrap">{{ $publicKey }}</pre>
            <button type="button" @click="navigator.clipboard.writeText(@js($publicKey)); copied = true; setTimeout(() => copied = false, 2000)" class="h-8 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2"><span x-show="! copied">Kopyala</span><span x-show="copied" x-cloak>Kopyalandı</span></button>
            @if ($app->repository && str_starts_with($app->repository, 'https://'))
                <p class="text-2xs text-muted">Depo şu an <span class="font-mono">https://</span> ile çekiliyor; özel bir depoysa "Değiştir" ile "Özel depo" seçin.</p>
            @endif
        </div>
        <div class="flex justify-end border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('showingKey', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Kapat</button>
        </div>
    </x-modal>

    {{-- Depo bağla --}}
    <x-modal title="Depo bağla" show="connecting">
        <form wire:submit="connect">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Git deposu bağla</h2>
            <div class="space-y-3 p-5 text-[13.5px]">
                <div class="flex flex-wrap gap-2">
                    @foreach (LaravelIndex::PROVIDERS as $key => [$label])
                        <label class="flex h-8 cursor-pointer items-center gap-2 rounded-md border border-line px-3 text-[13px] has-[:checked]:border-accent has-[:checked]:bg-accent-soft/40"><input type="radio" wire:model.live="provider" value="{{ $key }}" class="{{ $check }}">{{ $label }}</label>
                    @endforeach
                </div>
                <input wire:model="repository" aria-label="Depo" placeholder="kullanıcı/depo veya depo adresi" autocomplete="off" spellcheck="false"
                       class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('repository') ? 'border-crit' : 'border-line' }}">
                @error('repository')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                    <input wire:model="branch" aria-label="Dal" placeholder="main" class="h-9 w-40 rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('branch') ? 'border-crit' : 'border-line' }}">
                    <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="private" class="{{ $check }}">Özel depo (dağıtım anahtarıyla)</label>
                </div>
                @error('branch')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('connecting', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">Bağla</button>
            </div>
        </form>
    </x-modal>
</div>
