@php
    use App\Enums\LaravelAppStatus;
    use App\Livewire\Laravel\LaravelIndex;
    use App\Services\Laravel\LaravelApps;
    use App\Services\Laravel\PhpConstraint;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $radio = 'flex cursor-pointer items-start gap-2.5 rounded-lg border border-line p-3 has-[:checked]:border-accent has-[:checked]:bg-accent-soft/40';
    $chip = 'flex h-9 cursor-pointer items-center gap-2 rounded-md border border-line px-3 has-[:checked]:border-accent has-[:checked]:bg-accent-soft/40';
    $check = 'size-4 accent-[rgb(var(--accent))]';
@endphp
<div class="space-y-6">
    {{-- Refresh while an install, removal or deploy runs. --}}
    @if ($busy)
        <div wire:poll.3s hidden></div>
    @endif

    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">Laravel</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Sunucudaki Laravel uygulamaları. Dağıtım, kuyruk, zamanlayıcı ve .env için bir uygulamaya tıklayın.') }}</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
            @if ($canInstall)
            <button type="button" wire:click="openInstall" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">
                <x-icon name="plus" />{{ __('Laravel uygulaması kur') }}
            </button>
            <button type="button" wire:click="scan" wire:loading.attr="disabled" wire:target="scan" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-60">
                <x-icon name="scan-search" wire:loading.remove wire:target="scan" /><x-icon name="loader-circle" class="animate-spin" wire:loading wire:target="scan" />{{ __('Mevcut uygulamaları bul') }}
            </button>
            @endif
            <label class="relative ml-auto w-full sm:w-64">
                <span class="sr-only">{{ __('Uygulama ara') }}</span>
                <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Uygulama ara') }}"
                       class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
            </label>
        </div>

        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[760px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Uygulama') }}</th>
                        <th class="px-3 py-2.5 font-medium">Laravel</th>
                        <th class="px-3 py-2.5 font-medium">PHP</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Dal') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Son dağıtım') }}</th>
                        <th class="py-2.5 pr-5 pl-3 font-medium">{{ __('Kuyruk') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($apps as $app)
                        @php
                            $site = $app->site;
                            $deploy = $app->latestDeployment;
                            $fits = $app->php_requirement ? PhpConstraint::allows($app->php_requirement, $site->php_version) : null;
                            $workers = (int) $app->workers_sum_processes;
                        @endphp
                        <tr wire:key="app-{{ $app->id }}" class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5">
                                <a href="{{ route('laravel.show', $app) }}" wire:navigate class="font-mono text-[13px] font-medium hover:text-accent">{{ $site->domain }}</a>
                                <div class="mt-0.5 text-2xs text-muted">
                                    <span class="font-mono">{{ $site->systemUser->name }}</span>@if (! $app->isManaged()) · <span class="font-mono">{{ $app->path }}</span>@endif
                                    @if ($app->previews_count) {{ __('· :previews_count önizleme', ['previews_count' => $app->previews_count]) }} @endif
                                </div>
                                @if ($fits === false)
                                    <div class="mt-0.5 flex items-center gap-1 text-2xs text-warn"><x-icon name="triangle-alert" class="size-3" />{{ __('composer.json PHP :php_requirement istiyor, site PHP :php_version', ['php_requirement' => $app->php_requirement, 'php_version' => $site->php_version]) }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap">{{ $app->laravel_version ? \Illuminate\Support\Str::of($app->laravel_version)->explode('.')->take(2)->implode('.') : '—' }}</td>
                            <td class="px-3 py-3 whitespace-nowrap">{{ $site->php_version }}</td>
                            <td class="px-3 py-3 whitespace-nowrap">@if ($app->repository)<span class="font-mono">{{ $app->branch ?? __('varsayılan') }}</span>@else<span class="text-muted">{{ __('Git yok') }}</span>@endif</td>
                            <td class="px-3 py-3 whitespace-nowrap">
                                @if ($app->status !== LaravelAppStatus::Active)
                                    <x-status-badge :status="$app->status" :title="$app->last_error" />
                                @elseif ($deploy)
                                    <x-status-badge :status="new \App\Support\Badge(
                                        $deploy->status->isBusy() ? __('Dağıtılıyor') : ($deploy->status->isLive() ? '' : __('Başarısız · ')).\App\Support\When::short($deploy->finished_at ?? $deploy->created_at),
                                        $deploy->status->isBusy() ? 'info' : $deploy->status->tone(),
                                        $deploy->status->isBusy(),
                                    )" />
                                @else
                                    <span class="text-muted">{{ __('Henüz yok') }}</span>
                                @endif
                            </td>
                            <td class="py-3 pr-5 pl-3 whitespace-nowrap">
                                @if ($app->processes_state === 'failed')
                                    <x-status-badge :status="new \App\Support\Badge(__('Uygulanamadı'), 'crit')" :title="$app->processes_error" />
                                @elseif ($workers > 0)
                                    <x-status-badge :status="new \App\Support\Badge(__(':workers işçi', ['workers' => $workers]), 'ok')" />
                                @else
                                    <span class="text-muted">{{ __('Yok') }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center">
                                @if ($search !== '')
                                    <p class="text-muted">{{ __('Aramanızla eşleşen uygulama yok.') }}</p>
                                @else
                                    <p class="font-medium">{{ __('Henüz Laravel uygulaması yok') }}</p>
                                    @if ($canInstall)
                                        <p class="mt-1 text-[13px] text-muted">{{ __('Bir sitede yeni proje başlatın ya da Git deposundan kurun. Sitelerde zaten Laravel varsa "Mevcut uygulamaları bul"a basın.') }}</p>
                                    @else
                                        <p class="mt-1 text-[13px] text-muted">{{ __('Size henüz bir uygulama atanmadı. Sunucu yöneticisinden sizi bir uygulamaya eklemesini isteyin.') }}</p>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($apps->total() > 0)
            <div class="num flex items-center gap-3 border-t border-line px-5 py-3 text-2xs text-muted">
                <span>{{ __(':apps–:apps2 / :apps3 uygulama', ['apps' => $apps->firstItem(), 'apps2' => $apps->lastItem(), 'apps3' => $apps->total()]) }}</span>
                @if ($apps->hasPages())
                    <span class="ml-auto flex items-center gap-1">
                        <button type="button" wire:click="previousPage" @disabled($apps->onFirstPage()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Önceki') }}</button>
                        <span class="px-2">{{ __('Sayfa :apps / :apps2', ['apps' => $apps->currentPage(), 'apps2' => $apps->lastPage()]) }}</span>
                        <button type="button" wire:click="nextPage" @disabled(! $apps->hasMorePages()) class="h-7 rounded-md border border-line px-2.5 text-[12.5px] text-fg hover:bg-surface-2 disabled:opacity-40">{{ __('Sonraki') }}</button>
                    </span>
                @endif
            </div>
        @endif
    </section>

    {{-- Kurulum --}}
    @if ($canInstall)
    <x-modal :title="__('Laravel uygulaması kur')" show="installing" size="max-w-2xl">
        <form wire:submit="install" class="flex max-h-[calc(100vh-2rem)] flex-col">
            <h2 class="flex h-12 shrink-0 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Laravel uygulaması kur') }}</h2>
            @if ($step === 'key')
                @php $host = LaravelIndex::PROVIDERS[$provider][0] ?? 'Git'; @endphp
                <div class="space-y-3 p-5 text-[13.5px]">
                    <p><x-t :text="$provider === 'other' ? 'Depo özel olduğu için Serfex bu site için bir dağıtım anahtarı oluşturdu. Aşağıdaki açık anahtarı deponun :part bölümüne :part2 olarak ekleyin, sonra kurulumu başlatın.' : 'Depo özel olduğu için Serfex bu site için bir dağıtım anahtarı oluşturdu. Aşağıdaki açık anahtarı :host deposunun :part bölümüne :part2 olarak ekleyin, sonra kurulumu başlatın.'" :with="['host' => $host]"><x-slot:part><span class="font-medium">{{ __('Deploy keys') }}</span></x-slot:part><x-slot:part2><span class="font-medium">{{ __('salt okunur') }}</span></x-slot:part2></x-t></p>
                    <div x-data="{ copied: false }" class="space-y-2">
                        <pre class="rounded-md border border-line bg-surface-2 p-3 font-mono text-[12px] break-all whitespace-pre-wrap">{{ $deployKey }}</pre>
                        <button type="button" @click="navigator.clipboard.writeText(@js($deployKey)); copied = true; setTimeout(() => copied = false, 2000)" class="h-8 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">
                            <span x-show="! copied">{{ __('Anahtarı kopyala') }}</span><span x-show="copied" x-cloak>{{ __('Kopyalandı') }}</span>
                        </button>
                    </div>
                    @if ($keyError)
                        <p class="flex items-start gap-2 rounded-md bg-warn-soft px-3 py-2"><x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />{{ $keyError }}</p>
                    @endif
                    @if ($agentError)
                        <p class="text-[13px] text-crit">{{ $agentError }}</p>
                    @endif
                </div>
                <div class="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="backToForm" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Geri') }}</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="install" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Anahtarı ekledim, kur') }}</button>
                </div>
            @else
                <div class="grid grid-cols-1 gap-x-5 gap-y-4 overflow-y-auto p-5 text-[13.5px] sm:grid-cols-[170px_1fr]">
                    @if ($agentError)
                        <p class="text-[13px] text-crit sm:col-span-2">{{ $agentError }}</p>
                    @endif

                    <label for="lv-site" class="font-medium sm:pt-2">{{ __('Site') }}</label>
                    <div>
                        @if ($sites->isEmpty())
                            <p class="pt-2 text-muted"><x-t text="Laravel kurulabilecek site yok. Önce :link."><x-slot:link><a href="{{ route('sites.index') }}" wire:navigate class="text-accent hover:underline">{{ __('bir site ekleyin') }}</a></x-slot:link></x-t></p>
                        @else
                            <select id="lv-site" wire:model.live="siteId" class="{{ $field }} font-mono {{ $errors->has('siteId') ? 'border-crit' : 'border-line' }}">
                                @foreach ($sites as $s)
                                    <option value="{{ $s->id }}">{{ $s->domain }}</option>
                                @endforeach
                            </select>
                            @error('siteId')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted"><x-t text="Uygulama :part altına kurulur, site kökü :part2 yapılır. Sitedeki mevcut dosyalara dokunulmaz."><x-slot:part><span class="font-mono">laravel/</span></x-slot:part><x-slot:part2><span class="font-mono">laravel/current/public</span></x-slot:part2></x-t></p>@enderror
                        @endif
                    </div>

                    <p class="font-medium sm:pt-1">{{ __('Kaynak') }}</p>
                    <div class="grid grid-cols-1 gap-2 {{ $templates->isNotEmpty() ? 'sm:grid-cols-3' : 'sm:grid-cols-2' }}">
                        <label class="{{ $radio }}"><input type="radio" wire:model.live="source" value="create" class="mt-0.5 {{ $check }}"><span><span class="font-medium">{{ __('Yeni proje') }}</span><span class="block text-2xs text-muted">{{ __("Laravel'in son sürümüyle boş uygulama") }}</span></span></label>
                        <label class="{{ $radio }}"><input type="radio" wire:model.live="source" value="git" class="mt-0.5 {{ $check }}"><span><span class="font-medium">{{ __('Git deposundan') }}</span><span class="block text-2xs text-muted">{{ __('GitHub, GitLab, Bitbucket veya başka bir adres') }}</span></span></label>
                        @if ($templates->isNotEmpty())
                            <label class="{{ $radio }}"><input type="radio" wire:model.live="source" value="template" class="mt-0.5 {{ $check }}"><span><span class="font-medium">{{ __('Şablondan') }}</span><span class="block text-2xs text-muted">{{ __('Kendi uygulamalarınız') }}</span></span></label>
                        @endif
                    </div>

                    @if ($source === 'create')
                        <p class="font-medium sm:pt-2">{{ __('Başlangıç kiti') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach (LaravelApps::STARTER_KITS as $key => [$label])
                                <label class="{{ $chip }}"><input type="radio" wire:model="kit" value="{{ $key }}" class="{{ $check }}">{{ $label }}</label>
                            @endforeach
                        </div>
                    @elseif ($source === 'git')
                        <p class="font-medium sm:pt-2">{{ __('Depo') }}</p>
                        <div class="space-y-2">
                            <div class="flex flex-wrap gap-2">
                                @foreach (LaravelIndex::PROVIDERS as $key => [$label])
                                    <label class="{{ $chip }} h-8 text-[13px]"><input type="radio" wire:model.live="provider" value="{{ $key }}" class="{{ $check }}">{{ $label }}</label>
                                @endforeach
                            </div>
                            @if ($picker)
                                @include('livewire.partials.git-picker')
                                <button type="button" wire:click="$set('typeAddress', true)" class="text-[13px] text-accent hover:underline">{{ __('Depo adresini elle yaz') }}</button>
                            @else
                            <input wire:model="repository" aria-label="{{ __('Depo') }}" autocomplete="off" spellcheck="false"
                                   placeholder="{{ $provider === 'other' ? 'https://git.ornek.com/ekip/uygulama.git' : __('kullanıcı/depo veya depo adresi') }}"
                                   class="{{ $field }} font-mono {{ $errors->has('repository') ? 'border-crit' : 'border-line' }}">
                            @error('repository')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <input wire:model="branch" aria-label="{{ __('Dal') }}" placeholder="{{ __('main') }}" spellcheck="false" class="h-9 w-40 rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('branch') ? 'border-crit' : 'border-line' }}">
                                <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="private" class="{{ $check }}">{{ __('Özel depo') }}</label>
                            </div>
                            @error('branch')<p class="text-[13px] text-crit">{{ $message }}</p>@else<p class="text-2xs text-muted">{{ __('Özel depolar bir dağıtım anahtarıyla çekilir; anahtar bir sonraki adımda gösterilir. Dal boş kalırsa deponun varsayılan dalı kullanılır.') }}</p>@enderror
                            @if ($typeAddress && isset($hosts[$provider]))
                                <button type="button" wire:click="$set('typeAddress', false)" class="text-[13px] text-accent hover:underline">{{ __(':provider listesinden seç', ['provider' => $hosts[$provider]]) }}</button>
                            @endif
                            @endif
                        </div>
                    @else
                        <label for="lv-template" class="font-medium sm:pt-2">{{ __('Şablon') }}</label>
                        <div class="space-y-3">
                            <select id="lv-template" wire:model.live="templateId" class="{{ $field }} {{ $errors->has('templateId') ? 'border-crit' : 'border-line' }}">
                                @foreach ($templates as $t)
                                    <option value="{{ $t->id }}">{{ $t->name }}@if ($t->versionLabel()) · {{ $t->versionLabel() }}@endif</option>
                                @endforeach
                            </select>
                            @error('templateId')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                            @if ($picked)
                                @include('livewire.partials.template-form', ['template' => $picked, 'summary' => $summary])
                            @endif
                        </div>
                    @endif

                    @if ($source !== 'template')
                    <label for="lv-php" class="font-medium sm:pt-2">{{ __('PHP sürümü') }}</label>
                    <div>
                        <select id="lv-php" wire:model="phpVersion" class="h-9 w-48 rounded-md border bg-surface px-2.5 {{ $errors->has('phpVersion') ? 'border-crit' : 'border-line' }}">
                            @foreach ($phpVersions ?: [$phpVersion] as $v)
                                <option value="{{ $v }}">PHP {{ $v }}</option>
                            @endforeach
                        </select>
                        @error('phpVersion')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Sitenin PHP sürümü olarak ayarlanır; Composer ve Artisan da bu sürümle çalışır.') }}</p>@enderror
                    </div>

                    <label for="lv-db" class="font-medium sm:pt-2">{{ __('Veritabanı') }}</label>
                    <div class="space-y-2">
                        <select id="lv-db" wire:model.live="database" class="{{ $field }} border-line">
                            @if ($dbServers !== [])
                                <option value="new">{{ __('Yeni veritabanı oluştur (:db_servers)', ['db_servers' => implode(' veya ', $dbServers)]) }}</option>
                            @endif
                            <option value="sqlite">{{ __('SQLite dosyası (sürümler arasında paylaşılır)') }}</option>
                            <option value="existing">{{ __('Var olan MySQL, MariaDB veya PostgreSQL veritabanı') }}</option>
                            <option value="none">{{ __('Veritabanı yok') }}</option>
                        </select>
                        @if ($database === 'new')
                            <div class="grid grid-cols-1 gap-2 rounded-lg border border-line p-3 {{ count($dbServers) > 1 ? 'sm:grid-cols-[10rem_1fr]' : '' }}">
                                @if (count($dbServers) > 1)
                                    <select wire:model="newDbServer" aria-label="{{ __('Veritabanı sunucusu') }}" class="{{ $field }} border-line">
                                        @foreach ($dbServers as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="flex overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('newDbSuffix') ? 'border-crit' : 'border-line' }}">
                                    <span class="grid place-items-center border-r border-line bg-surface-2 px-3 font-mono text-[13px] text-muted">{{ $dbPrefix }}</span>
                                    <input wire:model="newDbSuffix" aria-label="{{ __('Veritabanı adı') }}" class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
                                </div>
                                @error('newDbSuffix')<p class="text-[13px] text-crit sm:col-span-2">{{ $message }}</p>@enderror
                            </div>
                        @endif
                        @if ($database === 'existing')
                            <div class="grid grid-cols-1 gap-2 rounded-lg border border-line p-3 sm:grid-cols-[1fr_1fr]">
                                <select wire:model.live="dbDriver" aria-label="{{ __('Sürücü') }}" class="{{ $field }} border-line">
                                    @foreach (LaravelApps::DB_DRIVERS as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <div class="flex gap-2">
                                    <input wire:model="dbHost" aria-label="{{ __('Sunucu') }}" placeholder="127.0.0.1" class="{{ $field }} font-mono {{ $errors->has('dbHost') ? 'border-crit' : 'border-line' }}">
                                    <input wire:model="dbPort" aria-label="{{ __('Port') }}" class="h-9 w-20 rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('dbPort') ? 'border-crit' : 'border-line' }}">
                                </div>
                                <input wire:model="dbName" aria-label="{{ __('Veritabanı adı') }}" placeholder="{{ __('Veritabanı adı') }}" class="{{ $field }} font-mono {{ $errors->has('dbName') ? 'border-crit' : 'border-line' }}">
                                <input wire:model="dbUser" aria-label="{{ __('Kullanıcı adı') }}" placeholder="{{ __('Kullanıcı adı') }}" autocomplete="off" class="{{ $field }} font-mono {{ $errors->has('dbUser') ? 'border-crit' : 'border-line' }}">
                                <input wire:model="dbPassword" type="password" aria-label="{{ __('Parola') }}" placeholder="{{ __('Parola') }}" autocomplete="new-password" class="{{ $field }} border-line sm:col-span-2">
                                @foreach (['dbHost', 'dbPort', 'dbName', 'dbUser'] as $f)
                                    @error($f)<p class="text-[13px] text-crit sm:col-span-2">{{ $message }}</p>@enderror
                                @endforeach
                            </div>
                        @endif
                        <p class="text-2xs text-muted">@if ($database === 'new')<x-t text="Veritabanı ve aynı adlı kullanıcısı oluşturulur, güçlü bir parolayla :part dosyasına yazılır ve kurulumda :part2 çalışır."><x-slot:part><span class="font-mono">.env</span></x-slot:part><x-slot:part2><span class="font-mono">migrate</span></x-slot:part2></x-t>@else <x-t text="Bilgiler :part dosyasına yazılır ve kurulumda :part2 çalışır."><x-slot:part><span class="font-mono">.env</span></x-slot:part><x-slot:part2><span class="font-mono">migrate</span></x-slot:part2></x-t>@endif</p>
                    </div>

                    <p class="font-medium sm:pt-1">{{ __('Ön yüz') }}</p>
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="frontend" class="{{ $check }}">{{ __('Bağımlılıkları yükle ve derle') }}</label>
                        <select wire:model="packageManager" aria-label="{{ __('Paket yöneticisi') }}" class="h-8 rounded-md border border-line bg-surface px-2 font-mono text-[13px]">
                            @foreach (LaravelApps::PACKAGE_MANAGERS as $pm)
                                <option>{{ $pm }}</option>
                            @endforeach
                        </select>
                    </div>

                    <p class="font-medium sm:pt-1">{{ __('Hemen kur') }}</p>
                    <div class="space-y-1.5">
                        <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="worker" class="{{ $check }}"><span><x-t text="Kuyruk işçisi (:queue kuyruğu)"><x-slot:queue><span class="font-mono text-[13px]">default</span></x-slot:queue></x-t></span></label>
                        <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="scheduler" class="{{ $check }}"><span><x-t text="Zamanlayıcı (her dakika :command)"><x-slot:command><span class="font-mono text-[13px]">schedule:run</span></x-slot:command></x-t></span></label>
                    </div>
                    @endif
                </div>
                <div class="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('installing', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="submit" @disabled($sites->isEmpty()) wire:loading.attr="disabled" wire:target="install" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ $source === 'git' && $private && ! $picker ? __('Devam') : __('Kur') }}</button>
                </div>
            @endif
        </form>
    </x-modal>

    {{-- Tarama --}}
    <x-modal :title="__('Mevcut Laravel uygulamaları')" show="scanning" size="max-w-2xl">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Mevcut Laravel uygulamaları') }}</h2>
        <div class="text-[13.5px]">
            @if ($agentError && $found === null)
                <p class="px-5 py-4 text-[13px] text-crit">{{ $agentError }}</p>
            @elseif ($found === null)
                <p class="flex items-center gap-2 px-5 py-4 text-muted"><x-icon name="loader-circle" class="animate-spin" />{{ __('Siteler taranıyor…') }}</p>
            @else
                <p class="px-5 pt-4 text-[13px] text-muted">{{ __(':scanned_sites site tarandı, Serfex\'e bağlı olmayan :found Laravel uygulaması bulundu.', ['scanned_sites' => $scannedSites, 'found' => count($found)]) }}</p>
                <ul class="divide-y divide-line px-5 py-3">
                    @forelse ($found as $i => $f)
                        <li wire:key="found-{{ $f['site_id'] }}-{{ $f['path'] }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 py-3">
                            <div class="min-w-0">
                                <p class="font-mono text-[13px] font-medium">{{ $f['domain'] }}<span class="text-muted">/{{ $f['path'] }}</span></p>
                                <p class="text-2xs text-muted">{{ __('Laravel :laravel · site PHP :php', ['laravel' => $f['laravel'] ?: __('sürümü bilinmiyor'), 'php' => $f['php']]) }}@if ($f['php_requirement']) {{ __('· composer.json PHP :php_requirement', ['php_requirement' => $f['php_requirement']]) }}@endif</p>
                                @if ($f['compatible'] === false)
                                    <p class="text-2xs text-crit">{{ __('Uygulama PHP :php_requirement istiyor; sitenin PHP sürümünü değiştirin.', ['php_requirement' => $f['php_requirement']]) }}</p>
                                @endif
                                @if (! $f['served'])
                                    <p class="text-2xs text-warn"><x-t text="Site kökü :path değil; bağlarken düzeltilir."><x-slot:path><span class="font-mono">{{ $f['path'] }}/public</span></x-slot:path></x-t></p>
                                @endif
                            </div>
                            <button type="button" wire:click="adopt({{ $i }})" class="ml-auto h-8 rounded-md bg-accent px-3 text-[13px] font-medium text-accent-fg hover:brightness-110">{{ __('Bağla') }}</button>
                        </li>
                    @empty
                        <li class="py-3 text-muted"><x-t text="Yeni uygulama bulunamadı. Serfex :part, onun alt dizinlerini ve site dizinindeki diğer klasörleri tarar."><x-slot:part><span class="font-mono">public_html</span></x-slot:part></x-t></li>
                    @endforelse
                </ul>
            @endif
        </div>
        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('scanning', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Kapat') }}</button>
        </div>
    </x-modal>
    @endif
</div>
