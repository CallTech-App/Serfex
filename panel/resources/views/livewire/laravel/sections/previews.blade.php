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
            <p class="font-medium">Önizleme için Git deposu gerekir</p>
            <p class="mt-1 text-[13px] text-muted">Önizleme ortamları depodaki bir dalı ayrı bir alt alan adına kurar. Önce Dağıtımlar bölümünden bir depo bağlayıp dağıtın.</p>
        </div>
    @else
        <dl class="divide-y divide-line px-5 py-2 text-[13.5px]">
            <div class="{{ $dl }}">
                <dt class="text-muted">Pull request önizlemeleri</dt>
                <dd class="space-y-1">
                    <label class="flex items-center gap-2.5"><input type="checkbox" @checked($app->previews_enabled) wire:change="setting('previews_enabled', $event.target.checked)" @disabled(! $operate) class="{{ $check }}">Pull request açılınca önizleme kur, kapanınca sil</label>
                    <p class="text-2xs text-muted">Dağıtımlar bölümündeki webhook adresine {{ $app->providerLabel() }} için pull request olaylarını da açın (GitHub: <span class="font-mono">Pull requests</span>, GitLab: <span class="font-mono">Merge request events</span>). Çatallardan (fork) gelen pull request'ler kurulmaz.</p>
                </dd>
            </div>
            <div class="{{ $dl }}">
                <dt class="text-muted">Adres</dt>
                <dd><span class="font-mono text-[13px]">pr-{numara}.{{ $domain }}</span> <span class="text-muted">ya da elle açılanlarda</span> <span class="font-mono text-[13px]">{dal}.{{ $domain }}</span>
                    <p class="mt-1 text-2xs text-muted">Alan adının DNS'i bu sunucudaysa kayıt ve Let's Encrypt sertifikası otomatik gelir; başka yerdeyse orada <span class="font-mono">*.{{ $domain }}</span> kaydını bu sunucuya yönlendirin.</p>
                </dd>
            </div>
            <div class="{{ $dl }}">
                <dt class="text-muted">Veritabanı</dt>
                <dd class="space-y-1">
                    <p>Her önizlemeye boş bir SQLite veritabanı; geçişler kurulumda çalışır.</p>
                    <label class="flex items-center gap-2.5"><input type="checkbox" @checked($app->preview_seed) wire:change="setting('preview_seed', $event.target.checked)" @disabled(! $operate) class="{{ $check }}">Kurulumdan sonra <span class="font-mono text-[13px]">db:seed</span> çalıştır</label>
                </dd>
            </div>
            <form wire:submit="saveSettings" class="{{ $dl }}">
                <dt class="text-muted sm:pt-1">.env</dt>
                <dd class="max-w-xl space-y-2">
                    <p class="text-[13px]">Uygulamanın .env dosyası kopyalanır; kuyruk, e-posta, önbellek ve oturum yalnızca önizlemenin kendisinde kalır (<span class="font-mono text-2xs">{{ implode(', ', array_diff($fixed, ['APP_KEY'])) }}</span>), yeni bir <span class="font-mono text-2xs">APP_KEY</span> üretilir.</p>
                    <textarea wire:model="envText" rows="3" aria-label="Önizlemeye özel .env değerleri" spellcheck="false" @disabled(! $operate) placeholder="STRIPE_KEY=pk_test_…&#10;FEATURE_NEW_CHECKOUT=true"
                              class="w-full rounded-md border bg-surface px-3 py-2 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('envText') ? 'border-crit' : 'border-line' }}"></textarea>
                    @error('envText')<p class="text-[13px] text-crit">{{ $message }}</p>@else<p class="text-2xs text-muted">Önizlemelere özel değerler; en son yazılır, yukarıdakilerin üstüne geçer.</p>@enderror
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-muted">En çok</span>
                        <input type="number" min="1" max="20" wire:model="limit" aria-label="Önizleme sınırı" @disabled(! $operate) class="num h-8 w-20 rounded-md border bg-surface px-2.5 outline-none focus:border-accent {{ $errors->has('limit') ? 'border-crit' : 'border-line' }}">
                        <span class="text-muted">önizleme</span>
                        @if ($operate)<button type="submit" class="ml-auto h-8 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2">Kaydet</button>@endif
                    </div>
                    @error('limit')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
                </dd>
            </form>
        </dl>

        <div class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3">
            <p class="text-2xs font-medium tracking-[.06em] text-muted uppercase">Önizlemeler · {{ $previews->where('status', '!=', 'deleting')->count() }} / {{ $app->preview_limit }}</p>
            @if ($operate)
                <button type="button" wire:click="openCreate" class="ml-auto flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2"><x-icon name="plus" />Dal önizlemesi aç</button>
            @endif
        </div>
        <div class="relative overflow-x-auto border-t border-line">
            <table class="w-full min-w-[680px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">Pull request / dal</th>
                        <th class="px-3 py-2.5 font-medium">Adres</th>
                        <th class="px-3 py-2.5 font-medium">Durum</th>
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
                                    @if ($p->pr_url)<a href="{{ $p->pr_url }}" target="_blank" rel="noopener" class="hover:text-accent">{{ $p->pr_title ?: 'Pull request' }}</a>@else{{ $p->pr_title }}@endif
                                @endif
                                <div class="font-mono text-2xs text-muted">{{ $p->branch }}@if ($p->created_by) <span class="font-sans">· {{ $p->created_by }}</span>@endif</div>
                            </td>
                            <td class="px-3 py-3 whitespace-nowrap"><a href="{{ $address }}" target="_blank" rel="noopener" class="font-mono text-[13px] text-accent hover:underline">{{ $p->domain() }}</a></td>
                            <td class="px-3 py-3"><x-status-badge :status="Previews::state($p)" />@if ($p->error)<div class="mt-0.5 max-w-xs text-2xs break-words text-crit">{{ $p->error }}</div>@endif</td>
                            <td class="space-x-3 px-3 py-3 text-right whitespace-nowrap">
                                @if ($p->previewApp)
                                    <a href="{{ route('laravel.show', $p->previewApp->id) }}" wire:navigate class="{{ $link }}">Yönet</a>
                                    @if ($operate && $p->previewApp->canDeploy())<button type="button" wire:click="redeploy({{ $p->id }})" class="{{ $link }}">Dağıt</button>@endif
                                @elseif ($operate && $p->status === 'failed')
                                    <button type="button" wire:click="retry({{ $p->id }})" class="{{ $link }}">Tekrar dene</button>
                                @endif
                                @if ($operate && $p->status !== 'deleting')
                                    <button type="button" wire:click="delete({{ $p->id }})" wire:confirm="{{ $p->domain() }}, dosyaları ve veritabanıyla silinsin mi?" class="{{ $link }} hover:text-crit">Sil</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-10 text-center"><p class="font-medium">Önizleme ortamı yok</p><p class="mt-1 text-[13px] text-muted">Pull request önizlemelerini açın ya da bir dalı elle önizleyin.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="border-t border-line px-5 py-3 text-2xs text-muted">Her önizleme <span class="font-mono">{{ $app->site->systemUser->name }}</span> hesabında ayrı bir sitedir; uygulamanın dağıtım anahtarıyla çekilir, kuyruk işçisi ve zamanlayıcı çalıştırmaz. Pull request'e yeni commit gelince yeniden dağıtılır. Silinince site, dosyalar, veritabanı, sertifika ve DNS kaydı gider.</p>
    @endunless

    {{-- Dal önizlemesi aç --}}
    <x-modal title="Dal önizlemesi aç" show="creating" size="max-w-md">
        <form wire:submit="create">
            <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">Dal önizlemesi aç</h2>
            <div class="space-y-4 p-5 text-[13.5px]">
                <div>
                    <label for="pv-branch" class="mb-1.5 block">Dal</label>
                    <input id="pv-branch" wire:model.live.debounce.300ms="branch" placeholder="feature/yeni-odeme" spellcheck="false" autocomplete="off"
                           class="h-9 w-full rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('branch') ? 'border-crit' : 'border-line' }}">
                    @error('branch')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">Adres: <span class="font-mono">{{ \App\Services\Laravel\Previews::nameFor($branch ?: 'dal') }}.{{ $domain }}</span></p>@enderror
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                <button type="button" wire:click="$set('creating', false)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">Vazgeç</button>
                <button type="submit" wire:loading.attr="disabled" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-40">Önizleme aç</button>
            </div>
        </form>
    </x-modal>
</div>
