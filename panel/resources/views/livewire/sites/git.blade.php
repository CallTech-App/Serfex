@php
    use App\Support\When;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $dl = 'grid gap-1 py-3 sm:grid-cols-[11rem_1fr] sm:gap-4';
    $link = 'text-[13px] whitespace-nowrap text-accent hover:underline';
    $providerIcon = $repo ? (['github' => 'github', 'gitlab' => 'gitlab'][$repo->provider()] ?? 'git-branch') : 'git-branch';
@endphp
<div class="space-y-6">
    @if ($busy)
        <div wire:poll.3s hidden></div>
    @endif

    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Git dağıtımı') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Git dağıtımı') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Sitenin dosyalarını bir Git deposundan alın: "Dağıt" dalın son hâlini klasöre yazar, istenirse her push\'ta kendiliğinden. Her dağıtıma geri dönülebilir.') }}</p>
    </div>

    @if ($site->laravelApp)
        <section class="rounded-xl border border-line bg-surface p-5 text-[13.5px]">
            <p class="font-medium">{{ __('Bu sitede bir Laravel uygulaması var.') }}</p>
            <p class="mt-1 text-muted">{{ __('Laravel uygulamaları sıfır kesintili sürümlerle dağıtılır; depo, webhook ve geçmiş orada.') }}</p>
            <a href="{{ route('laravel.show', [$site->laravelApp, 'section' => 'deployments']) }}" wire:navigate class="{{ $btn }} mt-3 w-fit">{{ __('Laravel → Dağıtımlar') }}</a>
        </section>
    @elseif ($repo === null)
        {{-- Depo bağla --}}
        <section class="rounded-xl border border-line bg-surface">
            <form wire:submit="connect">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Depo bağla') }}</h2>
                <div class="p-5 text-[13.5px]">
                    @include('livewire.sites.partials.git-form', ['picker' => $picker, 'hosts' => $hosts])
                </div>
                <div class="flex justify-end border-t border-line px-5 py-3">
                    <button type="submit" class="{{ $primary }}"><x-icon name="git-branch" />{{ __('Bağla') }}</button>
                </div>
            </form>
        </section>
    @else
        <section class="min-w-0 rounded-xl border border-line bg-surface">
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <button type="button" wire:click="deploy" @disabled($busy || $site->isSuspended()) class="{{ $primary }}">
                    <x-icon name="rocket" />{{ __('Dağıt') }}
                </button>
                <button type="button" wire:click="openSettings" class="{{ $btn }}"><x-icon name="settings" />{{ __('Ayarlar') }}</button>
                @if ($busy)
                    <span class="flex items-center gap-1.5 text-[13px] text-muted"><x-icon name="loader-circle" class="size-3.5 animate-spin" />{{ __('Dağıtım sürüyor') }}</span>
                @elseif ($site->isSuspended())
                    <span class="text-[13px] text-muted">{{ __('Site askıda; dağıtılamaz.') }}</span>
                @endif
                <div class="relative ml-auto" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="grid size-9 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Diğer işlemler') }}"><x-icon name="ellipsis-vertical" /></button>
                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute top-full right-0 z-20 mt-1 w-56 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg">
                        @if ($repo->usesKey())
                            <button type="button" wire:click="showKey" @click="open = false" class="w-full px-3 py-2 text-left hover:bg-surface-2">{{ __('Dağıtım anahtarı') }}</button>
                        @endif
                        <button type="button" wire:click="$set('disconnecting', true)" @click="open = false" @disabled($busy) class="w-full px-3 py-2 text-left text-crit hover:bg-surface-2 disabled:opacity-40">{{ __('Depo bağlantısını kaldır') }}</button>
                    </div>
                </div>
            </div>

            <dl class="divide-y divide-line px-5 py-1 text-[13.5px]">
                <div class="{{ $dl }}">
                    <dt class="text-muted">{{ __('Depo') }}</dt>
                    <dd class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                        <x-icon :name="$providerIcon" />
                        <span class="font-mono break-all">{{ $repo->repositoryName() }}</span>
                        <span class="text-muted">· <x-t text="dal :branch → :path"><x-slot:branch><span class="font-mono text-fg">{{ $repo->branch }}</span></x-slot:branch><x-slot:path><span class="font-mono text-fg">{{ $repo->path }}</span></x-slot:path></x-t></span>
                        <span class="text-2xs text-muted">{{ $repo->usesKey() ? __('SSH, dağıtım anahtarıyla') : ($repo->token ? __('https, erişim anahtarıyla') : __('https, herkese açık')) }}@if ($repo->remote_repository) · {{ __(':provider hesabından seçildi', ['provider' => $repo->providerLabel()]) }}@endif</span>
                    </dd>
                </div>
                <div class="{{ $dl }}">
                    <dt class="text-muted">{{ __('Yayındaki sürüm') }}</dt>
                    <dd>
                        @if ($liveDeployment)
                            <span class="font-mono text-[13px] text-accent">{{ $liveDeployment->shortCommit() }}</span>
                            <span>{{ $liveDeployment->message }}</span>
                            <span class="text-[13px] text-muted">· #{{ $liveDeployment->number }} · {{ When::withTime($liveDeployment->finished_at ?? $liveDeployment->created_at) }}</span>
                        @else
                            <span class="text-muted">{{ __('Henüz dağıtılmadı. "Dağıt" ile dalın son hâlini :path klasörüne alın.', ['path' => $repo->path]) }}</span>
                        @endif
                    </dd>
                </div>
                <div class="{{ $dl }}">
                    <dt class="text-muted">{{ __('Otomatik dağıtım') }}</dt>
                    <dd class="min-w-0 space-y-1">
                        <label class="flex items-center gap-2.5">
                            <input type="checkbox" @checked($repo->auto_deploy) wire:change="setAutoDeploy($event.target.checked)" class="size-4 accent-[rgb(var(--accent))]">
                            <x-t text=":branch dalına push gelince dağıt"><x-slot:branch><span class="font-mono">{{ $repo->branch }}</span></x-slot:branch></x-t>
                        </label>
                        <p class="text-2xs text-muted" x-data="{ copied: false }">
                            <x-t text="Webhook: :url"><x-slot:url><span class="font-mono break-all">{{ $repo->webhookUrl() }}</span></x-slot:url></x-t>
                            <button type="button" @click="navigator.clipboard.writeText(@js($repo->webhookUrl())); copied = true; setTimeout(() => copied = false, 2000)" class="text-accent hover:underline"><span x-show="! copied">{{ __('Kopyala') }}</span><span x-show="copied" x-cloak>{{ __('Kopyalandı') }}</span></button>
                        </p>
                        @if ($repo->remote_hook_id)
                            <p class="text-2xs text-muted"><x-t text="Webhook :provider deposuna eklendi. CI'dan dağıtmak için aynı adrese boş bir :part gönderin."><x-slot:provider>{{ $repo->providerLabel() }}</x-slot:provider><x-slot:part><span class="font-mono">POST</span></x-slot:part></x-t></p>
                        @else
                            <p class="text-2xs text-muted"><x-t text="Adresi deponun webhook ayarlarına (push olayı) ekleyin. CI'dan dağıtmak için aynı adrese boş bir :part gönderin."><x-slot:part><span class="font-mono">POST</span></x-slot:part></x-t></p>
                        @endif
                    </dd>
                </div>
                <div class="{{ $dl }}">
                    <dt class="text-muted">{{ __('Dağıtım sonrası komutlar') }}</dt>
                    <dd class="min-w-0">
                        @if ($repo->commands)
                            <pre class="max-h-24 overflow-auto font-mono text-[12.5px] whitespace-pre-wrap">{{ $repo->commands }}</pre>
                        @else
                            <span class="text-muted">{{ __('Yok') }}</span> <button type="button" wire:click="openSettings" class="{{ $link }}">{{ __('Ekle') }}</button>
                        @endif
                    </dd>
                </div>
            </dl>

            <div class="relative overflow-x-auto border-t border-line">
                <table class="w-full text-[13.5px] sm:min-w-[720px]">
                    <thead>
                        <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                            <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Dağıtım') }}</th>
                            <th class="px-3 py-2.5 font-medium">{{ __('Commit') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Tetikleyen') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Süre') }}</th>
                            <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Durum') }}</th>
                            <th class="px-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($deployments as $d)
                            <tr wire:key="git-{{ $d->id }}" class="hover:bg-surface-2/60">
                                <td class="py-3 pr-3 pl-5 font-mono text-[13px] whitespace-nowrap">
                                    #{{ $d->number }}
                                    @if ($liveDeployment?->id === $d->id)<span class="ml-1 font-sans text-2xs text-accent">{{ __('yayında') }}</span>@endif
                                    <div class="font-sans text-2xs text-muted">{{ When::withTime($d->started_at ?? $d->created_at) }}</div>
                                    <x-status-badge :status="$d->status" class="mt-1 font-sans sm:hidden" />
                                </td>
                                <td class="max-w-0 px-3 py-3 sm:w-[40%]">
                                    @if ($d->kind === 'undo')
                                        <span class="text-muted">{{ __('İlk dağıtım geri alındı; önceki dosyalar geri yüklendi') }}</span>
                                    @else
                                        <span class="block truncate">
                                            @if ($d->shortCommit())<span class="font-mono text-[13px] text-accent">{{ $d->shortCommit() }}</span>@endif
                                            <span @class(['text-muted' => $d->message === null])>{{ $d->message ?? __(':branch dağıtımı', ['branch' => $d->branch]) }}</span>
                                        </span>
                                        <span class="block text-2xs text-muted">
                                            @if ($d->kind === 'rollback'){{ __('önceki sürüme dönüş') }} · @endif
                                            @if ($d->changed_files !== null){{ trans_choice(':count dosya', $d->changed_files) }}@endif
                                            @if ($d->saved_files) · {{ __(':count mevcut dosya saklandı', ['count' => $d->saved_files]) }}@endif
                                        </span>
                                    @endif
                                </td>
                                <td class="hidden px-3 py-3 whitespace-nowrap md:table-cell">{{ $d->triggered_by ?? 'Serfex' }}</td>
                                <td class="num hidden px-3 py-3 whitespace-nowrap text-muted sm:table-cell">{{ $d->durationLabel() ?? '…' }}</td>
                                <td class="hidden px-3 py-3 sm:table-cell">
                                    <x-status-badge :status="$d->status" />
                                    @if ($d->error)<p class="mt-1 max-w-xs truncate text-2xs text-muted" title="{{ $d->error }}">{{ $d->error }}</p>@endif
                                </td>
                                <td class="px-3 py-3 text-right whitespace-nowrap">
                                    <div class="flex flex-col items-end gap-1.5 sm:block sm:space-x-3">
                                        <button type="button" wire:click="showLog({{ $d->id }})" class="{{ $link }}">{{ __('Günlük') }}</button>
                                        @if (! $busy && $d->kind !== 'undo' && $d->commit && $d->status->isLive() && $d->commit !== $repo->live_commit)
                                            <button type="button" wire:click="confirmRollback({{ $d->id }})" class="{{ $link }}">{{ __('Bu sürüme dön') }}</button>
                                        @endif
                                        @if (! $busy && $firstDeploy?->id === $d->id)
                                            <button type="button" wire:click="$set('undoing', true)" class="{{ $link }}">{{ __('İlk dağıtımı geri al') }}</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-10 text-center">
                                    <p class="font-medium">{{ __('Henüz dağıtım yok') }}</p>
                                    <p class="mt-1 text-[13px] text-muted">{{ __('İlk dağıtımda hedef klasördeki aynı adlı dosyalar saklanır; ilk dağıtım geri alınırsa geri gelir.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- Ayarlar --}}
    @if ($repo)
        <x-modal :title="__('Git dağıtımı ayarları')" show="editing" size="max-w-2xl">
            <form wire:submit="saveSettings">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Git dağıtımı ayarları') }}</h2>
                <div class="p-5 text-[13.5px]">
                    @include('livewire.sites.partials.git-form', ['hasToken' => $repo->token !== null, 'hosts' => [], 'picker' => null])
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('editing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Dağıtım günlüğü --}}
    @if ($log)
        <div x-data @keydown.escape.window="$wire.closeLog()" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
            <div class="w-full max-w-3xl min-w-0 rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Dağıtım günlüğü') }}">
                <h2 class="flex h-12 items-center gap-3 border-b border-line px-5 text-[14.5px] font-semibold">
                    {{ match ($log->kind) { 'rollback' => __('Sürüme dönüş'), 'undo' => __('İlk dağıtımın geri alınması'), default => __('Dağıtım') } }} #{{ $log->number }}
                    <x-status-badge :status="$log->status" />
                </h2>
                <div class="p-5">
                    <x-run-log :run="$log->run" class="h-96" />
                </div>
                <div class="flex justify-end border-t border-line px-5 py-3">
                    <button type="button" wire:click="closeLog" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kapat') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Bu sürüme dön --}}
    @if ($rollbackTo)
        <div x-data @keydown.escape.window="$wire.set('rollbackId', null)" class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
            <div class="w-full max-w-lg rounded-xl border border-line bg-surface shadow-xl" role="dialog" aria-modal="true" aria-label="{{ __('Sürüme dön') }}">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('#:number sürümüne dönülsün mü?', ['number' => $rollbackTo->number]) }}</h2>
                <p class="p-5 text-[13.5px] text-muted">
                    <x-t text=":commit commit'indeki dosyalar :path klasörüne yazılır; sonradan eklenen dosyalar kaldırılır. Depoda olmayan dosyalarınız olduğu gibi kalır." :with="['path' => $repo->path]"><x-slot:commit><span class="font-mono text-fg">{{ $rollbackTo->shortCommit() }}</span></x-slot:commit></x-t>
                    @if ($repo->commands) {{ __('Dağıtım sonrası komutlar yeniden çalışır.') }}@endif
                </p>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('rollbackId', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="button" wire:click="rollback" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Sürüme dön') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- İlk dağıtımı geri al --}}
    @if ($repo && $firstDeploy)
        <x-modal :title="__('İlk dağıtımı geri al')" show="undoing">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Klasör Git\'ten önceki hâline dönsün mü?') }}</h2>
            <p class="p-5 text-[13.5px] text-muted">
                <x-t text="Depodan gelen dosyalar :path klasöründen kaldırılır ve ilk dağıtımın üzerine yazdığı :count dosya geri yüklenir. Depo bağlı kalır; istediğiniz zaman yeniden dağıtabilirsiniz." :with="['path' => $repo->path, 'count' => (int) $firstDeploy->saved_files]" />
            </p>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('undoing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="undoFirst" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">{{ __('Geri al') }}</button>
            </div>
        </x-modal>
    @endif

    {{-- Bağlantıyı kaldır --}}
    @if ($repo)
        <x-modal :title="__('Depo bağlantısını kaldır')" show="disconnecting">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Depo bağlantısı kaldırılsın mı?') }}</h2>
            <p class="p-5 text-[13.5px] text-muted"><x-t text="Dosyalar :path klasöründe olduğu gibi kalır. Sunucudaki depo kopyası, dağıtım anahtarı ve dağıtım geçmişi silinir; önceki sürümlere dönülemez." :with="['path' => $repo->path]" /></p>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('disconnecting', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="disconnect" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit">{{ __('Bağlantıyı kaldır') }}</button>
            </div>
        </x-modal>
    @endif

    {{-- Dağıtım anahtarı --}}
    <x-modal :title="__('Dağıtım anahtarı')" show="showingKey">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Dağıtım anahtarı') }}</h2>
        <div class="space-y-2 p-5 text-[13.5px]" x-data="{ copied: false }">
            @foreach ($linkErrors as $error)
                <p class="flex items-start gap-2 rounded-md bg-warn-soft px-3 py-2"><x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />{{ $error }}</p>
            @endforeach
            <p class="text-muted"><x-t text="Özel depo bu anahtarla çekilir. Açık anahtarı deponun :part bölümüne :part2 olarak ekleyin, sonra &quot;Dağıt&quot;a basın."><x-slot:part><span class="font-medium text-fg">{{ __('Deploy keys') }}</span></x-slot:part><x-slot:part2><span class="font-medium text-fg">{{ __('salt okunur') }}</span></x-slot:part2></x-t>@if ($repo?->deployKeysUrl()) <a href="{{ $repo->deployKeysUrl() }}" target="_blank" rel="noopener" class="text-accent hover:underline">{{ __(':app\'da aç', ['app' => $repo->providerLabel()]) }}</a>@endif</p>
            <pre class="rounded-md border border-line bg-surface-2 p-3 font-mono text-[12px] break-all whitespace-pre-wrap">{{ $publicKey }}</pre>
            <button type="button" @click="navigator.clipboard.writeText(@js($publicKey)); copied = true; setTimeout(() => copied = false, 2000)" class="h-8 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2"><span x-show="! copied">{{ __('Kopyala') }}</span><span x-show="copied" x-cloak>{{ __('Kopyalandı') }}</span></button>
        </div>
        <div class="flex justify-end border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('showingKey', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kapat') }}</button>
        </div>
    </x-modal>
</div>
