@php
    use App\Enums\LaravelAppStatus;
    use App\Livewire\Laravel\LaravelShow;
    $check = 'size-4 accent-[rgb(var(--accent))]';
    $installing = $app->status !== LaravelAppStatus::Active && $install !== null && $app->current_release === null;
@endphp
<div class="space-y-6">
    {{-- Refresh while the agent installs or removes the application. --}}
    @if ($app->status->isBusy())
        <div wire:poll.3s="refreshStatus" hidden></div>
    @endif

    {{-- Title --}}
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="Konum">
            <a href="{{ route('laravel.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />Laravel</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="font-mono text-fg">{{ $site->domain }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <div class="flex min-w-0 items-center gap-3">
                <h1 class="truncate font-mono text-[22px] font-semibold tracking-tight">{{ $site->domain }}</h1>
                <x-status-badge :status="$app->status" />
                <div class="relative" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="grid size-8 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="Diğer işlemler"><x-icon name="ellipsis-vertical" /></button>
                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute top-full left-0 z-20 mt-1 w-56 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg">
                        <a href="{{ route('sites.show', $site) }}" wire:navigate class="block px-3 py-2 hover:bg-surface-2">Siteye git</a>
                        <button type="button" wire:click="openRemove" @click="open = false" @disabled($app->status->isBusy())
                                class="w-full px-3 py-2 text-left text-crit hover:bg-surface-2 disabled:opacity-40">Uygulamayı kaldır</button>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('laravel.index') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2"><x-icon name="arrow-left" />Listeye dön</a>
                @unless ($installing)
                    <button type="button" wire:click="show('komutlar')" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2"><x-icon name="terminal" />Komut çalıştır</button>
                    <button type="button" wire:click="openDeploy" @disabled(! $app->canDeploy())
                            title="{{ $app->repository === null ? 'Dağıtmak için önce Dağıtımlar bölümünden bir Git deposu bağlayın.' : ($running ? 'Bir dağıtım sürüyor.' : '') }}"
                            class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                        @if ($running)<x-icon name="loader-circle" class="animate-spin" />Dağıtılıyor @else<x-icon name="rocket" />Dağıt @endif
                    </button>
                @endunless
            </div>
        </div>
        <p class="mt-1 text-[13px] text-muted">
            @if ($app->repository)
                Depo <span class="font-mono">{{ $app->repositoryName() }}</span> · dal <span class="font-mono">{{ $app->branch ?? 'varsayılan' }}</span>
            @elseif ($app->source === 'existing')
                Sitede bulunan uygulama · <span class="font-mono">{{ $app->path }}</span>
            @else
                Yeni proje · <span class="font-mono">{{ $app->package }}</span>
            @endif
            @if ($app->laravel_version) · Laravel <span class="font-mono">{{ $app->laravel_version }}</span>@endif
            · PHP <span class="font-mono">{{ $site->php_version }}</span> · <span class="font-mono">{{ $site->systemUser->name }}</span>
        </p>
    </div>

    @if ($app->status === LaravelAppStatus::Removing)
        <div class="relative flex items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-info"></span>
            <x-icon name="loader-circle" class="animate-spin text-info" />
            <p>Uygulama kaldırılıyor: kuyruk işçileri ve zamanlayıcı durduruluyor, site <span class="font-mono">public_html</span> dizinine döndürülüyor.</p>
        </div>
    @elseif ($app->status === LaravelAppStatus::Failed && ! $installing)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
            <x-icon name="triangle-alert" class="text-crit" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">Son işlem sunucuya uygulanamadı.</p>
                <p class="mt-0.5 font-mono text-[12.5px] break-words text-muted">{{ $app->last_error }}</p>
            </div>
        </div>
    @endif

    @if ($installing)
        {{-- Install in progress, or failed --}}
        <section class="min-w-0 rounded-xl border border-line bg-surface">
            <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                <h2 class="font-semibold">{{ $app->status === LaravelAppStatus::Failed ? 'Kurulum başarısız' : 'Kurulum sürüyor' }}</h2>
                <x-status-badge :status="$install->status" />
                @if ($app->status === LaravelAppStatus::Failed)
                    <span class="ml-auto flex gap-2">
                        <button type="button" wire:click="retryInstall" class="flex h-8 items-center gap-2 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2"><x-icon name="refresh-cw" class="size-3.5" />Tekrar dene</button>
                        <button type="button" wire:click="openRemove" class="h-8 rounded-md border border-line px-3 text-[13px] text-crit hover:bg-surface-2">Kaldır</button>
                    </span>
                @endif
            </div>
            <div class="space-y-3 p-5 text-[13.5px]">
                @if ($app->status === LaravelAppStatus::Failed)
                    <p class="font-mono text-[12.5px] break-words text-crit">{{ $app->last_error }}</p>
                    <p class="text-[13px] text-muted">Site eskisi gibi yayında kaldı. Günlüğe bakıp sorunu giderin, sonra "Tekrar dene"ye basın.</p>
                @else
                    <p class="text-[13px] text-muted">Kod indiriliyor, bağımlılıklar kuruluyor ve uygulama hazırlanıyor. Sayfadan çıksanız da kurulum sürer; bittiğinde site yeni uygulamayı yayınlar.</p>
                @endif
                <x-run-log :run="$install->run" class="h-96" />
            </div>
        </section>
    @else
        <section class="grid min-w-0 grid-cols-1 rounded-xl border border-line bg-surface md:grid-cols-[210px_1fr]">
            <nav class="scroll-thin flex gap-0.5 overflow-x-auto border-b border-line p-2 text-[13.5px] md:flex-col md:border-r md:border-b-0" aria-label="Uygulama bölümleri">
                @foreach (LaravelShow::SECTIONS as $group => $items)
                    <p class="mb-1 hidden px-3 text-2xs font-medium tracking-[.08em] text-muted uppercase md:block {{ $loop->first ? 'mt-1' : 'mt-3' }}">{{ $group }}</p>
                    @foreach ($items as $key => [$label, $sectionComponent])
                        @if ($sectionComponent)
                            <button type="button" wire:click="show('{{ $key }}')" @if ($section === $key) aria-current="page" @endif
                                    class="h-9 shrink-0 rounded-md px-3 text-left whitespace-nowrap {{ $section === $key ? 'bg-accent-soft font-medium text-accent' : 'text-muted hover:bg-surface-2 hover:text-fg' }}">{{ $label }}</button>
                        @else
                            <span title="Sonraki sürümde" class="flex h-9 shrink-0 cursor-default items-center gap-2 rounded-md px-3 whitespace-nowrap text-muted/60">{{ $label }}<span class="rounded-full bg-surface-2 px-1.5 text-[10.5px] text-muted">yakında</span></span>
                        @endif
                    @endforeach
                @endforeach
            </nav>
            <div class="min-w-0">
                @livewire($component, ['appId' => $app->id], key($section.'-'.$app->id))
            </div>
        </section>
    @endif

    {{-- Dağıt --}}
    <x-modal title="Dağıt" show="deploying" size="max-w-md">
        <form wire:submit="deploy">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Dağıt</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div>
                    <label for="dep-ref" class="mb-1.5 block">Dal veya etiket</label>
                    <input id="dep-ref" wire:model="ref" placeholder="{{ $app->branch ?? 'varsayılan dal' }}" spellcheck="false" autocomplete="off"
                           class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('ref') ? 'border-crit' : 'border-line' }}">
                    @error('ref')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">Boş bırakırsanız deponun varsayılan dalı dağıtılır.</p>@enderror
                </div>
                <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="migrate" class="{{ $check }}">Veritabanı geçişlerini çalıştır (<span class="font-mono text-[13px]">migrate --force</span>)</label>
                <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="frontend" class="{{ $check }}">Ön yüzü derle (<span class="font-mono text-[13px]">{{ $app->package_manager ?? 'npm' }} run build</span>)</label>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('deploying', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="deploy" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Dağıt</button>
            </div>
        </form>
    </x-modal>

    {{-- Kaldır --}}
    <x-modal title="Uygulamayı kaldır" show="removing">
        <form wire:submit="remove">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Laravel uygulamasını kaldır</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <p class="text-muted">Kuyruk işçileri ve zamanlayıcı durur, site yeniden <span class="font-mono">public_html</span> dizinini yayınlar. Site, alan adı ve e-posta yerinde kalır.</p>
                @if ($app->isManaged())
                    <label class="flex items-start gap-2.5"><input type="checkbox" wire:model="removeFiles" class="mt-0.5 {{ $check }}"><span>Kodu, <span class="font-mono">.env</span> dosyasını ve <span class="font-mono">storage</span> dizinini de sil (<span class="font-mono">laravel/</span>)</span></label>
                @else
                    <p class="text-muted">Uygulamanın dosyaları (<span class="font-mono">{{ $app->path }}</span>) silinmez.</p>
                @endif
                <div>
                    <label for="rm-domain" class="mb-1.5 block">Onaylamak için <span class="font-mono font-medium">{{ $site->domain }}</span> yazın</label>
                    <input id="rm-domain" wire:model="confirmDomain" autocomplete="off" spellcheck="false"
                           class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('confirmDomain') ? 'border-crit' : 'border-line' }}">
                    @error('confirmDomain')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('removing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-white hover:brightness-110">Kaldır</button>
            </div>
        </form>
    </x-modal>
</div>
