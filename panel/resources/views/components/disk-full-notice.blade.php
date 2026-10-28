@props(['customer', 'admin' => true])
{{-- Shown while the customer's disk is full and limits are enforced (DiskQuotas). --}}
@if ($customer->disk_full_at !== null && $customer->suspended_at === null)
    <div {{ $attributes->class('relative flex flex-wrap items-center gap-3 overflow-hidden rounded-xl border border-line bg-surface py-3 pr-4 pl-5 text-[13.5px]') }} role="alert">
        <span class="absolute inset-y-0 left-0 w-1 bg-crit"></span>
        <x-icon name="hard-drive" class="text-crit" />
        <div class="min-w-0 flex-1 basis-64">
            <p class="font-medium">{{ $admin ? __('Bu müşterinin disk kotası :at tarihinde doldu', ['at' => \App\Support\When::dateTime($customer->disk_full_at)]) : __('Disk alanınız :at tarihinde doldu', ['at' => \App\Support\When::dateTime($customer->disk_full_at)]) }}</p>
            <p class="mt-0.5 text-[13px] text-muted">
                {{ __('Dosyalara yazılamıyor: dosya yöneticisiyle ya da FTP ile yükleme ve sitelerin dosya yazması reddediliyor. Gelen e-postalar bekletiliyor; gönderen sunucular birkaç gün yeniden dener, yer açılınca teslim edilir.') }}
                {{ $admin ? __('Müşteri yer açınca ya da disk sınırı yükseltilince en geç 5 dakikada düzelir.') : __('Yer açın ya da paketinizi yükseltmek için barındırma sağlayıcınıza yazın; en geç 5 dakikada düzelir.') }}
            </p>
        </div>
        {{ $slot }}
    </div>
@endif
