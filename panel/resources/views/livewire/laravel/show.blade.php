@php
    use App\Enums\LaravelAppStatus;
    $check = 'size-4 accent-[rgb(var(--accent))]';
    $installing = $app->status !== LaravelAppStatus::Active && $install !== null && $app->current_release === null;
    $operate = auth()->user()->canOnApp($app, 'operate');
    $manage = auth()->user()->canOnApp($app, 'manage');
@endphp
<div class="space-y-6">
    {{-- Refresh while the agent installs or removes the application. --}}
    @if ($app->status->isBusy())
        <div wire:poll.3s="refreshStatus" hidden></div>
    @endif

    {{-- Title --}}
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('laravel.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />Laravel</a>
            <x-icon name="chevron-right" class="size-3.5" />
            @if ($app->previewOf)
                <a href="{{ route('laravel.show', ['app' => $app->previewOf->id, 'bolum' => 'onizleme']) }}" wire:navigate class="font-mono hover:text-accent">{{ $app->previewOf->site->domain }}</a>
                <x-icon name="chevron-right" class="size-3.5" />
            @endif
            <span class="font-mono text-fg">{{ $site->domain }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <div class="flex min-w-0 items-center gap-3">
                <h1 class="truncate font-mono text-[22px] font-semibold tracking-tight">{{ $site->domain }}</h1>
                <x-status-badge :status="$app->status" />
                @if ($app->previewOf)<span class="rounded-full bg-surface-2 px-2 py-0.5 text-2xs font-medium text-muted">{{ __('Önizleme') }}</span>@endif
                @if ($manage || auth()->user()->is_admin)
                <div class="relative" x-data="{ open: false }">
                    <button type="button" @click="open = !open" class="grid size-8 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg" aria-label="{{ __('Diğer işlemler') }}"><x-icon name="ellipsis-vertical" /></button>
                    <div x-show="open" x-cloak @click.outside="open = false" class="absolute top-full left-0 z-20 mt-1 w-56 rounded-lg border border-line bg-surface py-1 text-[13.5px] shadow-lg">
                        @if (auth()->user()->canSeeSite($site))<a href="{{ route('sites.show', $site) }}" wire:navigate class="block px-3 py-2 hover:bg-surface-2">{{ __('Siteye git') }}</a>@endif
                        <button type="button" wire:click="openRemove" @click="open = false" @disabled($app->status->isBusy())
                                class="w-full px-3 py-2 text-left text-crit hover:bg-surface-2 disabled:opacity-40">{{ $app->previewOf ? __('Önizlemeyi sil') : __('Uygulamayı kaldır') }}</button>
                    </div>
                </div>
                @endif
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('laravel.index') }}" wire:navigate class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2"><x-icon name="arrow-left" />{{ __('Listeye dön') }}</a>
                @if (! $installing && $operate)
                    <button type="button" wire:click="show('komutlar')" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2"><x-icon name="terminal" />{{ __('Komut çalıştır') }}</button>
                    <button type="button" wire:click="openDeploy" @disabled(! $app->canDeploy())
                            title="{{ $app->repository === null ? __('Dağıtmak için önce Dağıtımlar bölümünden bir Git deposu bağlayın.') : ($running ? __('Bir dağıtım sürüyor.') : '') }}"
                            class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">
                        @if ($running)<x-icon name="loader-circle" class="animate-spin" />{{ __('Dağıtılıyor') }} @else<x-icon name="rocket" />{{ __('Dağıt') }} @endif
                    </button>
                @endif
            </div>
        </div>
        <p class="mt-1 text-[13px] text-muted">
            @if ($app->previewOf)
                <x-t text=":domain uygulamasının önizlemesi · dal :branch"><x-slot:domain><span class="font-mono">{{ $app->previewOf->site->domain }}</span></x-slot:domain><x-slot:branch><span class="font-mono">{{ $app->branch }}</span></x-slot:branch></x-t>
            @elseif ($app->repository)
                <x-t text="Depo :app · dal :branch"><x-slot:app><span class="font-mono">{{ $app->repositoryName() }}</span></x-slot:app><x-slot:branch><span class="font-mono">{{ $app->branch ?? __('varsayılan') }}</span></x-slot:branch></x-t>
            @elseif ($app->source === 'existing')
                <x-t text="Sitede bulunan uygulama · :path"><x-slot:path><span class="font-mono">{{ $app->path }}</span></x-slot:path></x-t>
            @else
                <x-t text="Yeni proje · :package"><x-slot:package><span class="font-mono">{{ $app->package }}</span></x-slot:package></x-t>
            @endif
            @if ($app->laravel_version) · Laravel <span class="font-mono">{{ $app->laravel_version }}</span>@endif
            · PHP <span class="font-mono">{{ $site->php_version }}</span> · <span class="font-mono">{{ $site->systemUser->name }}</span>
        </p>
    </div>

    @if ($app->status === LaravelAppStatus::Removing)
        <div class="relative flex items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-info"></span>
            <x-icon name="loader-circle" class="animate-spin text-info" />
            <p><x-t text="Uygulama kaldırılıyor: kuyruk işçileri ve zamanlayıcı durduruluyor, site :part dizinine döndürülüyor."><x-slot:part><span class="font-mono">public_html</span></x-slot:part></x-t></p>
        </div>
    @elseif ($app->status === LaravelAppStatus::Failed && ! $installing)
        <div class="relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]">
            <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
            <x-icon name="triangle-alert" class="text-crit" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">{{ __('Son işlem sunucuya uygulanamadı.') }}</p>
                <p class="mt-0.5 font-mono text-[12.5px] break-words text-muted">{{ $app->last_error }}</p>
            </div>
        </div>
    @endif

    @if ($installing)
        {{-- Install in progress, or failed --}}
        <section class="min-w-0 rounded-xl border border-line bg-surface">
            <div class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                <h2 class="font-semibold">{{ $app->status === LaravelAppStatus::Failed ? __('Kurulum başarısız') : __('Kurulum sürüyor') }}</h2>
                <x-status-badge :status="$install->status" />
                @if ($app->status === LaravelAppStatus::Failed && $operate)
                    <span class="ml-auto flex gap-2">
                        <button type="button" wire:click="retryInstall" class="flex h-8 items-center gap-2 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2"><x-icon name="refresh-cw" class="size-3.5" />{{ __('Tekrar dene') }}</button>
                        <button type="button" wire:click="openRemove" class="h-8 rounded-md border border-line px-3 text-[13px] text-crit hover:bg-surface-2">{{ __('Kaldır') }}</button>
                    </span>
                @endif
            </div>
            <div class="space-y-3 p-5 text-[13.5px]">
                @if ($app->status === LaravelAppStatus::Failed)
                    <p class="font-mono text-[12.5px] break-words text-crit">{{ $app->last_error }}</p>
                    <p class="text-[13px] text-muted">{{ __('Site eskisi gibi yayında kaldı. Günlüğe bakıp sorunu giderin, sonra "Tekrar dene"ye basın.') }}</p>
                @else
                    <p class="text-[13px] text-muted">{{ __('Kod indiriliyor, bağımlılıklar kuruluyor ve uygulama hazırlanıyor. Sayfadan çıksanız da kurulum sürer; bittiğinde site yeni uygulamayı yayınlar.') }}</p>
                @endif
                <x-run-log :run="$install->run" class="h-96" />
            </div>
        </section>
    @else
        <section class="grid min-w-0 grid-cols-1 rounded-xl border border-line bg-surface md:grid-cols-[210px_1fr]">
            <nav class="scroll-thin flex gap-0.5 overflow-x-auto border-b border-line p-2 text-[13.5px] md:flex-col md:border-r md:border-b-0" aria-label="{{ __('Uygulama bölümleri') }}">
                @foreach ($sections as $group => $items)
                    <p class="mb-1 hidden px-3 text-2xs font-medium tracking-[.08em] text-muted uppercase md:block {{ $loop->first ? 'mt-1' : 'mt-3' }}">{{ $group }}</p>
                    @foreach ($items as $key => [$label])
                        <button type="button" wire:click="show('{{ $key }}')" @if ($section === $key) aria-current="page" @endif
                                class="h-9 shrink-0 rounded-md px-3 text-left whitespace-nowrap {{ $section === $key ? 'bg-accent-soft font-medium text-accent' : 'text-muted hover:bg-surface-2 hover:text-fg' }}">{{ $label }}</button>
                    @endforeach
                @endforeach
            </nav>
            <div class="min-w-0">
                @livewire($component, ['appId' => $app->id], key($section.'-'.$app->id))
            </div>
        </section>
    @endif

    {{-- Dağıt --}}
    <x-modal title="{{ __('Dağıt') }}" show="deploying" size="max-w-md">
        <form wire:submit="deploy">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Dağıt') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div>
                    <label for="dep-ref" class="mb-1.5 block">{{ __('Dal veya etiket') }}</label>
                    <input id="dep-ref" wire:model="ref" placeholder="{{ $app->branch ?? __('varsayılan dal') }}" spellcheck="false" autocomplete="off"
                           class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('ref') ? 'border-crit' : 'border-line' }}">
                    @error('ref')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Boş bırakırsanız deponun varsayılan dalı dağıtılır.') }}</p>@enderror
                </div>
                <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="migrate" class="{{ $check }}"><span>{{ __('Veritabanı geçişlerini çalıştır') }} (<span class="font-mono text-[13px]">migrate --force</span>)</span></label>
                <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="frontend" class="{{ $check }}"><span>{{ __('Ön yüzü derle') }} (<span class="font-mono text-[13px]">{{ $app->package_manager ?? 'npm' }} run build</span>)</span></label>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('deploying', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="deploy" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Dağıt') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Kaldır --}}
    <x-modal title="{{ __('Uygulamayı kaldır') }}" show="removing">
        <form wire:submit="remove">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ $app->previewOf ? __('Önizlemeyi sil') : __('Laravel uygulamasını kaldır') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                @if ($app->previewOf)
                <p class="text-muted">{{ __('Önizleme sitesi, dosyaları, SQLite veritabanı, sertifikası ve DNS kaydı silinir. Asıl uygulama etkilenmez.') }}</p>
                @else
                <p class="text-muted"><x-t text="Kuyruk işçileri ve zamanlayıcı durur, site yeniden :part dizinini yayınlar. Site, alan adı ve e-posta yerinde kalır."><x-slot:part><span class="font-mono">public_html</span></x-slot:part></x-t></p>
                @if ($app->isManaged())
                    <label class="flex items-start gap-2.5"><input type="checkbox" wire:model="removeFiles" class="mt-0.5 {{ $check }}"><span><x-t text="Kodu, :env dosyasını ve :storage dizinini de sil (:dir)"><x-slot:env><span class="font-mono">.env</span></x-slot:env><x-slot:storage><span class="font-mono">storage</span></x-slot:storage><x-slot:dir><span class="font-mono">laravel/</span></x-slot:dir></x-t></span></label>
                @else
                    <p class="text-muted"><x-t text="Uygulamanın dosyaları (:path) silinmez."><x-slot:path><span class="font-mono">{{ $app->path }}</span></x-slot:path></x-t></p>
                @endif
                @endif
                <div>
                    <label for="rm-domain" class="mb-1.5 block"><x-t text="Onaylamak için :domain yazın"><x-slot:domain><span class="font-mono font-medium">{{ $site->domain }}</span></x-slot:domain></x-t></label>
                    <input id="rm-domain" wire:model="confirmDomain" autocomplete="off" spellcheck="false"
                           class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('confirmDomain') ? 'border-crit' : 'border-line' }}">
                    @error('confirmDomain')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('removing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit hover:brightness-110">{{ __('Kaldır') }}</button>
            </div>
        </form>
    </x-modal>
</div>
