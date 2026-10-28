@php
    use App\Enums\DeploymentStatus;
    use App\Enums\LaravelAppStatus;
    use App\Models\AppTemplate;
    use App\Services\Laravel\AppTemplates;
    use App\Services\Laravel\LaravelApps;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $sel = 'h-9 rounded-md border border-line bg-surface px-2.5 text-[13px] outline-none focus:border-accent';
    $area = 'w-full rounded-md border bg-surface px-3 py-2 font-mono text-[12.5px] leading-5 outline-none focus:border-accent';
    $row = 'grid items-start gap-2 py-3.5 sm:grid-cols-[200px_1fr] sm:gap-6';
    $chip = 'flex h-8 cursor-pointer items-center gap-2 rounded-md border border-line px-3 text-[13px] has-[:checked]:border-accent has-[:checked]:bg-accent-soft/40';
    $check = 'size-4 accent-[rgb(var(--accent))]';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2';
    $primary = 'h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $secondary = 'h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2';
    $icons = ['github' => 'github', 'gitlab' => 'gitlab', 'bitbucket' => 'git-branch', 'other' => 'link'];
    $package = $template->isPackage();
    $code = fn (string $s) => '<span class="font-mono text-fg">'.e($s).'</span>';
    $badge = fn (string $tone, string $text) => '<span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2 py-0.5 text-2xs font-medium '.['ok' => 'bg-ok-soft text-ok', 'warn' => 'bg-warn-soft text-warn', 'crit' => 'bg-crit-soft text-crit', 'muted' => 'bg-surface-2 text-muted'][$tone].'"><i class="size-1.5 rounded-full '.['ok' => 'bg-ok', 'warn' => 'bg-warn', 'crit' => 'bg-crit', 'muted' => 'bg-muted'][$tone].'"></i>'.e($text).'</span>';
@endphp
<div class="space-y-6">
    @if ($busy)
        <div wire:poll.5s hidden></div>
    @endif
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('settings.general') }}" wire:navigate class="hover:text-accent">{{ __('Araçlar ve Ayarlar') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('settings.templates') }}" wire:navigate class="hover:text-accent">{{ __('Uygulama şablonları') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ $template->name }}</span>
        </nav>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <h1 class="text-[22px] font-semibold tracking-tight">{{ $template->name }}</h1>
            <div class="flex gap-2">
                <button type="button" wire:click="check" wire:loading.attr="disabled" wire:target="check" class="{{ $btn }}"><x-icon name="refresh-cw" />{{ __('Sürümü denetle') }}</button>
                <button type="button" wire:click="export" title="{{ __('Dosya .env şablonundaki değerleri içerir; Git hesabı ve bağlı siteler dahil edilmez.') }}" class="{{ $btn }}"><x-icon name="download" />{{ __('Dışa aktar') }}</button>
            </div>
        </div>
        <p class="mt-1 flex flex-wrap items-center gap-x-2 text-[13px] text-muted">
            <span class="flex items-center gap-1.5"><x-icon :name="$icons[$template->provider] ?? 'link'" class="size-3.5" />@if ($template->webUrl())<a href="{{ $template->webUrl() }}" target="_blank" rel="noopener" class="font-mono hover:text-accent">{{ $template->repositoryLabel() }}</a>@else<span class="font-mono">{{ $template->repositoryLabel() }}</span>@endif</span>
            <span>·</span>
            @if ($package)
                <span>@if ($template->latest_version){{ __('sürüm paketi') }} <span class="font-mono text-fg">{{ $template->latest_version }}</span>@else{{ __('sürüm paketi, son sürüm') }}@endif</span>
            @elseif ($template->ref_type === 'tag')
                <span>{{ __('etiket') }} <span class="font-mono text-fg">{{ $template->ref }}</span></span>
            @else
                <span>{{ __('dal') }} <span class="font-mono text-fg">{{ $template->ref }}</span> @if ($template->latest_commit)<span class="font-mono">{{ substr($template->latest_commit, 0, 7) }}</span>@endif</span>
            @endif
            <span>·</span><span>{{ trans_choice(':count site', $template->apps()->count()) }}</span>
        </p>
        @if ($template->check_error)
            <p class="mt-2 flex items-start gap-2 rounded-md bg-crit-soft px-3 py-2 text-[13px]"><x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-crit" /><span>{{ __('Sürüm okunamadı: :reason', ['reason' => $template->check_error]) }}</span></p>
        @endif
    </div>

    <div class="grid items-start gap-6 md:grid-cols-[200px_minmax(0,1fr)]">
        <x-settings-nav current="settings.templates" />
        <div class="min-w-0 space-y-6">
            <section class="min-w-0 rounded-xl border border-line bg-surface">
                <div class="flex gap-5 overflow-x-auto border-b border-line px-5 text-[13.5px]" role="tablist">
                    @foreach (['general' => __('Genel'), 'env' => __('Ortam (.env)'), 'setup' => __('Kurulum adımları'), 'sites' => __('Siteler')] as $key => $label)
                        <button type="button" wire:click="show('{{ $key }}')" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                                @class(['-mb-px flex h-12 items-center gap-1.5 border-b-2 whitespace-nowrap', 'border-accent font-medium text-fg' => $tab === $key, 'border-transparent text-muted hover:text-fg' => $tab !== $key])>
                            {{ $label }}
                            @if ($key === 'sites' && $behind->count() > 0)<span class="num rounded-full bg-warn-soft px-1.5 text-2xs leading-5 font-semibold text-warn">{{ $behind->count() }}</span>@endif
                        </button>
                    @endforeach
                </div>

                @if ($tab === 'general')
                    <form wire:submit="saveGeneral" class="max-w-3xl px-5 py-2 text-[13.5px]">
                        <div class="divide-y divide-line">
                            <div class="{{ $row }}">
                                <label for="t-name" class="sm:pt-2">{{ __('Ad') }}</label>
                                <div>
                                    <input id="t-name" wire:model="name" maxlength="60" class="{{ $field }} sm:w-80 {{ $errors->has('name') ? 'border-crit' : 'border-line' }}">
                                    @error('name')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ __('Kurulum sırasında listede bu ad ve açıklama görünür.') }}</p>@enderror
                                </div>
                            </div>
                            <div class="{{ $row }}">
                                <label for="t-desc" class="sm:pt-2">{{ __('Açıklama') }}</label>
                                <div><input id="t-desc" wire:model="description" maxlength="300" class="{{ $field }} border-line"></div>
                            </div>
                            <div class="{{ $row }}">
                                <span class="sm:pt-2">{{ $package ? __('Sürüm paketi') : __('Git deposu') }}</span>
                                <div class="space-y-2">
                                    <p class="pt-2 font-mono">{{ $template->repositoryLabel() }}</p>
                                    @if ($template->provider !== 'other' && ($accounts->isNotEmpty() || $template->git_account_id))
                                        <div class="flex flex-wrap items-center gap-2">
                                            <label for="t-account" class="text-muted">{{ __('Git hesabı') }}</label>
                                            <select id="t-account" wire:model="gitAccountId" class="{{ $sel }}">
                                                <option value="">{{ __('Yok (herkese açık depo)') }}</option>
                                                @foreach ($accounts as $a)
                                                    <option value="{{ $a->id }}">{{ $a->username }} ({{ AppTemplate::PROVIDERS[$a->provider] }})</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif
                                    <p class="text-2xs text-muted">
                                        @if ($package)
                                            {{ __('Her kurulumda en son yayımlanan paket indirilir, sitenin app/ klasörüne açılır ve site kökü app/public olur. Sonraki güncellemeleri uygulama kendi panelinden yapar; Serfex karışmaz.') }}
                                        @elseif ($template->private)
                                            {{ __('Özel depo. Her kurulumda Serfex o siteye ayrı, salt okunur bir dağıtım anahtarı üretir ve bu hesap üzerinden depoya ekler. Uygulama kaldırılınca anahtar da kaldırılır.') }}
                                        @else
                                            {{ __('Herkese açık depo; adresinden çekilir.') }}
                                        @endif
                                    </p>
                                </div>
                            </div>
                            <div class="{{ $row }}">
                                <span class="sm:pt-1.5">{{ $package && $template->package_url ? __('Paket adresi') : __('Sürüm') }}</span>
                                <div class="space-y-2">
                                    @if ($package)
                                        @if ($template->package_url)
                                            <input wire:model="packageUrl" aria-label="{{ __('Paket adresi') }}" spellcheck="false" class="{{ $field }} font-mono {{ $errors->has('packageUrl') ? 'border-crit' : 'border-line' }}">
                                            @error('packageUrl')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                                        @else
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="text-muted">{{ __('Son sürüme eklenen dosya') }}</span>
                                                <input wire:model="packageAsset" aria-label="{{ __('Paket dosyası') }}" spellcheck="false" class="h-9 w-56 rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('packageAsset') ? 'border-crit' : 'border-line' }}">
                                            </div>
                                            @error('packageAsset')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                                            @if ($template->latest_package)
                                                <p class="text-2xs text-muted"><x-t text="Şu an: :file (:size)"><x-slot:file><span class="font-mono text-fg">{{ $template->latest_package['name'] ?? '' }}</span></x-slot:file><x-slot:size>{{ number_format(($template->latest_package['size'] ?? 0) / 1048576, 1) }} MB</x-slot:size></x-t></p>
                                            @endif
                                        @endif
                                        <p class="text-2xs text-muted">{{ __('Yeni kurulumlar her zaman en son paketi alır. Siteler sekmesinde her sitenin kendi sürümü görünür.') }}</p>
                                    @else
                                        <div class="flex flex-wrap items-center gap-2">
                                            <label class="{{ $chip }}"><input type="radio" wire:model.live="refType" value="branch" class="{{ $check }}">{{ __('Dal') }}</label>
                                            <label class="{{ $chip }}"><input type="radio" wire:model.live="refType" value="tag" class="{{ $check }}">{{ __('Etiket') }}</label>
                                            <input wire:model="ref" list="t-refs" aria-label="{{ $refType === 'tag' ? __('Etiket') : __('Dal') }}" spellcheck="false" class="h-9 w-48 rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('ref') ? 'border-crit' : 'border-line' }}">
                                            <datalist id="t-refs">
                                                @foreach (array_slice($refs, 0, 100) as $r)
                                                    <option value="{{ $r }}"></option>
                                                @endforeach
                                            </datalist>
                                        </div>
                                        @error('ref')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                                        <p class="text-2xs text-muted">{{ __('Yeni kurulumlar bu sürümle yapılır. Sürümü değiştirdiğinizde bağlı siteler "Güncelleme var" gösterir; siz güncelleyene kadar hiçbir site değişmez. Dal seçerseniz o dala gelen her yeni commit yeni sürüm sayılır.') }}</p>
                                    @endif
                                </div>
                            </div>
                            <div class="{{ $row }}">
                                <label for="t-php" class="sm:pt-2">{{ __('PHP sürümü') }}</label>
                                <div>
                                    <select id="t-php" wire:model="phpVersion" class="{{ $sel }} w-44">
                                        <option value="">{{ __('Sitenin sürümü') }}</option>
                                        @foreach (array_unique(array_filter([...$phpVersions, $phpVersion])) as $v)
                                            <option value="{{ $v }}">{{ $v }}</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1.5 text-2xs text-muted">{{ __('Kurulumda site bu PHP sürümüne geçirilir.') }}</p>
                                </div>
                            </div>
                            <div class="{{ $row }}">
                                <span class="sm:pt-1.5">{{ __('Veritabanı') }}</span>
                                <div class="space-y-2">
                                    <div class="flex flex-wrap gap-2">
                                        @foreach (AppTemplate::DATABASES as $key => $label)
                                            <label class="{{ $chip }}"><input type="radio" wire:model.live="database" value="{{ $key }}" class="{{ $check }}">{{ __($label) }}</label>
                                        @endforeach
                                    </div>
                                    @if (in_array($database, ['mysql', 'postgresql'], true) && ! in_array($database, $dbServers, true))
                                        <p class="text-[13px] text-warn">{{ __('Bu veritabanı sunucusu kurulu değil; kurulumlar başarısız olur. Yazılım Yönetimi\'nden kurun.') }}</p>
                                    @endif
                                    <p class="text-2xs text-muted">
                                        @if (in_array($database, ['mysql', 'postgresql'], true))
                                            <x-t text="Her siteye kendi veritabanı ve kullanıcısı açılır: :name. Müşterinin paketindeki veritabanı sınırına sayılır."><x-slot:name><span class="font-mono">&lt;{{ __('hesap') }}&gt;_{{ $template->databaseSuffix() }}</span></x-slot:name></x-t>
                                        @elseif ($database === 'sqlite')
                                            {{ __('Uygulamanın yanında bir SQLite dosyası; sürümler arasında korunur.') }}
                                        @else
                                            {{ __('Veritabanı açılmaz.') }}
                                        @endif
                                        <x-t text="Kurulum komutlarında :values ile kullanılır."><x-slot:values><span class="font-mono">{db.name}</span>, <span class="font-mono">{db.user}</span>, <span class="font-mono">{db.password}</span></x-slot:values></x-t>
                                    </p>
                                </div>
                            </div>
                            <div class="{{ $row }}">
                                <span class="sm:pt-0.5">{{ $package ? __('Süreçler') : __('Derleme ve süreçler') }}</span>
                                <div class="space-y-1.5">
                                    @unless ($package)
                                        <label class="flex flex-wrap items-center gap-2.5"><input type="checkbox" wire:model="frontend" class="{{ $check }}">{{ __('Ön yüzü derle') }}
                                            <select wire:model="packageManager" aria-label="{{ __('Paket yöneticisi') }}" class="h-7 rounded-md border border-line bg-surface px-2 font-mono text-[12.5px]">
                                                @foreach (LaravelApps::PACKAGE_MANAGERS as $pm)
                                                    <option value="{{ $pm }}">{{ $pm }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endunless
                                    <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="worker" class="{{ $check }}">{{ __('Kuyruk işçisi çalıştır') }} <span class="text-muted">{{ __('(1 işçi)') }}</span></label>
                                    <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="scheduler" class="{{ $check }}">{{ __('Zamanlayıcıyı çalıştır') }} <span class="font-mono text-muted">schedule:run</span></label>
                                </div>
                            </div>
                            @unless ($package)
                                <div class="{{ $row }}">
                                    <span class="sm:pt-0.5">{{ __('Güncellerken') }}</span>
                                    <div class="space-y-1.5">
                                        <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="updateMaintenance" class="{{ $check }}">{{ __('Ziyaretçilere bakım sayfası göster') }}</label>
                                        <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="updateBackup" class="{{ $check }}">{{ __('Önce veritabanını yedekle') }}</label>
                                        <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="updateRollback" class="{{ $check }}">{{ __('Site açılmazsa önceki sürüme otomatik dön') }}</label>
                                    </div>
                                </div>
                            @endunless
                            <div class="{{ $row }}">
                                <span class="sm:pt-1.5">{{ __('Kimler kurabilir') }}</span>
                                <div class="space-y-1.5">
                                    <label class="flex items-center gap-2.5"><input type="radio" name="t-who" @checked(! $customersCanInstall) wire:click="$set('customersCanInstall', false)" class="{{ $check }}">{{ __('Yalnızca yöneticiler') }}</label>
                                    <label class="flex items-center gap-2.5"><input type="radio" name="t-who" @checked($customersCanInstall) wire:click="$set('customersCanInstall', true)" class="{{ $check }}">{{ __('Müşteriler de kendi sitelerine kurabilir') }}</label>
                                    <p class="text-2xs text-muted">{{ __('Müşteri yalnızca şablonun adını ve açıklamasını görür; depo, .env şablonu ve komutlar ona gösterilmez.') }}</p>
                                </div>
                            </div>
                            <details class="py-3.5" @if ($errors->has('healthPath') || $errors->has('versionFile')) open @endif>
                                <summary class="cursor-pointer text-muted hover:text-fg">{{ __('Gelişmiş') }}</summary>
                                <div class="mt-3 grid items-start gap-x-6 gap-y-3 sm:grid-cols-[200px_1fr]">
                                    <label for="t-health" class="sm:pt-2">{{ __('Sağlık adresi') }}</label>
                                    <div>
                                        <input id="t-health" wire:model="healthPath" class="{{ $sel }} w-48 font-mono {{ $errors->has('healthPath') ? 'border-crit' : '' }}">
                                        @error('healthPath')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                                    </div>
                                    @if ($package)
                                        <label for="t-version" class="sm:pt-2">{{ __('Sürüm dosyası') }}</label>
                                        <div>
                                            <input id="t-version" wire:model="versionFile" class="{{ $sel }} w-48 font-mono {{ $errors->has('versionFile') ? 'border-crit' : '' }}">
                                            @error('versionFile')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Sitelerin sürümü uygulamanın bu dosyasından okunur.') }}</p>@enderror
                                        </div>
                                    @endif
                                </div>
                            </details>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 border-t border-line py-4">
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveGeneral" class="{{ $primary }}">{{ __('Kaydet') }}</button>
                            <button type="button" wire:click="revert" class="{{ $secondary }}">{{ __('Vazgeç') }}</button>
                            <button type="button" wire:click="$set('deleting', true)" class="ml-auto text-[13px] text-crit hover:underline">{{ __('Şablonu sil') }}</button>
                        </div>
                    </form>
                @elseif ($tab === 'env')
                    <form wire:submit="saveEnv" class="space-y-5 px-5 py-5 text-[13.5px]">
                        <div class="grid items-start gap-2 sm:grid-cols-[200px_1fr] sm:gap-6">
                            <label for="t-env" class="sm:pt-1">{{ __('.env şablonu') }}</label>
                            <div class="min-w-0 space-y-2">
                                <textarea id="t-env" wire:model="envTemplate" rows="9" spellcheck="false" placeholder="MAIL_FROM_ADDRESS=&quot;info@{site.domain}&quot;" class="{{ $area }} {{ $errors->has('envTemplate') ? 'border-crit' : 'border-line' }}"></textarea>
                                @error('envTemplate')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                                <p class="text-2xs text-muted">
                                    @if ($package)
                                        {{ __('İsteğe bağlı. Boş bırakırsanız .env dosyasını uygulamanın kurulum komutu yazar. Doluysa .env.example alınır, Serfex\'in değerleri ve bu satırlar üstüne yazılır.') }}
                                    @else
                                        <x-t text="Kurulumda uygulamanın :file dosyası alınır, bu satırlar üstüne yazılır."><x-slot:file><span class="font-mono">.env.example</span></x-slot:file></x-t>
                                    @endif
                                    {{ __('Kullanılabilir değerler:') }}
                                    {!! collect(AppTemplates::PLACEHOLDERS)->map(fn ($p) => $code($p))->implode(', ') !!}.
                                </p>
                                <p class="flex items-start gap-2 rounded-md bg-surface-2 px-3 py-2 text-2xs text-muted"><x-icon name="info" class="mt-px size-3.5 shrink-0" /><span><x-t text="Bunları Serfex kendisi yazar, şablona koymanız gerekmez: :keys. Güncellemeler :env dosyasına dokunmaz."><x-slot:keys>{!! $code('APP_KEY') !!}, {!! $code('APP_URL') !!}, {!! $code('APP_ENV=production') !!}, {!! $code('APP_DEBUG=false') !!}, {!! $code('DB_*') !!}</x-slot:keys><x-slot:env><span class="font-mono">.env</span></x-slot:env></x-t></span></p>
                            </div>
                        </div>
                        <div class="grid items-start gap-2 sm:grid-cols-[200px_1fr] sm:gap-6">
                            <span class="sm:pt-1">{{ __('Kurulumda sorulanlar') }}</span>
                            <div class="min-w-0 rounded-lg border border-line">
                                @if ($fields === [])
                                    <p class="px-4 py-3 text-[13px] text-muted"><x-t text="Soru yok. Bir soru ekleyip yanıtını :ask olarak kullanın."><x-slot:ask><span class="font-mono">{ask.ANAHTAR}</span></x-slot:ask></x-t></p>
                                @else
                                    <div class="overflow-x-auto">
                                        <table class="w-full min-w-[520px] text-[13px]">
                                            <thead>
                                                <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                                    <th class="py-2 pr-3 pl-4 font-medium">{{ __('Anahtar') }}</th>
                                                    <th class="px-3 py-2 font-medium">{{ __('Soru') }}</th>
                                                    <th class="px-3 py-2 font-medium">{{ __('Varsayılan') }}</th>
                                                    <th class="py-2 pr-4 pl-3"></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-line">
                                                @foreach ($fields as $i => $f)
                                                    <tr wire:key="field-{{ $f['key'] }}">
                                                        <td class="py-2.5 pr-3 pl-4 font-mono">{{ $f['key'] }}</td>
                                                        <td class="px-3 py-2.5">{{ $f['label'] }} <span class="text-2xs text-muted">· {{ collect([$f['type'] === 'file' ? __('dosya') : null, $f['required'] ? __('zorunlu') : __('boş geçilebilir'), $f['secret'] ? __('gizli') : null])->filter()->implode(', ') }}</span></td>
                                                        <td class="px-3 py-2.5 font-mono text-muted">{{ $f['default'] !== '' ? $f['default'] : '—' }}</td>
                                                        <td class="py-2.5 pr-4 pl-3 text-right whitespace-nowrap"><button type="button" wire:click="editField({{ $i }})" class="text-[13px] text-accent hover:underline">{{ __('Düzenle') }}</button></td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                                <div class="border-t border-line px-4 py-2.5"><button type="button" wire:click="editField(-1)" class="flex items-center gap-1.5 text-[13px] text-accent hover:underline"><x-icon name="plus" class="size-3.5" />{{ __('Soru ekle') }}</button></div>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 border-t border-line pt-4 sm:pl-[224px]">
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveEnv" class="{{ $primary }}">{{ __('Kaydet') }}</button>
                            <button type="button" wire:click="revert" class="{{ $secondary }}">{{ __('Vazgeç') }}</button>
                        </div>
                    </form>
                @elseif ($tab === 'setup')
                    <form wire:submit="saveSetup" class="max-w-3xl px-5 py-2 text-[13.5px]">
                        <div class="divide-y divide-line">
                            <div class="{{ $row }}">
                                <label for="t-setup" class="sm:pt-1">{{ __('İlk kurulumdan sonra') }}</label>
                                <div>
                                    <textarea id="t-setup" wire:model="setupCommands" rows="4" spellcheck="false" placeholder="{{ $package ? 'php artisan app:install --no-interaction --url={site.url} --db-database={db.name} --db-username={db.user} --db-password={db.password}' : 'php artisan storage:link' }}" class="{{ $area }} {{ $errors->has('setupCommands') ? 'border-crit' : 'border-line' }}"></textarea>
                                    @error('setupCommands')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                                    <p class="mt-1.5 text-2xs text-muted"><x-t text="Yalnızca kurulumda, bir kez. Satır başına bir komut (:a, :b veya :c); sırayla, sitenin kullanıcısıyla çalışır. Biri başarısız olursa kalanlar çalışmaz; çıktılar kurulum günlüğünde görünür. Yer tutucular .env şablonundakilerle aynıdır."><x-slot:a><span class="font-mono">php artisan</span></x-slot:a><x-slot:b><span class="font-mono">composer</span></x-slot:b><x-slot:c><span class="font-mono">npm</span></x-slot:c></x-t>
                                        @if ($package) {{ __('Uygulamanın kendi kurulum komutunu buraya yazın.') }} @endif</p>
                                </div>
                            </div>
                            <div class="{{ $row }}">
                                <span class="sm:pt-0.5">{{ __('İlk yönetici hesabı') }}</span>
                                <div class="space-y-2">
                                    <label class="flex items-center gap-2.5"><input type="checkbox" wire:model.live="createAdmin" class="{{ $check }}">{{ __('Kurulumda yönetici hesabı oluştur') }}</label>
                                    @if ($createAdmin)
                                        <input wire:model="adminCommand" aria-label="{{ __('Yönetici komutu') }}" spellcheck="false" placeholder="php artisan app:create-admin --name={admin.name} --email={admin.email} --password={admin.password}" class="{{ $field }} font-mono text-[12.5px] {{ $errors->has('adminCommand') ? 'border-crit' : 'border-line' }}">
                                        @error('adminCommand')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                                        <p class="text-2xs text-muted"><x-t text="Kurulum formu ad ve e-posta sorar. Parolayı Serfex üretir, kurulumu başlatan kişiye bir kez gösterir; günlükte gizlenir ve kurulum bitince silinir. Komut, kurulum adımlarından sonra çalışır. Kurulum komutunuz yöneticiyi kendisi oluşturuyorsa bunu kapalı bırakıp :values değerlerini orada kullanabilirsiniz."><x-slot:values><span class="font-mono">{admin.*}</span></x-slot:values></x-t></p>
                                    @else
                                        <p class="text-2xs text-muted"><x-t text="Kapalıyken de kurulum komutlarında :values kullanılabilir; form o zaman yönetici sormaz."><x-slot:values><span class="font-mono">{admin.*}</span></x-slot:values></x-t></p>
                                    @endif
                                </div>
                            </div>
                            @if ($package)
                                <div class="{{ $row }}">
                                    <span class="sm:pt-0.5">{{ __('Güncellemeler') }}</span>
                                    <p class="text-[13px] text-muted">{{ __('Bu uygulama kendini kendi panelinden günceller; Serfex sonraki sürümleri kurmaz. Kuyruk işçisi ve zamanlayıcı Serfex\'te kalır.') }}</p>
                                </div>
                            @else
                                <div class="{{ $row }}">
                                    <span class="sm:pt-0.5">{{ __('Her güncellemede') }}</span>
                                    <div class="space-y-3">
                                        <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="migrate" class="{{ $check }}">{{ __('Veritabanı geçişlerini çalıştır') }} <span class="font-mono text-muted">migrate --force</span></label>
                                        <div>
                                            <p class="mb-1 text-2xs text-muted">{{ __('Yeni sürüm yayına girmeden önce (bash betiği)') }}</p>
                                            <textarea wire:model="beforeActivate" rows="2" spellcheck="false" class="{{ $area }} border-line"></textarea>
                                        </div>
                                        <div>
                                            <p class="mb-1 text-2xs text-muted">{{ __('Yeni sürüm yayına girdikten sonra (bash betiği)') }}</p>
                                            <textarea wire:model="afterActivate" rows="2" spellcheck="false" placeholder="php artisan cache:clear" class="{{ $area }} border-line"></textarea>
                                        </div>
                                        <p class="text-2xs text-muted">{{ __('Bunlar her sitenin Dağıtımlar → Dağıtım adımları ayarına yazılır ve her güncellemede şablondan yeniden alınır.') }}</p>
                                    </div>
                                </div>
                            @endif
                        </div>
                        <div class="flex gap-2 border-t border-line py-4">
                            <button type="submit" wire:loading.attr="disabled" wire:target="saveSetup" class="{{ $primary }}">{{ __('Kaydet') }}</button>
                            <button type="button" wire:click="revert" class="{{ $secondary }}">{{ __('Vazgeç') }}</button>
                        </div>
                    </form>
                @else
                    <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                        @if ($package)
                            <button type="button" wire:click="check" wire:loading.attr="disabled" wire:target="check" class="{{ $btn }}"><x-icon name="refresh-cw" />{{ __('Sürümleri yenile') }}</button>
                        @elseif ($behind->count() > 0)
                            <button type="button" wire:click="$set('confirmingAll', true)" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="rocket" />{{ __("Tümünü :version sürümüne güncelle (:count)", ['version' => $template->versionLabel(), 'count' => $behind->count()]) }}</button>
                        @endif
                        <button type="button" wire:click="openInstall" class="{{ $btn }}"><x-icon name="plus" />{{ __('Siteye kur') }}</button>
                        <label class="relative ml-auto w-full sm:w-56">
                            <x-icon name="search" class="absolute top-1/2 left-3 -translate-y-1/2 text-muted" />
                            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Site ara') }}" aria-label="{{ __('Site ara') }}" class="h-9 w-full rounded-md border border-transparent bg-surface-2 pr-3 pl-9 text-[13.5px] outline-none focus:border-accent focus:bg-surface">
                        </label>
                    </div>
                    @if ($apps->isEmpty())
                        <p class="px-5 py-8 text-center text-[13.5px] text-muted">{{ $search !== '' ? __('Eşleşen site yok.') : __('Bu şablon henüz hiçbir siteye kurulmadı.') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[720px] text-[13.5px]">
                                <thead>
                                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Site') }}</th>
                                        <th class="px-3 py-2.5 font-medium">{{ __('Müşteri') }}</th>
                                        <th class="px-3 py-2.5 font-medium">{{ __('Sürüm') }}</th>
                                        <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
                                        <th class="py-2.5 pr-5 pl-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    @foreach ($apps as $a)
                                        @php
                                            $d = $a->latestDeployment;
                                            $isBehind = $template->isBehind($a);
                                            $running = $a->status->isBusy() || $d?->status->isBusy();
                                        @endphp
                                        <tr class="hover:bg-surface-2/60" wire:key="app-{{ $a->id }}">
                                            <td class="py-3 pr-3 pl-5"><a href="{{ route('laravel.show', $a) }}" wire:navigate class="font-mono text-[13px] font-medium hover:text-accent">{{ $a->site->domain }}</a></td>
                                            <td class="px-3 py-3 whitespace-nowrap">{{ $a->site->customer()?->displayName() ?? '—' }}</td>
                                            <td class="px-3 py-3 font-mono whitespace-nowrap">{{ $a->template_version ?? '—' }}</td>
                                            <td class="px-3 py-3">
                                                @if ($running)
                                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-info-soft px-2 py-0.5 text-2xs font-medium whitespace-nowrap text-info"><x-icon name="loader-circle" class="size-3 animate-spin" />{{ $a->status === LaravelAppStatus::Installing ? __('Kuruluyor') : __('Güncelleniyor') }}</span>
                                                @elseif ($a->status === LaravelAppStatus::Failed)
                                                    {!! $badge('crit', __('Kurulum başarısız')) !!}
                                                @elseif ($d && in_array($d->status, [DeploymentStatus::Failed, DeploymentStatus::RolledBack], true) && $d->kind === 'deploy')
                                                    {!! $badge('crit', $d->status === DeploymentStatus::RolledBack ? __(':version başarısız · eski sürüme dönüldü', ['version' => $d->ref]) : __('Son güncelleme başarısız')) !!}
                                                @elseif ($d && $d->status === DeploymentStatus::Unhealthy)
                                                    {!! $badge('warn', __('Sorun var')) !!}
                                                @elseif ($isBehind)
                                                    {!! $badge('warn', $package ? __('Yeni sürüm var') : __('Güncelleme var')) !!}
                                                @elseif ($a->template_version === null)
                                                    {!! $badge('muted', __('Sürüm bilinmiyor')) !!}
                                                @else
                                                    {!! $badge('ok', __('Güncel')) !!}
                                                @endif
                                            </td>
                                            <td class="py-3 pr-5 pl-3 text-right whitespace-nowrap">
                                                @if (! $package && $isBehind && ! $running && $a->status === LaravelAppStatus::Active)
                                                    <button type="button" wire:click="updateApp({{ $a->id }})" class="text-[13px] text-accent hover:underline">{{ __('Güncelle') }}</button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($package && $behind->count() > 0)
                            <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Sürüm paketiyle kurulan uygulamalar kendi panellerinden güncellenir; Serfex yalnızca sürümlerini gösterir.') }}</p>
                        @endif
                    @endif
                @endif
            </section>
        </div>
    </div>

    {{-- Soru ekle / düzenle --}}
    <x-modal :title="__('Kurulumda sorulan')" show="fieldIndex !== null" close="$wire.fieldIndex = null">
        <form wire:submit="saveField">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ ($fieldIndex ?? -1) >= 0 ? __('Soruyu düzenle') : __('Soru ekle') }}</h2>
            <div class="grid grid-cols-1 gap-x-4 gap-y-3 p-5 text-[13.5px] sm:grid-cols-[8rem_minmax(0,1fr)]">
                <label for="f-key" class="sm:pt-2">{{ __('Anahtar') }}</label>
                <div>
                    <input id="f-key" wire:model="fieldForm.key" spellcheck="false" placeholder="STORE_NAME" class="{{ $field }} font-mono uppercase {{ $errors->has('fieldForm.key') ? 'border-crit' : 'border-line' }}">
                    @error('fieldForm.key')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted"><x-t text="Yanıt :ask olarak kullanılır."><x-slot:ask><span class="font-mono">{ask.{{ $fieldForm['key'] ?: 'ANAHTAR' }}}</span></x-slot:ask></x-t></p>@enderror
                </div>
                <label for="f-label" class="sm:pt-2">{{ __('Soru') }}</label>
                <div>
                    <input id="f-label" wire:model="fieldForm.label" maxlength="80" placeholder="{{ __('Mağaza adı') }}" class="{{ $field }} {{ $errors->has('fieldForm.label') ? 'border-crit' : 'border-line' }}">
                    @error('fieldForm.label')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
                </div>
                <span class="sm:pt-1.5">{{ __('Tür') }}</span>
                <div class="flex flex-wrap gap-2">
                    <label class="{{ $chip }}"><input type="radio" wire:model.live="fieldForm.type" value="text" class="{{ $check }}">{{ __('Metin') }}</label>
                    <label class="{{ $chip }}"><input type="radio" wire:model.live="fieldForm.type" value="file" class="{{ $check }}">{{ __('Dosya') }}</label>
                </div>
                @if ($fieldForm['type'] === 'text')
                    <label for="f-default" class="sm:pt-2">{{ __('Varsayılan') }}</label>
                    <div>
                        <input id="f-default" wire:model="fieldForm.default" spellcheck="false" placeholder="{customer.name}" class="{{ $field }} font-mono border-line">
                        <p class="mt-1 text-2xs text-muted"><x-t text="Formda hazır gelir; :values kullanılabilir."><x-slot:values><span class="font-mono">{site.domain}</span>, <span class="font-mono">{customer.name}</span>, <span class="font-mono">{customer.email}</span></x-slot:values></x-t></p>
                    </div>
                @else
                    <span></span>
                    <p class="text-2xs text-muted"><x-t text="En fazla 256 KB. Uygulamanın :dir klasörüne yazılır; yolu :file ile kullanılır (lisans dosyası gibi)."><x-slot:dir><span class="font-mono">storage/app/serfex/</span></x-slot:dir><x-slot:file><span class="font-mono">{file.{{ $fieldForm['key'] ?: 'ANAHTAR' }}}</span></x-slot:file></x-t></p>
                @endif
                <span></span>
                <div class="space-y-1.5">
                    <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="fieldForm.required" class="{{ $check }}">{{ __('Zorunlu') }}</label>
                    @if ($fieldForm['type'] === 'text')
                        <label class="flex items-center gap-2.5"><input type="checkbox" wire:model="fieldForm.secret" class="{{ $check }}">{{ __('Gizli') }} <span class="text-2xs text-muted">{{ __('· parola alanı, günlükte gizlenir') }}</span></label>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2 border-t border-line px-5 py-3">
                @if (($fieldIndex ?? -1) >= 0)
                    <button type="button" wire:click="removeField({{ $fieldIndex }})" class="text-[13px] text-crit hover:underline">{{ __('Kaldır') }}</button>
                @endif
                <span class="ml-auto flex gap-2">
                    <button type="button" wire:click="$set('fieldIndex', null)" class="{{ $secondary }}">{{ __('Vazgeç') }}</button>
                    <button type="submit" class="{{ $primary }}">{{ __('Kaydet') }}</button>
                </span>
            </div>
        </form>
    </x-modal>

    {{-- Toplu güncelleme --}}
    <x-modal :title="__('Siteleri güncelle')" show="confirmingAll">
        <div>
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ trans_choice(':count siteyi :version sürümüne güncelle', $behind->count(), ['version' => $template->versionLabel()]) }}</h2>
            <div class="space-y-3 p-5 text-[13.5px]">
                <p><x-t text="Siteler :order, birer birer güncellenir. Her sitede:"><x-slot:order><b class="font-medium">{{ __('sırayla') }}</b></x-slot:order></x-t></p>
                <ol class="list-decimal space-y-1 pl-5 text-[13px]">
                    @if ($template->update_backup)<li>{{ __('Veritabanı yedeklenir,') }}</li>@endif
                    @if ($template->update_maintenance)<li>{{ __('ziyaretçilere bakım sayfası gösterilir,') }}</li>@endif
                    <li>{{ __('yeni sürüm kurulur') }}@if ($template->migrate), <span class="font-mono">migrate --force</span>@endif {{ __('ve güncelleme adımları çalışır,') }}</li>
                    <li>{{ $template->update_rollback ? __('site açılıyorsa bakım kalkar; açılmıyorsa önceki sürüme dönülür.') : __('ardından bakım kalkar.') }}</li>
                </ol>
                <label class="flex items-start gap-2.5 rounded-md bg-surface-2 px-3 py-2"><input type="checkbox" wire:model="stopOnFailure" class="mt-0.5 {{ $check }}"><span>{{ __('Bir site başarısız olursa kalanları güncelleme') }} <span class="block text-2xs text-muted">{{ __('Hatalı bir sürüm tüm müşterilere yayılmaz.') }}</span></span></label>
                <p class="font-mono text-[12.5px] break-words text-muted">{{ $template->apps()->with('site')->get()->filter(fn ($a) => $template->isBehind($a))->map(fn ($a) => $a->site->domain)->sort()->implode(' · ') }}</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('confirmingAll', false)" class="{{ $secondary }}">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="updateAll" wire:loading.attr="disabled" class="{{ $primary }}">{{ __('Güncellemeyi başlat') }}</button>
            </div>
        </div>
    </x-modal>

    {{-- Siteye kur --}}
    <x-modal :title="__('Siteye kur')" show="installing" size="max-w-lg">
        <form wire:submit="install" class="flex max-h-[calc(100vh-2rem)] flex-col">
            <h2 class="flex h-12 shrink-0 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __(':name kur', ['name' => $template->name]) }}</h2>
            <div class="space-y-4 overflow-y-auto p-5 text-[13.5px]">
                <div>
                    <label for="t-site" class="mb-1.5 block">{{ __('Site') }}</label>
                    @if ($sites->isEmpty())
                        <p class="text-muted"><x-t text="Uygulama kurulabilecek boş site yok. Yeni siteye :link sırasında Uygulama seçebilirsiniz."><x-slot:link><a href="{{ route('sites.index') }}" wire:navigate class="text-accent hover:underline">{{ __('site eklerken') }}</a></x-slot:link></x-t></p>
                    @else
                        <select id="t-site" wire:model.live="siteId" class="{{ $field }} font-mono {{ $errors->has('siteId') ? 'border-crit' : 'border-line' }}">
                            @foreach ($sites as $s)
                                <option value="{{ $s->id }}">{{ $s->domain }}</option>
                            @endforeach
                        </select>
                        @error('siteId')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">{{ __('Laravel uygulaması olmayan etkin siteler. Sitedeki mevcut dosyalara dokunulmaz.') }}</p>@enderror
                    @endif
                </div>
                @if ($picked && $sites->isNotEmpty())
                    @include('livewire.partials.template-form', ['template' => $picked, 'summary' => $summary])
                @endif
            </div>
            <div class="flex shrink-0 justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('installing', false)" class="{{ $secondary }}">{{ __('Vazgeç') }}</button>
                <button type="submit" @disabled($sites->isEmpty()) wire:loading.attr="disabled" wire:target="install" class="{{ $primary }}">{{ __('Kur') }}</button>
            </div>
        </form>
    </x-modal>

    {{-- Şablonu sil --}}
    <x-modal :title="__('Şablonu sil')" show="deleting">
        <div>
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('":name" silinsin mi?', ['name' => $template->name]) }}</h2>
            <div class="space-y-2 p-5 text-[13.5px]">
                <p>{{ __('Şablondan kurulan uygulamalar silinmez ve çalışmaya devam eder; yalnızca şablonla bağları kopar ve şablondan güncellenemezler.') }}</p>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('deleting', false)" class="{{ $secondary }}">{{ __('Vazgeç') }}</button>
                <button type="button" wire:click="delete" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit hover:brightness-110">{{ __('Şablonu sil') }}</button>
            </div>
        </div>
    </x-modal>
</div>
