@php
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2';
    $row = 'grid items-start gap-2 py-4 sm:grid-cols-[200px_1fr] sm:gap-6';
    $icons = ['github' => 'github', 'gitlab' => 'gitlab', 'bitbucket' => 'git-branch'];
    $idLabel = ['github' => 'Client ID', 'gitlab' => 'Application ID', 'bitbucket' => 'Key'];
    $secretLabel = ['github' => 'Client secret', 'gitlab' => 'Secret', 'bitbucket' => 'Secret'];
    $steps = [
        'github' => __('GitHub\'da Settings → Developer settings → OAuth Apps → New OAuth App. Homepage URL olarak panelin adresini, Authorization callback URL olarak aşağıdaki adresi yazın. Oluşan Client ID\'yi ve "Generate a new client secret" ile alınan anahtarı buraya girin.'),
        'gitlab' => __('GitLab\'da Edit profile → Applications → Add new application. Redirect URI olarak aşağıdaki adresi yazın, Confidential işaretli kalsın ve kapsamlardan yalnızca api\'yi seçin. Kendi GitLab sunucunuz için uygulamayı orada oluşturun.'),
        'bitbucket' => __('Bitbucket\'ta çalışma alanının Settings → OAuth consumers → Add consumer sayfasında Callback URL olarak aşağıdaki adresi yazın. İzinler: Account Read, Repositories Admin, Webhooks Read and write. Oluşan Key ve Secret\'ı buraya girin.'),
    ];
@endphp
<x-settings-page current="settings.git" :title="__('Git sağlayıcıları')" :description="__('Kullanıcılar GitHub, GitLab veya Bitbucket hesaplarını bağlayıp depolarını listeden seçer; Serfex dağıtım anahtarını ve webhook\'u depoya kendisi ekler. Her sağlayıcı için orada bir OAuth uygulaması oluşturun.')">
    <section class="rounded-xl border border-line bg-surface">
        @unless ($named)
            <p class="flex items-start gap-2 border-b border-line bg-warn-soft px-5 py-3 text-[13px]">
                <x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />
                <span><x-t text="Panelin bir adı yok, bu yüzden geri dönüş adresleri :base ile başlıyor. Panele ad verirseniz (Genel ayarlar) sağlayıcılardaki adresi de güncelleyin; webhook'lar da sertifika doğrulaması olmadan eklenir."><x-slot:base><span class="font-mono">{{ $panelBase }}</span></x-slot:base></x-t></span>
            </p>
        @endunless
        <div class="divide-y divide-line px-5 text-[13.5px]">
            @foreach ($rows as $key => $r)
                <div class="{{ $row }}" wire:key="provider-{{ $key }}">
                    <span class="flex items-center gap-2 font-medium sm:pt-2"><x-icon :name="$icons[$key]" />{{ $r['label'] }}</span>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <div class="min-w-0 flex-1">
                            @if ($r['on'])
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-ok-soft px-2 py-0.5 text-2xs font-medium text-ok"><i class="size-1.5 rounded-full bg-ok"></i>{{ __('Açık') }}</span>
                                <span class="ml-2 font-mono text-[13px] break-all">{{ $r['client_id'] }}</span>
                                @if ($key === 'gitlab')<span class="ml-1 font-mono text-[13px] text-muted">· {{ $r['url'] }}</span>@endif
                                <p class="mt-1 text-2xs text-muted">{{ $r['accounts'] ? trans_choice(':count hesap bağlı.', $r['accounts']) : __('Henüz bağlanan hesap yok.') }}</p>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-2 px-2 py-0.5 text-2xs font-medium text-muted"><i class="size-1.5 rounded-full bg-muted"></i>{{ __('Kapalı') }}</span>
                                <p class="mt-1 text-2xs text-muted">{{ __('Kullanıcılar depo adresini elle yazar.') }}</p>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="edit('{{ $key }}')" class="{{ $btn }}"><x-icon :name="$r['on'] ? 'pencil' : 'plug'" />{{ $r['on'] ? __('Düzenle') : __('Ayarla') }}</button>
                            @if ($r['on'])
                                <button type="button" wire:click="$set('turningOff', '{{ $key }}')" class="{{ $btn }}">{{ __('Kapat') }}</button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    @if ($editing)
        @php $r = $rows[$editing]; @endphp
        <x-modal :title="__(':provider bağlantısı', ['provider' => $r['label']])" show="editing" close="$wire.editing = null" size="max-w-2xl">
            <form wire:submit="save">
                <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __(':provider bağlantısı', ['provider' => $r['label']]) }}</h2>
                <div class="space-y-4 p-5 text-[13.5px]">
                    <p class="text-muted">{{ $steps[$editing] }} <a href="{{ $r['register'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-accent hover:underline">{{ __(':app\'da aç', ['app' => $r['label']]) }}<x-icon name="external-link" class="size-3.5" /></a></p>
                    <div class="grid items-start gap-x-4 gap-y-3 sm:grid-cols-[10rem_minmax(0,1fr)]">
                        <span class="sm:pt-2">{{ __('Geri dönüş adresi') }}</span>
                        <div x-data="{ copied: false }" class="flex min-w-0 items-center gap-2">
                            <span class="min-w-0 flex-1 rounded-md border border-line bg-surface-2 px-3 py-2 font-mono text-[12.5px] break-all">{{ $r['callback'] }}</span>
                            <button type="button" @click="navigator.clipboard.writeText(@js($r['callback'])); copied = true; setTimeout(() => copied = false, 2000)" class="h-9 shrink-0 rounded-md border border-line px-3 text-[13px] hover:bg-surface-2"><span x-show="! copied">{{ __('Kopyala') }}</span><span x-show="copied" x-cloak>{{ __('Kopyalandı') }}</span></button>
                        </div>

                        @if ($editing === 'gitlab')
                            <label for="gp-url" class="sm:pt-2">{{ __('Sunucu adresi') }}</label>
                            <div>
                                <input id="gp-url" wire:model="form.gitlab.url" spellcheck="false" autocomplete="off" placeholder="https://gitlab.com" class="{{ $field }} font-mono {{ $errors->has('form.gitlab.url') ? 'border-crit' : 'border-line' }}">
                                @error('form.gitlab.url')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ __('GitLab.com ya da kendi GitLab sunucunuz.') }}</p>@enderror
                            </div>
                        @endif

                        <label for="gp-id" class="sm:pt-2">{{ $idLabel[$editing] }}</label>
                        <div>
                            <input id="gp-id" wire:model="form.{{ $editing }}.client_id" spellcheck="false" autocomplete="off" class="{{ $field }} font-mono {{ $errors->has("form.{$editing}.client_id") ? 'border-crit' : 'border-line' }}">
                            @error("form.{$editing}.client_id")<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@enderror
                        </div>

                        <label for="gp-secret" class="sm:pt-2">{{ $secretLabel[$editing] }}</label>
                        <div>
                            <input id="gp-secret" type="password" wire:model="form.{{ $editing }}.client_secret" spellcheck="false" autocomplete="new-password"
                                   placeholder="{{ $r['has_secret'] ? __('kayıtlı · değiştirmek için yenisini yazın') : '' }}"
                                   class="{{ $field }} font-mono {{ $errors->has("form.{$editing}.client_secret") ? 'border-crit' : 'border-line' }}">
                            @error("form.{$editing}.client_secret")<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ __('Şifreli saklanır, bir daha gösterilmez.') }}</p>@enderror
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <button type="button" wire:click="$set('editing', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
                    <button type="submit" class="h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
                </div>
            </form>
        </x-modal>
    @endif

    <x-modal :title="__('Git sağlayıcısını kapat')" show="turningOff" close="$wire.turningOff = null" size="max-w-md">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Git sağlayıcısını kapat') }}</h2>
        <div class="space-y-3 p-5 text-[13.5px]">
            @if ($turningOff)
                <p>{{ __(':provider bağlantısının uygulama bilgileri silinir; depolar artık listeden seçilemez.', ['provider' => $rows[$turningOff]['label']]) }}</p>
                <p class="text-muted">{{ __('Daha önce seçilen depolar dağıtılmaya devam eder. Bağlı hesaplar silinmez; bağlantı yeniden açılınca kullanılır.') }}</p>
            @endif
        </div>
        <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
            <button type="button" wire:click="$set('turningOff', null)" class="h-9 rounded-md border border-line px-3.5 text-[13.5px] hover:bg-surface-2">{{ __('Vazgeç') }}</button>
            <button type="button" wire:click="turnOff" class="h-9 rounded-md bg-crit px-3.5 text-[13.5px] font-medium text-on-crit hover:brightness-110">{{ __('Kapat') }}</button>
        </div>
    </x-modal>
</x-settings-page>
