<div class="space-y-6">
    <x-site-header :site="$site" active="mail" />

    @if ($domain)
        @livewire('mail.mail-domain-show', ['domain' => $domain->id, 'siteId' => $site->id], key('mail-'.$domain->id))
    @else
        @if (auth()->user()->is_admin)
            @include('livewire.mail.server-state')
        @endif
        <section class="rounded-xl border border-line bg-surface">
            <div class="mx-auto flex max-w-xl flex-col items-center px-6 py-12 text-center">
                <span class="grid size-12 place-items-center rounded-xl bg-surface-2 text-muted"><x-icon name="mail" class="size-6" /></span>
                <h1 class="mt-4 text-[16px] font-semibold"><x-t text=":domain için e-posta kapalı"><x-slot:domain><span class="font-mono">{{ $site->domain }}</span></x-slot:domain></x-t></h1>
                <p class="mt-1.5 text-[13.5px] text-muted"><x-t text="E-postayı açınca :address gibi posta kutularını bu sayfadan oluşturursunuz. MX, SPF, DKIM ve DMARC kayıtları alan adının DNS bölgesine kendiliğinden eklenir."><x-slot:address><span class="font-mono text-fg">info{{ '@'.$site->domain }}</span></x-slot:address></x-t></p>
                <button type="button" wire:click="enable" wire:loading.attr="disabled" class="mt-5 flex h-9 items-center gap-2 rounded-md bg-accent px-4 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-60">
                    <x-icon name="mail" />{{ __('E-postayı aç') }}
                </button>
                <p class="mt-3 text-[12.5px] text-muted">{{ __('Açtıktan sonra bildirimdeki "Geri al" ile kapatabilirsiniz.') }}</p>
            </div>
        </section>
    @endif

    @if ($aliases->isNotEmpty())
        <p class="text-[13px] text-muted">
            {{ __('Ek alan adlarının e-postası:') }}
            @foreach ($aliases as $alias)
                @if (isset($aliasMail[$alias]))
                    <a href="{{ route('mail.show', $aliasMail[$alias]) }}" wire:navigate class="font-mono text-accent hover:underline">{{ $alias }}</a>{{ $loop->last ? '' : ',' }}
                @else
                    <a href="{{ route('mail.index', ['enable' => $alias]) }}" wire:navigate class="font-mono hover:text-accent">{{ $alias }}</a> <span>({{ __('kapalı') }})</span>{{ $loop->last ? '' : ',' }}
                @endif
            @endforeach
        </p>
    @endif
</div>
