{{--
    Shown when a Livewire request does not reach the panel (the network
    dropped, or the browser refused the connection, for example over a
    certificate), instead of a button that waits forever. It goes away by
    itself once a request gets through again.
--}}
<div
    x-data="{ lost: false }"
    @servik-connection.window="lost = ! $event.detail.ok"
    x-show="lost"
    x-cloak
    role="alert"
    class="fixed inset-x-0 top-0 z-[70] flex flex-wrap items-center justify-center gap-x-4 gap-y-1 border-b border-crit/30 bg-crit-soft px-4 py-2.5 text-[13.5px]"
>
    <span class="flex items-center gap-2"><x-icon name="triangle-alert" class="size-4 shrink-0 text-crit" />{{ __('Panele ulaşılamadı; son işlem gönderilmemiş olabilir. Sayfayı yenileyip yeniden deneyin.') }}</span>
    <button type="button" @click="location.reload()" class="font-medium text-accent hover:underline">{{ __('Sayfayı yenile') }}</button>
</div>
