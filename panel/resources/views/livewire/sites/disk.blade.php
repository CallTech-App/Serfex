@php
    use App\Services\Stats\DiskBreakdown;
    use App\Support\Bytes;
    $total = max($disk['total'], 1);
    $links = [
        'web' => route('sites.files', $site),
        'mail' => $site->mailDomain ? route('mail.show', $site->mailDomain) : null,
        'databases' => route('databases.index', ['site' => $site->id]),
        'backups' => $site->laravelApp ? route('laravel.show', [$site->laravelApp, 'bolum' => 'yedekler']) : null,
    ];
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <nav class="flex flex-wrap items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
                <a href="{{ route('sites.index') }}" wire:navigate class="hover:text-accent">{{ __('Siteler') }}</a>
                <x-icon name="chevron-right" class="size-3.5" />
                <a href="{{ route('sites.show', $site) }}" wire:navigate class="font-mono hover:text-accent">{{ $site->domain }}</a>
                <x-icon name="chevron-right" class="size-3.5" />
                <span class="text-fg">{{ __('Disk kullanımı') }}</span>
            </nav>
            <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Disk kullanımı') }}</h1>
            <p class="mt-1 text-[13px] text-muted">{{ __('Sitenin diskte kapladığı yer: dosyalar, e-posta, veritabanları ve uygulama yedekleri.') }}</p>
        </div>
        <button type="button" wire:click="measure" wire:loading.attr="disabled" wire:target="measure" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2 disabled:opacity-60">
            <x-icon name="refresh-cw" class="size-3.5" wire:loading.class="animate-spin" wire:target="measure" />{{ __('Şimdi ölç') }}
        </button>
    </div>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        @if ($agentError)
            <p class="border-b border-line px-5 py-3 text-[13px] text-crit">{{ $agentError }}</p>
        @endif
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Bölüm') }}</th>
                        <th class="px-3 py-2.5 text-right font-medium">{{ __('Boyut') }}</th>
                        <th class="w-56 px-3 py-2.5 font-medium">{{ __('Pay') }}</th>
                        <th class="py-2.5 pr-5 pl-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach (DiskBreakdown::PARTS as $key => [$label, $hint])
                        @php $bytes = $disk['parts'][$key]; @endphp
                        <tr class="hover:bg-surface-2/60">
                            <td class="py-3 pr-3 pl-5">
                                <span class="font-medium">{{ $label }}</span>
                                <span class="block text-2xs text-muted">{{ $hint }}</span>
                            </td>
                            <td class="num px-3 py-3 text-right whitespace-nowrap">{{ $bytes === null ? __('Ölçülmedi') : Bytes::format($bytes) }}</td>
                            <td class="px-3 py-3">
                                @if ($bytes !== null)
                                    <div class="flex items-center gap-2.5">
                                        <span class="h-1.5 w-32 overflow-hidden rounded-full bg-surface-2"><span class="block h-full rounded-full bg-muted" style="width: {{ $bytes > 0 ? max(1, round($bytes / $total * 100)) : 0 }}%"></span></span>
                                        <span class="num text-[12.5px] text-muted">{{ \App\Support\Num::percent(round($bytes / $total * 100)) }}</span>
                                    </div>
                                @endif
                            </td>
                            <td class="py-3 pr-5 pl-3 text-right">
                                @if ($links[$key])
                                    <a href="{{ $links[$key] }}" wire:navigate class="text-[13px] text-accent hover:underline">{{ __('Aç') }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-line">
                        <td class="py-3 pr-3 pl-5 font-semibold">{{ __('Toplam') }}</td>
                        <td class="num px-3 py-3 text-right font-semibold whitespace-nowrap">{{ Bytes::format($disk['total']) }}</td>
                        <td colspan="2" class="py-3 pr-5 pl-3 text-[13px] text-muted">
                            @if ($customer?->package?->limit('disk'))
                                {{ __('Paket diski :package; hesabın tüm siteleriyle birlikte sayılır.', ['package' => Bytes::format($customer->package->limit('disk'))]) }}
                            @endif
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="border-t border-line px-5 py-3 text-2xs text-muted">
            {{ $disk['measured_at'] ? __('Son ölçüm :measured_at; dosyalar 30 dakikada bir ölçülür.', ['measured_at' => \App\Support\When::dateTime($disk['measured_at'])]) : __('Dosyalar henüz ölçülmedi; 30 dakikada bir ölçülür.') }}
            @if ($disk['server_backups'] > 0)
                {{ __('Sunucu yedeklerindeki kopyaları :server_backups; bunlar kotaya sayılmaz.', ['server_backups' => Bytes::format($disk['server_backups'])]) }}
            @endif
        </p>
    </section>
</div>
