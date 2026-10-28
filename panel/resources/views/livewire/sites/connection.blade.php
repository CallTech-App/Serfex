@php
    use App\Services\Files\SshAccess;
    $link = 'inline-flex items-center gap-1 text-[13px] text-accent hover:underline';
    $copy = fn (string $value) => '<button type="button" x-data="{ done: false }" @click="navigator.clipboard.writeText('.e(json_encode($value)).'); done = true; setTimeout(() => done = false, 1500)" class="shrink-0 text-[12.5px] text-accent hover:underline"><span x-show="!done">'.e(__('Kopyala')).'</span><span x-show="done" x-cloak>'.e(__('Kopyalandı')).'</span></button>';
    $sftpRows = [__('Protokol') => 'SFTP', __('Sunucu') => $site->domain, __('Port') => (string) $port];
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Bağlantı bilgileri') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Bağlantı bilgileri') }}</h1>
        <p class="mt-1 max-w-3xl text-[13px] text-muted">{{ __('Dosya aktarma programına, uygulamanızın ayarlarına ya da terminale yazacaklarınız. Serfex parolaları saklamaz; unutulan parolanın yenisi ilgili sayfadan belirlenir.') }}</p>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <div class="divide-y divide-line px-5 text-[13.5px]">
            {{-- Sunucu --}}
            <div class="grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6">
                <div>
                    <p class="font-medium">{{ __('Sunucu') }}</p>
                    <p class="mt-0.5 text-2xs text-muted">{{ __('Alan adı bu sunucuya işaret etmiyorsa IP adresini kullanın') }}</p>
                </div>
                <dl class="min-w-0 divide-y divide-line rounded-lg border border-line">
                    @foreach (array_filter([__('Alan adı') => $site->domain, __('IP adresi') => $ip, __('Sistem kullanıcısı') => $account->name]) as $label => $value)
                        <div class="flex items-center gap-3 px-3 py-2">
                            <dt class="w-28 shrink-0 text-muted sm:w-36">{{ $label }}</dt>
                            <dd class="min-w-0 flex-1 font-mono text-[13px] break-all sm:truncate" title="{{ $value }}">{{ $value }}</dd>
                            {!! $copy($value) !!}
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- SFTP --}}
            <div class="grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6">
                <div>
                    <p class="font-medium">{{ __('Dosya aktarımı (SFTP)') }}</p>
                    <p class="mt-0.5 text-2xs text-muted">{{ __('FileZilla, WinSCP ya da Cyberduck. Klasik FTP (şifresiz) desteklenmez.') }}</p>
                </div>
                <div class="min-w-0 space-y-3">
                    <dl class="min-w-0 divide-y divide-line rounded-lg border border-line">
                        @foreach ($sftpRows as $label => $value)
                            <div class="flex items-center gap-3 px-3 py-2">
                                <dt class="w-28 shrink-0 text-muted sm:w-36">{{ $label }}</dt>
                                <dd class="min-w-0 flex-1 font-mono text-[13px] break-all sm:truncate">{{ $value }}</dd>
                                {!! $copy($value) !!}
                            </div>
                        @endforeach
                    </dl>
                    @if ($ftpAccounts->isEmpty())
                        <p class="text-muted">{{ __('Henüz FTP hesabı yok.') }}</p>
                    @else
                        <ul class="divide-y divide-line rounded-lg border border-line">
                            @foreach ($ftpAccounts as $ftpAccount)
                                <li wire:key="ftp-{{ $ftpAccount->id }}" class="flex items-center gap-3 px-3 py-2">
                                    <x-icon name="user" class="shrink-0 text-muted" />
                                    <span class="min-w-0 flex-1 font-mono text-[13px] break-all sm:truncate">{{ $ftpAccount->name }}</span>
                                    <span class="hidden min-w-0 truncate font-mono text-[12.5px] text-muted sm:block" title="{{ __('Bağlanınca açılan klasör') }}">{{ $ftpAccount->remoteFolder() }}</span>
                                    {!! $copy($ftpAccount->name) !!}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <a href="{{ route('sites.ftp', $site) }}" wire:navigate class="{{ $link }}">{{ $ftpAccounts->isEmpty() ? __('FTP hesabı ekle') : __('FTP hesapları ve parolalar') }}<x-icon name="chevron-right" class="size-3.5" /></a>
                </div>
            </div>

            {{-- Veritabanları --}}
            <div class="grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6">
                <div>
                    <p class="font-medium">{{ __('Veritabanları') }}</p>
                    <p class="mt-0.5 text-2xs text-muted">{{ __('Sitenin kodu aynı sunucuda çalışır, bu yüzden adres 127.0.0.1 olur') }}</p>
                </div>
                <div class="min-w-0 space-y-3">
                    @if ($databases->isEmpty())
                        <p class="text-muted">{{ __('Bu sitenin veritabanı yok.') }}</p>
                    @else
                        <ul class="divide-y divide-line rounded-lg border border-line">
                            @foreach ($databases as $database)
                                @php $conn = $database->connection('', ''); @endphp
                                <li wire:key="db-{{ $database->id }}" class="px-3 py-2.5">
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                        <span class="min-w-0 truncate font-mono text-[13px] font-medium">{{ $database->name }}</span>
                                        <span class="rounded bg-surface-2 px-1.5 py-0.5 text-2xs text-muted">{{ $serverLabels[$database->server] ?? $database->serverLabel() }}</span>
                                        <span class="font-mono text-[12.5px] text-muted">{{ $conn['host'] }}:{{ $conn['port'] }}</span>
                                        <a href="{{ route('databases.manager', $database) }}" target="_blank" rel="noopener" class="ml-auto {{ $link }}">{{ $database->managerLabel() }}<x-icon name="external-link" class="size-3.5" /></a>
                                    </div>
                                    <p class="mt-1 text-[12.5px] text-muted">
                                        {{ __('Kullanıcılar') }}:
                                        @forelse ($database->users as $dbUser)
                                            <span class="font-mono text-fg">{{ $dbUser->name }}</span>@if (! $loop->last), @endif
                                        @empty
                                            {{ __('Yok') }}
                                        @endforelse
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <a href="{{ route('databases.index', ['site' => $site->id]) }}" wire:navigate class="{{ $link }}">{{ $databases->isEmpty() ? __('Veritabanı ekle') : __('Veritabanları ve parolalar') }}<x-icon name="chevron-right" class="size-3.5" /></a>
                </div>
            </div>

            {{-- SSH --}}
            <div class="grid items-start gap-2 py-4 sm:grid-cols-[220px_1fr] sm:gap-6">
                <div>
                    <p class="font-medium">SSH</p>
                    <p class="mt-0.5 text-2xs text-muted"><x-t text="Sistem kullanıcısı :user ile, anahtar ya da parolayla"><x-slot:user><span class="font-mono">{{ $account->name }}</span></x-slot:user></x-t></p>
                </div>
                <div class="min-w-0 space-y-3">
                    @if ($ssh === null)
                        <p class="text-muted">{{ __('Paketinizde yok') }}</p>
                    @elseif ($sshAccess === SshAccess::OFF)
                        <p class="text-muted">{{ __('SSH erişimi kapalı.') }}</p>
                        <a href="{{ route('sites.ssh', $site) }}" wire:navigate class="{{ $link }}">{{ __('SSH erişimini aç') }}<x-icon name="chevron-right" class="size-3.5" /></a>
                    @else
                        <dl class="min-w-0 divide-y divide-line rounded-lg border border-line">
                            @foreach ([__('Erişim') => __(SshAccess::LABELS[$sshAccess]), __('Kullanıcı') => $ssh['user'], 'SFTP' => $ssh['sftp']] + ($ssh['ssh'] ? ['SSH' => $ssh['ssh']] : []) as $label => $value)
                                <div class="flex items-center gap-3 px-3 py-2">
                                    <dt class="w-28 shrink-0 text-muted sm:w-36">{{ $label }}</dt>
                                    <dd @class(['min-w-0 flex-1 break-all sm:truncate', 'font-mono text-[13px]' => $label !== __('Erişim')]) title="{{ $value }}">{{ $value }}</dd>
                                    @if ($label !== __('Erişim'))
                                        {!! $copy($value) !!}
                                    @endif
                                </div>
                            @endforeach
                        </dl>
                        <a href="{{ route('sites.ssh', $site) }}" wire:navigate class="{{ $link }}">{{ __('SSH anahtarları ve parola') }}<x-icon name="chevron-right" class="size-3.5" /></a>
                    @endif
                </div>
            </div>
        </div>
    </section>
</div>
