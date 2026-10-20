@php
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $row = 'grid items-start gap-2 py-3.5 sm:grid-cols-[200px_1fr] sm:gap-6';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-40';
    $primary = 'h-9 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-50';
    $slugs = array_keys($steps);
    $number = array_search($step, $slugs, true);
    $mark = fn (string $state) => match ($state) {
        'done' => ['circle-check', 'text-ok'],
        'skipped' => ['circle-dashed', 'text-warn'],
        default => ['circle', 'text-muted'],
    };
    $error = fn (string $name) => $errors->has($name) ? 'border-crit' : 'border-line';
@endphp
<div class="grid items-start gap-6 md:grid-cols-[230px_minmax(0,1fr)]">
    {{-- The steps --}}
    <nav class="scroll-thin flex gap-0.5 overflow-x-auto rounded-xl border border-line bg-surface p-2 text-[13.5px] md:sticky md:top-6 md:flex-col" aria-label="{{ __('Kurulum adımları') }}">
        <span class="flex h-9 shrink-0 items-center gap-2.5 rounded-md px-3 whitespace-nowrap text-muted">
            <x-icon name="circle-check" class="size-4 text-ok" />{{ __('Yönetici hesabı') }}
        </span>
        @foreach ($steps as $slug => $name)
            @php [$icon, $color] = $mark($checklist[$slug]['state']); $on = $step === $slug; @endphp
            <button type="button" wire:click="go('{{ $slug }}')" @if ($on) aria-current="step" x-init="$el.scrollIntoView({ block: 'nearest', inline: 'center' })" @endif
                    class="flex h-9 shrink-0 items-center gap-2.5 rounded-md px-3 text-left whitespace-nowrap {{ $on ? 'bg-accent-soft font-medium text-accent' : 'text-muted hover:bg-surface-2 hover:text-fg' }}">
                <x-icon :name="$icon" class="size-4 {{ $on ? '' : $color }}" />{{ __($name) }}
            </button>
        @endforeach
        <button type="button" wire:click="go('ozet')" @if ($step === 'ozet') aria-current="step" @endif
                class="flex h-9 shrink-0 items-center gap-2.5 rounded-md px-3 text-left whitespace-nowrap {{ $step === 'ozet' ? 'bg-accent-soft font-medium text-accent' : 'text-muted hover:bg-surface-2 hover:text-fg' }}">
            <x-icon name="list-checks" class="size-4" />{{ __('Özet') }}
        </button>
    </nav>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="border-b border-line px-5 py-4">
            <p class="text-2xs font-medium tracking-wide text-muted uppercase">
                {{ $step === 'ozet' ? __('Kurulum · son adım') : __('Kurulum · :n. adım / :total', ['n' => $number + 2, 'total' => count($steps) + 2]) }}
            </p>
            <h1 class="mt-1 text-[18px] font-semibold tracking-tight">
                @switch($step)
                    @case('sunucu') {{ __('Sunucunun adı, adresi ve saati') }} @break
                    @case('ad-sunuculari') {{ __('Ad sunucuları') }} @break
                    @case('panel') {{ __('Panel adresi') }} @break
                    @case('bildirimler') {{ __('Bildirimler') }} @break
                    @case('guvenlik') {{ __('Güvenlik duvarı') }} @break
                    @case('varsayilanlar') {{ __('Yeni sitelerin varsayılanları') }} @break
                    @case('yedekler') {{ __('Yedekler') }} @break
                    @default {{ __('Özet') }}
                @endswitch
            </h1>
            <p class="mt-1 text-[13px] text-muted">
                @switch($step)
                    @case('sunucu') {{ __('Sunucunun kendi adı (hostname), DNS kayıtlarında kullanılacak IP adresleri, saat dilimi ve dil.') }} @break
                    @case('ad-sunuculari') {{ __('Alan adlarınızın bu sunucuyu ad sunucusu olarak kullanması için kendi ad sunucularınız. Sonra da Alan Adları ve DNS → Ayarlar\'dan değiştirebilirsiniz.') }} @break
                    @case('panel') {{ __('Paneli sunucunun adıyla, ayrı bir adla ya da yalnızca IP adresi ve portla açabilirsiniz. Bir ad seçerseniz sertifikası Let\'s Encrypt\'ten alınır.') }} @break
                    @case('bildirimler') {{ __('Sertifika, yedek, disk ya da duran bir hizmet sorunu olduğunda e-posta gidecek adresler.') }} @break
                    @case('guvenlik') {{ __('Açıkken sunucu yalnızca Servik\'in kullandığı portlarda yanıt verir. Panele şu anki bağlantınız hiçbir zaman kesilmez.') }} @break
                    @case('varsayilanlar') {{ __('Yeni bir site eklendiğinde seçili gelecek web sunucusu ve PHP sürümü, ve Servik\'in hangi sürümleri önereceği.') }} @break
                    @case('yedekler') {{ __('Sunucu yedeği her gün tüm siteleri, veritabanlarını ve e-postaları alır.') }} @break
                    @default {{ __('Neler yapıldı, neler sonraya kaldı. Açık kalanlar siz bitirene kadar Genel Bakış\'ta da görünür.') }}
                @endswitch
            </p>
        </div>

        <form wire:submit="save" class="px-5 py-2 text-[13.5px]">
            @switch($step)
                @case('sunucu')
                    <div class="divide-y divide-line">
                        <div class="{{ $row }}">
                            <label for="hostname" class="sm:pt-2">{{ __('Sunucu adı') }}</label>
                            <div>
                                <input id="hostname" wire:model="hostname" spellcheck="false" autocomplete="off" placeholder="srv1.ornek.com.tr" class="{{ $field }} font-mono sm:w-80 {{ $error('hostname') }}">
                                @error('hostname')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ __('Noktalı tam ad (FQDN). Posta sunucusu kendini bu adla tanıtır; sağlayıcınızın panelinde IP\'nin ters DNS (PTR) kaydını da bu ada yönlendirin.') }}</p>@enderror
                            </div>
                        </div>
                        <div class="{{ $row }}">
                            <label for="ipv4" class="sm:pt-2">{{ __('IPv4 adresi') }}</label>
                            <div>
                                <input id="ipv4" wire:model="ipv4" list="ipv4-found" spellcheck="false" autocomplete="off" class="{{ $field }} font-mono sm:w-80 {{ $error('ipv4') }}">
                                <datalist id="ipv4-found">@foreach ($detected['ipv4'] as $ip)<option value="{{ $ip }}">@endforeach</datalist>
                                @error('ipv4')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ $detected['ipv4'] ? __('Sunucuda bulunanlar: :list. Sunucu NAT arkasındaysa dışarıdan görünen adresi yazın.', ['list' => implode(', ', $detected['ipv4'])]) : __('Sitelerin ve ad sunucularının A kayıtlarına yazılır.') }}</p>@enderror
                            </div>
                        </div>
                        <div class="{{ $row }}">
                            <label for="ipv6" class="sm:pt-2">{{ __('IPv6 adresi') }}</label>
                            <div>
                                <input id="ipv6" wire:model="ipv6" list="ipv6-found" spellcheck="false" autocomplete="off" placeholder="{{ __('isteğe bağlı') }}" class="{{ $field }} font-mono sm:w-80 {{ $error('ipv6') }}">
                                <datalist id="ipv6-found">@foreach ($detected['ipv6'] as $ip)<option value="{{ $ip }}">@endforeach</datalist>
                                @error('ipv6')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ __('Boş bırakılırsa AAAA kaydı yazılmaz.') }}</p>@enderror
                            </div>
                        </div>
                        <div class="{{ $row }}">
                            <label for="timezone" class="sm:pt-2">{{ __('Saat dilimi') }}</label>
                            <div>
                                <select id="timezone" wire:model="timezone" class="{{ $field }} sm:w-80 {{ $error('timezone') }}">
                                    @foreach ($timezones as $zone)
                                        <option value="{{ $zone }}">{{ str_replace('_', ' ', $zone) }}</option>
                                    @endforeach
                                </select>
                                @error('timezone')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ __('Zamanlanmış görevler, yedekler ve günlükler bu saate göre çalışır.') }}</p>@enderror
                            </div>
                        </div>
                        <div class="{{ $row }}">
                            <label for="locale" class="sm:pt-2">{{ __('Sunucunun dili') }}</label>
                            <div>
                                <select id="locale" wire:model="locale" class="{{ $field }} sm:w-80 border-line">
                                    @foreach (\App\Support\Locales::all() as $code => $name)
                                        <option value="{{ $code }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1.5 text-2xs text-muted">{{ __('Kendi dilini seçmemiş yöneticiler ve müşteriler, e-postalar ve giriş sayfası bu dili kullanır.') }}</p>
                            </div>
                        </div>
                    </div>
                    @break

                @case('ad-sunuculari')
                    <div class="divide-y divide-line">
                        <div class="{{ $row }}">
                            <label for="domain" class="sm:pt-2">{{ __('Kendi alan adınız') }}</label>
                            <div>
                                <input id="domain" wire:model.live.debounce.400ms="domain" spellcheck="false" autocomplete="off" placeholder="ornek.com.tr" class="{{ $field }} font-mono sm:w-80 {{ $error('domain') }}">
                                @error('domain')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ __('Ad sunucularınızın adı bu alan adının altında olur.') }}</p>@enderror
                            </div>
                        </div>
                        <div class="{{ $row }}">
                            <label for="ns1" class="sm:pt-2">{{ __('1. ad sunucusu') }}</label>
                            <div>
                                <input id="ns1" wire:model.live.debounce.400ms="ns1" spellcheck="false" autocomplete="off" placeholder="ns1.ornek.com.tr" class="{{ $field }} font-mono sm:w-80 {{ $error('ns1') }}">
                                @error('ns1')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <div class="{{ $row }}">
                            <label for="ns2" class="sm:pt-2">{{ __('2. ad sunucusu') }}</label>
                            <div>
                                <input id="ns2" wire:model.live.debounce.400ms="ns2" spellcheck="false" autocomplete="off" placeholder="ns2.ornek.com.tr" class="{{ $field }} font-mono sm:w-80 {{ $error('ns2') }}">
                                @error('ns2')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@enderror
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <label for="ns2-ip" class="text-[13px] text-muted">{{ __('IP adresi') }}</label>
                                    <input id="ns2-ip" wire:model.live.debounce.400ms="ns2Ip" spellcheck="false" autocomplete="off" placeholder="{{ $ipv4 }}" class="{{ $field }} font-mono sm:w-48 {{ $error('ns2Ip') }}">
                                </div>
                                @error('ns2Ip')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ __('Sunucunun ikinci bir IP adresi varsa yazın. Yoksa iki ad da aynı adresi gösterir; çoğu alan adı firması bunu kabul eder.') }}</p>@enderror
                            </div>
                        </div>
                        <div class="{{ $row }}">
                            <span>{{ __('Bu sunucuda') }}</span>
                            <div class="space-y-2">
                                <label class="flex items-start gap-2">
                                    <input type="checkbox" wire:model="installDns" class="mt-0.5 size-4 accent-accent" @disabled(($dnsServer['state'] ?? null) === 'ready')>
                                    <span>
                                        {{ __('DNS sunucusunu kur (PowerDNS)') }}
                                        <span class="block text-2xs text-muted">{{ ($dnsServer['state'] ?? null) === 'ready' ? __('Kurulu ve çalışıyor.') : (($dnsServer['state'] ?? null) === 'installing' ? __('Kuruluyor…') : __('Arka planda birkaç dakika sürer; 53 numaralı port açılır.')) }}</span>
                                    </span>
                                </label>
                                <label class="flex items-start gap-2">
                                    <input type="checkbox" wire:model="createZone" class="mt-0.5 size-4 accent-accent">
                                    <span>
                                        <x-t text=":domain için DNS bölgesi oluştur"><x-slot:domain><span class="font-mono">{{ $domain ?: 'ornek.com.tr' }}</span></x-slot:domain></x-t>
                                        <span class="block text-2xs text-muted">{{ __('Ad sunucularının ve sunucunun A kayıtları içine yazılır.') }}</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="my-3 rounded-lg border border-line bg-info-soft px-4 py-3 text-[13px]">
                        <p class="flex items-center gap-2 font-medium"><x-icon name="info" class="size-4 text-info" />{{ __('Alan adı firmanızda yapmanız gereken') }}</p>
                        <p class="mt-1 text-muted"><x-t text="Ad sunucuları kendi alan adınızın altında olduğu için :domain alan adını aldığınız firmanın panelinde bu iki adı, IP adresleriyle birlikte “kendi ad sunucum” (glue / host kaydı) olarak kaydedin. Sonra alan adının ad sunucularını bunlar yapın."><x-slot:domain><span class="font-mono text-fg">{{ $domain ?: 'ornek.com.tr' }}</span></x-slot:domain></x-t></p>
                        <table class="mt-2 font-mono text-[13px]">
                            @foreach ($glue as [$host, $ip])
                                <tr><td class="pr-6">{{ $host ?: '—' }}</td><td>{{ $ip ?: '—' }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                    @break

                @case('panel')
                    <div class="divide-y divide-line">
                        <div class="{{ $row }}">
                            <span class="sm:pt-0.5">{{ __('Paneli açacağınız adres') }}</span>
                            <div class="space-y-3">
                                <label class="flex items-start gap-2">
                                    <input type="radio" wire:model.live="panelMode" value="hostname" class="mt-0.5 size-4 accent-accent">
                                    <span>{{ __('Sunucunun adı') }}<span class="block font-mono text-[13px] text-muted">https://{{ $serverName ?: 'srv1.ornek.com.tr' }}:{{ $port }}</span></span>
                                </label>
                                <label class="flex items-start gap-2">
                                    <input type="radio" wire:model.live="panelMode" value="custom" class="mt-0.5 size-4 accent-accent">
                                    <span class="flex-1">{{ __('Ayrı bir ad') }}
                                        @if ($panelMode === 'custom')
                                            <input id="panel-hostname" wire:model="panelHostname" spellcheck="false" autocomplete="off" placeholder="panel.ornek.com.tr" class="{{ $field }} mt-1.5 block font-mono sm:w-80 {{ $error('panelHostname') }}">
                                        @endif
                                    </span>
                                </label>
                                <label class="flex items-start gap-2">
                                    <input type="radio" wire:model.live="panelMode" value="ip" class="mt-0.5 size-4 accent-accent">
                                    <span>{{ __('Yalnızca IP adresi ve port') }}<span class="block font-mono text-[13px] text-muted">https://{{ $panelIp ?: '203.0.113.10' }}:{{ $port }}</span></span>
                                </label>
                                @error('panelHostname')<p class="text-[13px] text-crit">{{ $message }}</p>@else<p class="text-2xs text-muted">{{ __('Panelin adı bir sitenin ya da posta sunucusunun adı olamaz; posta sunucusu mail.ornek.com.tr gibi ayrı bir ad kullanır. Alan adınızın DNS bölgesi bu sunucudaysa A kaydı kendiliğinden eklenir. IP adresiyle açılan panel her zaman çalışır.') }}</p>@enderror
                            </div>
                        </div>
                        @if ($panelMode !== 'ip')
                            <div class="{{ $row }}">
                                <span>{{ __('Sertifika') }}</span>
                                <label class="flex items-start gap-2">
                                    <input type="checkbox" wire:model="issueCertificate" class="mt-0.5 size-4 accent-accent">
                                    <span>
                                        {{ __('Let\'s Encrypt sertifikası al') }}
                                        <span class="block text-2xs text-muted">{{ __('Ad henüz bu sunucuyu göstermiyorsa her saat yeniden denenir. O zamana kadar panel kendinden imzalı sertifikayla açılır; tarayıcının uyarısı bundandır.') }}</span>
                                    </span>
                                </label>
                            </div>
                        @else
                            <div class="{{ $row }}">
                                <span>{{ __('Sertifika') }}</span>
                                <p class="text-[13px] text-muted">{{ __('IP adresine Let\'s Encrypt sertifikası verilmez; panel kendinden imzalı sertifikayla açılır ve tarayıcı uyarı gösterir. Sonra bir ad vermek için Araçlar ve Ayarlar → Genel.') }}</p>
                            </div>
                        @endif
                        <div class="{{ $row }}">
                            <span>{{ __('Panel portu') }}</span>
                            <div>
                                <span class="num font-mono text-[13px]">{{ $port }}</span>
                                <p class="mt-1.5 text-2xs text-muted">{{ __('Değiştirmek isterseniz Araçlar ve Ayarlar → Genel\'den değiştirin; eski port, yeni porttan onaylayana kadar açık kalır.') }}</p>
                            </div>
                        </div>
                    </div>
                    @break

                @case('bildirimler')
                    <div class="divide-y divide-line">
                        <div class="{{ $row }}">
                            <label for="emails" class="sm:pt-2">{{ __('E-posta adresleri') }}</label>
                            <div>
                                <input id="emails" wire:model="emails" spellcheck="false" autocomplete="off" class="{{ $field }} {{ $error('emails') }}">
                                @error('emails')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ __('Virgülle ayırın; en fazla 10 adres. Webhook ve hangi olayların bildirileceği Araçlar ve Ayarlar → Bildirimler\'de.') }}</p>@enderror
                            </div>
                        </div>
                    </div>
                    @if ($mailProblem)
                        <div class="my-3 rounded-lg border border-line bg-warn-soft px-4 py-3 text-[13px]">
                            <p class="font-medium">{{ __('E-postalar şu an gönderilemez') }}</p>
                            <p class="mt-1 text-muted">{{ $mailProblem }} <a href="{{ route('settings.notifications') }}" class="text-accent hover:underline">{{ __('Giden posta ayarları') }}</a></p>
                        </div>
                    @endif
                    @break

                @case('guvenlik')
                    <div class="py-3">
                        @if ($firewallOn)
                            <p class="flex items-center gap-2"><x-icon name="circle-check" class="size-4 text-ok" />{{ __('Güvenlik duvarı açık.') }}</p>
                        @else
                            <p>{{ __('Açtığınızda yalnızca şu portlar açık kalır:') }}</p>
                        @endif
                        <table class="mt-3 w-full text-[13px]">
                            @foreach ($firewallServices as $s)
                                <tr class="border-t border-line">
                                    <td class="py-2 pr-4">{{ $s['name'] }}</td>
                                    <td class="num py-2 pr-4 font-mono">{{ $s['ports'] }}</td>
                                    <td class="py-2 text-muted">{{ $s['help'] }}</td>
                                </tr>
                            @endforeach
                        </table>
                        <p class="mt-3 text-2xs text-muted">{{ __('SSH portu sunucudan okunur; farklı bir port kullanıyorsanız o da açık kalır. Kural eklemek ve E-posta, DNS gibi hizmetleri açıp kapatmak Güvenlik → Güvenlik duvarı\'nda.') }}</p>
                        @error('firewall')<p class="mt-2 text-[13px] text-crit">{{ $message }}</p>@enderror
                    </div>
                    @break

                @case('varsayilanlar')
                    <div class="divide-y divide-line">
                        <div class="{{ $row }}">
                            <label for="web-stack" class="sm:pt-2">{{ __('Web sunucusu') }}</label>
                            <div>
                                <select id="web-stack" wire:model="webStack" class="{{ $field }} sm:w-80 {{ $error('webStack') }}">
                                    <option value="{{ \App\Models\Site::NGINX }}">Nginx</option>
                                    <option value="{{ \App\Models\Site::APACHE }}" @disabled(! $apacheInstalled)>{{ $apacheInstalled ? __('Apache (Nginx arkasında)') : __('Apache (kurulu değil)') }}</option>
                                </select>
                                @error('webStack')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ __('.htaccess isteyen siteler için Apache\'yi Yazılım Yönetimi\'nden kurabilirsiniz. Her site için ayrıca seçilebilir.') }}</p>@enderror
                            </div>
                        </div>
                        <div class="{{ $row }}">
                            <label for="php-version" class="sm:pt-2">{{ __('PHP sürümü') }}</label>
                            <div>
                                <select id="php-version" wire:model="phpVersion" @disabled(! $phpVersions) class="{{ $field }} sm:w-80 {{ $error('phpVersion') }}">
                                    @forelse ($phpVersions as $v)
                                        <option value="{{ $v }}">PHP {{ $v }}</option>
                                    @empty
                                        <option value="">{{ __('Kurulu PHP sürümü yok') }}</option>
                                    @endforelse
                                </select>
                                @error('phpVersion')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1.5 text-2xs text-muted">{{ __('Başka sürümler Hizmetler → PHP sürümleri\'nden kurulur.') }}</p>@enderror
                            </div>
                        </div>
                        <div class="{{ $row }}">
                            <span class="sm:pt-0.5">{{ __('Servik güncellemeleri') }}</span>
                            <div class="space-y-2">
                                @foreach (\App\Services\Updates\Updates::CHANNELS as $value => $name)
                                    <label class="flex items-start gap-2">
                                        <input type="radio" wire:model="channel" value="{{ $value }}" class="mt-0.5 size-4 accent-accent">
                                        <span>{{ __($name) }}<span class="block text-2xs text-muted">{{ $value === 'stable' ? __('Yalnızca kararlı sürümler. Önerilir.') : __('Ön sürümler de önerilir; deneme sunucuları için.') }}</span></span>
                                    </label>
                                @endforeach
                                <p class="text-2xs text-muted">{{ __('Her gün 04:30\'da yeni sürüm denetlenir; hiçbir güncelleme siz başlatmadan kurulmaz.') }}</p>
                            </div>
                        </div>
                    </div>
                    @break

                @case('yedekler')
                    <div class="divide-y divide-line">
                        <div class="{{ $row }}">
                            <span>{{ __('Günlük sunucu yedeği') }}</span>
                            <label class="flex items-start gap-2">
                                <input type="checkbox" wire:model="backups" class="mt-0.5 size-4 accent-accent">
                                <span>{{ __('Açık') }}<span class="block text-2xs text-muted">{{ __('Pazar günleri tam, diğer günler artımlı yedek; son 7 gün, 4 hafta ve 3 ay saklanır.') }}</span></span>
                            </label>
                        </div>
                        <div class="{{ $row }}">
                            <label for="backup-time" class="sm:pt-2">{{ __('Saat') }}</label>
                            <div>
                                <input id="backup-time" type="time" wire:model="backupTime" class="{{ $field }} num sm:w-32 {{ $error('backupTime') }}">
                                @error('backupTime')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <div class="{{ $row }}">
                            <span>{{ __('Nereye') }}</span>
                            <div>
                                <span>{{ $backupTarget ? $backupTarget->name : __('Bu sunucunun diski') }}</span>
                                @unless ($backupTarget)
                                    <p class="mt-1.5 text-2xs text-warn">{{ __('Sunucu bozulursa yedekler de gider. Bir S3 ya da SFTP hedefi eklemenizi öneririz.') }}</p>
                                @endunless
                                <a href="{{ route('backups.index', ['sekme' => 'hedefler']) }}" class="mt-1.5 inline-block text-[13px] text-accent hover:underline">{{ __('Yedek hedefi ekle') }}</a>
                            </div>
                        </div>
                    </div>
                    @break

                @default
                    <ul class="divide-y divide-line">
                        <li class="flex items-center gap-3 py-3">
                            <x-icon name="circle-check" class="size-4 text-ok" />
                            <span class="flex-1">{{ __('Yönetici hesabı') }}</span>
                            <span class="truncate text-[13px] text-muted">{{ auth()->user()->email }}</span>
                        </li>
                        @foreach ($checklist as $item)
                            @php [$icon, $color] = $mark($item['state']); @endphp
                            <li class="flex items-center gap-3 py-3">
                                <x-icon :name="$icon" class="size-4 {{ $color }}" />
                                <span class="flex-1">{{ $item['name'] }}</span>
                                <span class="truncate text-[13px] {{ $item['state'] === 'done' ? 'text-muted' : 'text-warn' }}">
                                    {{ $item['state'] === 'done' ? ($item['detail'] ?? __('Tamam')) : ($item['state'] === 'skipped' ? __('Sonraya bırakıldı') : __('Yapılmadı')) }}
                                </span>
                                @if ($item['state'] !== 'done')
                                    <button type="button" wire:click="go('{{ $item['step'] }}')" class="text-[13px] text-accent hover:underline">{{ __('Aç') }}</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if (\App\Models\Setting::get('setup.domain'))
                        <div class="my-3 rounded-lg border border-line bg-info-soft px-4 py-3 text-[13px]">
                            <p class="font-medium">{{ __('Unutmayın') }}</p>
                            <ul class="mt-1 list-disc space-y-1 pl-5 text-muted">
                                <li>{{ __('Alan adı firmanızda ad sunucularının glue (host) kayıtlarını açın:') }} <span class="font-mono text-fg">{{ collect($glue)->map(fn ($g) => $g[0].' → '.$g[1])->join(', ') }}</span></li>
                                <li>{{ __('Sunucu sağlayıcınızın panelinde IP adresinin ters DNS (PTR) kaydını sunucu adına yönlendirin; posta için gerekir.') }}</li>
                            </ul>
                        </div>
                    @endif
            @endswitch

            <div class="flex flex-wrap items-center gap-2 border-t border-line py-4">
                @if ($step === 'ozet')
                    <button type="button" wire:click="finish" class="{{ $primary }}">{{ __('Kurulumu bitir') }}</button>
                    <span class="text-2xs text-muted">{{ __('Açık kalan adımları sonra Genel Bakış\'tan ya da Araçlar ve Ayarlar → Kurulum sihirbazı\'ndan tamamlayabilirsiniz.') }}</span>
                @else
                    <button type="submit" wire:loading.attr="disabled" wire:target="save" class="{{ $primary }}">
                        {{ $step === 'guvenlik' ? ($firewallOn ? __('Devam et') : __('Güvenlik duvarını aç ve devam et')) : __('Kaydet ve devam et') }}
                    </button>
                    <button type="button" wire:click="skip" class="{{ $btn }}">{{ __('Sonra') }}</button>
                @endif
                @if ($step !== 'sunucu')
                    <button type="button" wire:click="back" class="ml-auto text-[13px] text-muted hover:text-fg">{{ __('Geri') }}</button>
                @endif
            </div>
        </form>
    </section>
</div>
