@php
    use App\Livewire\Laravel\Sections\Previews;
    $dl = 'grid gap-1 py-3 sm:grid-cols-[230px_1fr] sm:gap-6';
    $check = 'size-4 accent-[rgb(var(--accent))]';
    $link = 'text-[13px] whitespace-nowrap text-muted hover:text-fg';
    $domain = $app->site->domain;
    $scheme = $app->site->hasHttps() ? 'https' : 'http';
@endphp
<div>
    @if ($busy)
        <div wire:poll.3s hidden></div>
    @endif
    @if ($agentError)
        <p class="border-b border-line bg-crit-soft/40 px-5 py-2.5 text-[13px] text-crit">{{ $agentError }}</p>
    @endif

    @unless ($available)
        <div class="px-5 py-10 text-center text-[13.5px]">
            <p class="font-medium">{{ __('Önizleme için Git deposu gerekir') }}</p>
            <p class="mt-1 text-[13px] text-muted">{{ __('Önizleme ortamları depodaki bir dalı ayrı bir alt alan adına kurar. Önce Dağıtımlar bölümünden bir depo bağlayıp dağıtın.') }}</p>
        </div>
    @else
        <dl class="divide-y divide-line px-5 py-2 text-[13.5px]">
            <div class="{{ $dl }}">
                <dt class="text-muted">{{ __('Pull request önizlemeleri') }}</dt>
                <dd class="space-y-1">
                    <label class="flex items-center gap-2.5"><input type="checkbox" @checked($app->previews_enabled) wire:change="setting('previews_enabled', $event.target.checked)" @disabled(! $operate) class="{{ $check }}">{{ __('Pull request açılınca önizleme kur, kapanınca sil') }}</label>
                    <p class="text-2xs text-muted"><x-t text="Dağıtımlar bölümündeki webhook adresine :app için pull request olaylarını da açın (GitHub: :part, GitLab: :part2). Çatallardan (fork) gelen pull request'ler kurulmaz." :with="['app' => $app->providerLabel()]"><x-slot:part><span class="font-mono">Pull requests</span></x-slot:part><x-slot:part2><span class="font-mono">Merge request events</span></x-slot:part2></x-t></p>
                </dd>
            </div>
            <div class="{{ $dl }}">
                <dt class="text-muted">{{ __('Adres') }}</dt>
                <dd><span class="font-mono text-[13px]">pr-{{ __('{numara}') }}.{{ $domain }}</span> <span class="text-muted">{{ __('ya da elle açılanlarda') }}</span> <span class="font-mono text-[13px]">{{ __('{dal}') }}.{{ $domain }}</span>
                    <p class="mt-1 text-2xs text-muted"><x-t text="Alan adının DNS'i bu sunucudaysa kayıt ve Let's Encrypt sertifikası otomatik gelir; başka yerdeyse orada :domain kaydını bu sunucuya yönlendirin."><x-slot:domain><span class="font-mono">*.{{ $domain }}</span></x-slot:domain></x-t></p>
                </dd>
            </div>
            <div class="{{ $dl }}">
                <dt class="text-muted">{{ __('Veritabanı') }}</dt>
                <dd class="space-y-1">
                    <p>{{ __('Her önizlemeye boş bir SQLite veritabanı; geçişler kurulumda çalışır.') }}</p>
                    <label class="flex items-center gap-2.5"><input type="checkbox" @checked($app->preview_seed) wire:change="setting('preview_seed', $event.target.checked)" @disabled(! $operate) class="{{ $check }}"><x-t text="Kurulumdan sonra :part çalıştır"><x-slot:part><span class="font-mono text-[13px]">db:seed</span></x-slot:part></x-t></label>
                </dd>
            </div>
            <form wire:submit="saveSettings" class="{{ $dl }}">
                <dt class="text-muted sm:pt-1">.env</dt>
                <dd class="max-w-xl space-y-2">
                    <p class="text-[13px]"><x-t text="Uygulamanın .env dosyası kopyalanır; kuyruk, e-posta, önbellek ve oturum yalnızca önizlemenin kendisinde kalır (:keys), yeni bir :part üretilir."><x-slot:keys><span class="font-mono text-2xs">{{ implode(', ', array_diff($fixed, ['APP_KEY'])) }}</span></x-slot:keys><x-slot:part><span class="font-mono text-2xs">APP_KEY</span></x-slot:part></x-t></p>
                    <textarea wire:model="envText" rows="3" aria-label="{{ __('Önizlemeye özel .env değerleri') }}" spellcheck="false" @disabled(! $operate) placeholder="STRIPE_KEY=pk_test_…&#10;FEATURE_NEW_CHECKOUT=true"
                              class="w-full rounded-md border bg-surface px-3 py-2 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('envText') ? 'border-crit' : 'border-line' }}"></textarea>
                    @error('envText')<p class="text-[13px] text-crit">{{ $message }}</p>@else<p class="text-2xs text-muted">{{ __('Önizlemelere özel değerler; en son yazılır, yukarıdakilerin üstüne geçer.') }}</p>@enderror
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-muted">{{ __('En çok') }}</span>
                        <input type="number" min="1" max="20" wire:model="limit" aria-label="{{ __('Önizleme sınırı') }}" @disabled(! $operate) class="num h-8 w-20 rounded-md border bg-surface px-2.5 outline-none focus:border-accent {{ $errors->has('limit') ? 'border-crit' : 'border-line' }}">
                        <span class="text-muted">{{ __('önizleme') }}</span>
                        @if ($operate)<button type="submit" class="ml-auto h-8 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">{{ __('Kaydet') }}</button>@endif
                    </div>
                    @error('limit')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                </dd>
            </form>
        </dl>

        <div class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3">
            <p class="text-2xs font-medium tracking-[.06em] text-muted uppercase">{{ __('Önizlemeler · :previews / :preview_limit', ['previews' => $previews->where('status', '!=', 'deleting')->count(), 'preview_limit' => $app->preview_limit]) }}</p>
            @if ($operate)
                <button type="button" wire:click="openCreate" class="ml-auto flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2"><x-icon name="plus" />{{ __('Dal önizlemesi aç') }}</button>
            @endif
        </div>
        <div class="relative overflow-x-auto border-t border-line">
            <table class="w-full min-w-[680px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Pull request / dal') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Adres') }}</th>
                        <th class="px-3 py-2.5 font-medium">{{ __('Durum') }}</th>
                        <th class="px-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($previews as $p)
                        @php $address = ($p->previewApp?->site->hasHttps() ? 'https' : 'http').'://'.$p->domain(); @endphp
                        <tr wire:key="pv-{{ $p->id }}" class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5">
                                @if ($p->pr_number)
                                    <span class="font-mono text-[13px]">#{{ $p->pr_number }}</span>
                                    @if ($p->pr_url)<a href="{{ $p->pr_url }}" target="_blank" rel="noopener" class="hover:text-accent">{{ $p->pr_title ?: __('Pull request') }}</a>@else{{ $p->pr_title }}@endif
                                @endif
                                <div class="font-mono text-2xs text-muted">{{ $p->branch }}@if ($p->created_by) <span class="font-sans">· {{ $p->created_by }}</span>@endif</div>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap"><a href="{{ $address }}" target="_blank" rel="noopener" class="font-mono text-[13px] text-accent hover:underline">{{ $p->domain() }}</a></td>
                            <td class="px-3 py-3"><x-status-badge :status="Previews::state($p)" />@if ($p->error)<div class="mt-0.5 max-w-xs text-2xs break-words text-crit">{{ $p->error }}</div>@endif</td>
                            <td class="space-x-3 px-3 py-3 text-right whitespace-nowrap">
                                @if ($p->previewApp)
                                    <a href="{{ route('laravel.show', $p->previewApp->id) }}" wire:navigate class="{{ $link }}">{{ __('Yönet') }}</a>
                                    @if ($operate && $p->previewApp->canDeploy())<button type="button" wire:click="redeploy({{ $p->id }})" class="{{ $link }}">{{ __('Dağıt') }}</button>@endif
                                @elseif ($operate && $p->status === 'failed')
                                    <button type="button" wire:click="retry({{ $p->id }})" class="{{ $link }}">{{ __('Tekrar dene') }}</button>
                                @endif
                                @if ($operate && $p->status !== 'deleting')
                                    <button type="button" wire:click="delete({{ $p->id }})" wire:confirm="{{ __(':p, dosyaları ve veritabanıyla silinsin mi?', ['p' => $p->domain()]) }}" class="{{ $link }} hover:text-crit">{{ __('Sil') }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-10 text-center"><p class="font-medium">{{ __('Önizleme ortamı yok') }}</p><p class="mt-1 text-[13px] text-muted">{{ __('Pull request önizlemelerini açın ya da bir dalı elle önizleyin.') }}</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="border-t border-line px-5 py-3 text-2xs text-muted"><x-t text="Her önizleme :name hesabında ayrı bir sitedir; uygulamanın dağıtım anahtarıyla çekilir, kuyruk işçisi ve zamanlayıcı çalıştırmaz. Pull request'e yeni commit gelince yeniden dağıtılır. Silinince site, dosyalar, veritabanı, sertifika ve DNS kaydı gider."><x-slot:name><span class="font-mono">{{ $app->site->systemUser->name }}</span></x-slot:name></x-t></p>
    @endunless

    {{-- Dal önizlemesi aç --}}
    <x-modal :title="__('Dal önizlemesi aç')" show="creating" size="max-w-md">
        <form wire:submit="create">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Dal önizlemesi aç') }}</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div>
                    <label for="pv-branch" class="mb-1.5 block">{{ __('Dal') }}</label>
                    <input id="pv-branch" wire:model.live.debounce.300ms="branch" placeholder="{{ __('feature/yeni-odeme') }}" spellcheck="false" autocomplete="off"
                           class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('branch') ? 'border-crit' : 'border-line' }}">
                    @error('branch')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted"><x-t text="Adres: :part"><x-slot:part><span class="font-mono">{{ \App\Services\Laravel\Previews::nameFor($branch ?: 'dal') }}.{{ $domain }}</span></x-slot:part></x-t></p>@enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('creating', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">{{ __('Önizleme aç') }}</button>
            </div>
        </form>
    </x-modal>
</div>
