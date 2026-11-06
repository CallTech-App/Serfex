{{-- A staging copy's WordPress: e-mail and WP-Cron. $live saves on change (a copy), otherwise it is part of the create form. --}}
<div class="{{ $row }}">
    <div>
        <p class="font-medium">WordPress</p>
        <p class="mt-0.5 text-2xs text-muted">{{ __('Kopya canlı sitenin müşterilerine ulaşmasın') }}</p>
    </div>
    <div class="space-y-2.5">
        <label class="flex items-start gap-2.5">
            <input type="checkbox" wire:model="blockMail" @if ($live) wire:change="saveStaging" @endif class="mt-0.5 size-4 accent-accent">
            <span>{{ __('E-posta gönderilmesin') }}
                <span class="block text-[12.5px] text-muted">{{ __('Sipariş, parola sıfırlama ve bülten e-postaları kopyadan gitmez; WordPress gönderilmiş sayar.') }}</span>
            </span>
        </label>
        <label class="flex items-start gap-2.5">
            <input type="checkbox" wire:model="blockCron" @if ($live) wire:change="saveStaging" @endif class="mt-0.5 size-4 accent-accent">
            <span>{{ __('Zamanlanmış görevler (WP-Cron) çalışmasın') }}
                <span class="block text-[12.5px] text-muted">{{ __('Abonelik ödemeleri, içe aktarmalar ve yedekleme eklentileri kopyada çalışmaz.') }}</span>
            </span>
        </label>
        <p class="text-2xs text-muted">{{ __('Serfex kopyaya küçük bir eklenti (serfex-staging.php) koyar; canlı siteye aktarırken kaldırır.') }}</p>
    </div>
</div>
