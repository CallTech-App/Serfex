@php
    use App\Support\When;
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] font-medium hover:bg-surface-2 disabled:opacity-40';
    $primary = 'flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40';
    $row = 'grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6';
    $icons = ['github' => 'github', 'gitlab' => 'gitlab', 'bitbucket' => 'git-branch'];
@endphp
<div class="space-y-6">
    <div>
        <h1 class="text-[22px] font-semibold tracking-tight">{{ __('Git hesapları') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Bağladığınız hesabın depoları Git dağıtımında ve Laravel kurulumunda listeden seçilir; Serfex dağıtım anahtarını ve webhook\'u depoya kendisi ekler.') }}</p>
    </div>

    <x-account-nav current="account.git" />

    <section class="rounded-xl border border-line bg-surface">
        @if ($rows->isEmpty())
            <div class="px-5 py-10 text-center text-[13.5px]">
                <p class="font-medium">{{ __('Git hesabı bağlantısı açık değil') }}</p>
                @if (auth()->user()->isFullAdmin())
                    <p class="mt-1 text-[13px] text-muted"><x-t text="GitHub, GitLab veya Bitbucket bağlantısını :link sayfasından açın."><x-slot:link><a href="{{ route('settings.git') }}" wire:navigate class="text-accent hover:underline">{{ __('Araçlar ve Ayarlar → Git sağlayıcıları') }}</a></x-slot:link></x-t></p>
                @else
                    <p class="mt-1 text-[13px] text-muted">{{ __('Sunucu yöneticisi GitHub, GitLab veya Bitbucket bağlantısını açtığında hesabınızı buradan bağlayabilirsiniz. O zamana kadar depo adresini elle yazın.') }}</p>
                @endif
            </div>
        @else
            <div class="max-w-4xl divide-y divide-line px-5 text-[13.5px]">
                @foreach ($rows as $key => $r)
                    <div class="{{ $row }}" wire:key="git-{{ $key }}">
                        <span class="flex items-center gap-2 sm:pt-2"><x-icon :name="$icons[$key]" />{{ $r['label'] }}</span>
                        <div>
                            @if ($r['account'])
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                    <span class="font-mono text-[14px]">{{ $r['account']->username }}</span>
                                    <span class="text-[13px] text-muted">{{ __('bağlandı :when', ['when' => When::withTime($r['account']->created_at)]) }}</span>
                                    <span class="ml-auto flex gap-2">
                                        @if ($r['enabled'])
                                            <a href="{{ route('git.connect', [$key, 'return' => route('account.git', absolute: false)]) }}" class="{{ $btn }}"><x-icon name="refresh-cw" />{{ __('Yeniden bağlan') }}</a>
                                        @endif
                                        <button type="button" wire:click="$set('disconnectingId', {{ $r['account']->id }})" class="{{ $btn }}"><x-icon name="unplug" />{{ __('Bağlantıyı kaldır') }}</button>
                                    </span>
                                </div>
                                @php $used = $r['account']->git_repositories_count + $r['account']->laravel_apps_count; @endphp
                                <p class="mt-1.5 text-2xs text-muted">
                                    @if (! $r['enabled'])
                                        {{ __('Bu bağlantı sunucuda kapatıldı; depolar listelenemez.') }}
                                    @elseif ($used)
                                        {{ trans_choice(':count depo bu hesapla seçildi.', $used) }}
                                    @else
                                        {{ __('Depolarınız Git dağıtımında ve Laravel kurulumunda listelenir.') }}
                                    @endif
                                </p>
                            @else
                                <a href="{{ route('git.connect', [$key, 'return' => route('account.git', absolute: false)]) }}" class="{{ $primary }} w-fit"><x-icon name="plug" />{{ __(':provider ile bağlan', ['provider' => $r['label']]) }}</a>
                                <p class="mt-1.5 text-2xs text-muted">{{ __(':provider sayfasında Serfex\'e izin verdikten sonra buraya dönersiniz.', ['provider' => $r['label']]) }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    <x-modal :title="__('Git hesabının bağlantısını kaldır')" show="disconnectingId" close="$wire.disconnectingId = null" size="max-w-md">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Git hesabının bağlantısını kaldır') }}</h2>
        <div class="space-y-3 p-5 text-[13.5px]">
            @if ($disconnecting)
                <p><x-t text=":provider hesabı :name artık Serfex'te kullanılmaz."><x-slot:provider>{{ \App\Services\Git\GitProviders::LABELS[$disconnecting->provider] ?? '' }}</x-slot:provider><x-slot:name><span class="font-mono">{{ $disconnecting->username }}</span></x-slot:name></x-t></p>
                <p class="text-muted">{{ __('Bu hesapla seçilen depolar dağıtılmaya devam eder (dağıtım anahtarıyla). Ancak depo bağlantısı kaldırıldığında Serfex anahtarı ve webhook\'u depodan artık kendisi silemez.') }}</p>
                <p class="text-muted">{{ __('Serfex\'in iznini sağlayıcının ayarlarından (Authorized OAuth Apps / Applications) ayrıca geri alabilirsiniz.') }}</p>
            @endif
        </div>
        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('disconnectingId', null)" class="{{ $btn }}">{{ __('Vazgeç') }}</button>
            <button type="button" wire:click="disconnect" class="flex h-9 items-center gap-2 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit hover:brightness-110">{{ __('Bağlantıyı kaldır') }}</button>
        </div>
    </x-modal>
</div>
