@php
    use App\Services\Laravel\CommandLine;
    use App\Services\Laravel\LaravelApps;
    use App\Support\Badge;
    $prefix = $tool === 'artisan' ? 'php artisan' : ($tool === 'composer' ? 'composer' : $packageManager);
    $operate = auth()->user()->canOnApp($app, 'operate');
    $state = fn ($c) => match ($c->status) {
        'queued' => new Badge('Sırada', 'info', true),
        'running' => $c->tool === 'tinker' ? new Badge('Oturum açık', 'info') : new Badge('Çalışıyor', 'info', true),
        'succeeded' => new Badge('Bitti', 'ok'),
        'finished' => new Badge('Kapandı', 'muted'),
        default => new Badge($c->exit_code !== null ? 'Çıkış kodu '.$c->exit_code : 'Başarısız', 'crit'),
    };
@endphp
<div>
    @if ($busy)
        <div wire:poll.3s hidden></div>
    @endif
    <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
        <div class="inline-flex rounded-md border border-line p-0.5 text-[13px]" role="tablist" aria-label="Araç">
            @foreach (['artisan' => 'Artisan', 'composer' => 'Composer', 'node' => 'Node.js', 'tinker' => 'Tinker'] as $key => $label)
                <button type="button" role="tab" wire:click="$set('tool', '{{ $key }}')" aria-selected="{{ $tool === $key ? 'true' : 'false' }}"
                        class="h-7 rounded px-2.5 {{ $tool === $key ? 'bg-accent-soft font-medium text-accent' : 'text-muted hover:text-fg' }}">{{ $label }}</button>
            @endforeach
        </div>
        @if ($tool === 'node')
            <select wire:model.live="packageManager" aria-label="Paket yöneticisi" class="h-8 rounded-md border border-line bg-surface px-2 font-mono text-[13px]">
                @foreach (LaravelApps::PACKAGE_MANAGERS as $pm)
                    <option>{{ $pm }}</option>
                @endforeach
            </select>
        @endif
    </div>

    @if ($tool === 'tinker')
        <div class="space-y-3 p-5 text-[13.5px]">
            @if ($session)
                <div class="flex flex-wrap items-center gap-2 text-[13px]">
                    <x-status-badge :status="$state($session)" />
                    <span class="text-muted">Değişkenler ve tanımlar girdiler arasında korunur.</span>
                    <button type="button" wire:click="stopTinker" class="ml-auto text-muted hover:text-crit">Oturumu kapat</button>
                </div>
                <x-run-log :run="$session->run" class="h-72" />
                <form wire:submit="send" x-data @keydown.ctrl.enter.prevent="$wire.send()" @keydown.meta.enter.prevent="$wire.send()">
                    <div class="flex overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('code') ? 'border-crit' : 'border-line' }}">
                        <span class="grid place-items-start border-r border-line bg-surface-2 px-3 pt-2 font-mono text-[13px] text-muted">&gt;&gt;&gt;</span>
                        <textarea wire:model="code" rows="3" aria-label="PHP kodu" spellcheck="false" placeholder="App\Models\User::count()" class="min-w-0 flex-1 resize-y bg-surface px-3 py-2 font-mono text-[13px] outline-none"></textarea>
                        <button type="submit" wire:loading.attr="disabled" wire:target="send" class="bg-accent px-3.5 text-[13px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-60">Gönder</button>
                    </div>
                    @error('code')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@else<p class="mt-1 text-2xs text-muted">Ctrl + Enter ile gönderin.</p>@enderror
                </form>
            @else
                <div class="rounded-md border border-line px-4 py-5">
                    <p class="font-medium">Tinker oturumu</p>
                    <p class="mt-1 text-[13px] text-muted">Uygulamanın içinde PHP çalıştırın: modelleri sorgulayın, yapılandırmaya bakın, bir işi elle tetikleyin. Yazdıklarınız gerçek veritabanında çalışır.</p>
                    @if ($operate)
                        <button type="button" wire:click="startTinker" wire:loading.attr="disabled" class="mt-3 flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-60"><x-icon name="terminal" />Oturum başlat</button>
                    @endif
                </div>
                @if ($shown?->tool === 'tinker')
                    <div class="flex flex-wrap items-center gap-2 text-[13px]"><span class="font-mono">tinker</span><x-status-badge :status="$state($shown)" />@if ($shown->error)<span class="text-2xs text-crit">{{ $shown->error }}</span>@endif</div>
                    <x-run-log :run="$shown->run" class="h-72" />
                @endif
            @endif
            <p class="text-2xs text-muted">Oturum sitenin kullanıcısıyla (<span class="font-mono">{{ $app->site->systemUser->name }}</span>) <span class="font-mono">php artisan tinker</span> olarak çalışır; 10 dakika girdi gelmezse ya da 60 dakika dolunca kapanır. Uygulama başına en çok 5 oturum açılabilir.</p>
        </div>
    @else
    <form wire:submit="run" class="space-y-3 p-5 text-[13.5px]">
        <div class="flex overflow-hidden rounded-md border focus-within:border-accent {{ $errors->has('command') ? 'border-crit' : 'border-line' }}">
            <span class="grid place-items-center border-r border-line bg-surface-2 px-3 font-mono text-[13px] whitespace-nowrap text-muted">{{ $prefix }}</span>
            <input wire:model="command" aria-label="Komut" autocomplete="off" spellcheck="false" placeholder="{{ $tool === 'artisan' ? 'migrate --force' : ($tool === 'composer' ? 'require vendor/paket' : 'run build') }}"
                   class="h-9 min-w-0 flex-1 bg-surface px-3 font-mono text-[13px] outline-none">
            <button type="submit" wire:loading.attr="disabled" wire:target="run" class="bg-accent px-3.5 text-[13px] font-medium text-accent-fg hover:brightness-110 disabled:opacity-60">Çalıştır</button>
        </div>
        @error('command')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
        <div class="flex flex-wrap items-center gap-1.5">
            <span class="mr-1 text-2xs text-muted">Kayıtlı:</span>
            @foreach ($saved as $s)
                <span wire:key="saved-{{ md5($s) }}" class="inline-flex h-7 items-center rounded-md border border-line font-mono text-[12px]">
                    <button type="button" wire:click="pick(@js($s))" class="h-full px-2 hover:bg-surface-2">{{ $s }}</button>
                    <button type="button" wire:click="forget(@js($s))" aria-label="Kayıtlı komutu sil" class="h-full border-l border-line px-1.5 text-muted hover:text-crit">×</button>
                </span>
            @endforeach
            <button type="button" wire:click="saveCommand" class="h-7 rounded-md px-2 text-[12px] text-accent hover:underline">+ Bu komutu kaydet</button>
        </div>

        @if ($shown)
            <div class="flex flex-wrap items-center gap-2 text-[13px]">
                <span class="font-mono">{{ CommandLine::prefix($shown->tool) }} {{ $shown->command }}</span>
                <x-status-badge :status="$state($shown)" />
                @if ($shown->error)<span class="text-2xs text-crit">{{ $shown->error }}</span>@endif
            </div>
            <x-run-log :run="$shown->run" class="h-72" />
        @else
            <pre class="h-72 rounded-md bg-[#0f1a1c] p-3 font-mono text-[12.5px] text-[#7f9a9a]">Çıktı burada görünür.</pre>
        @endif
        <p class="text-2xs text-muted">Komutlar sitenin kullanıcısıyla (<span class="font-mono">{{ $app->site->systemUser->name }}</span>) <span class="font-mono">{{ $app->path }}</span> dizininde, sitenin PHP sürümüyle çalışır. Çıktı canlı akar; sayfadan çıksanız da komut sürer (en çok 30 dakika). Etkileşimli PHP için Tinker sekmesini kullanın.</p>
    </form>

    @endif

    @if ($recent->isNotEmpty())
        <div class="border-t border-line">
            <p class="px-5 pt-3 text-2xs font-medium tracking-[.06em] text-muted uppercase">Son komutlar</p>
            <ul class="divide-y divide-line px-5 pb-2 text-[13px]">
                @foreach ($recent as $c)
                    <li wire:key="cmd-{{ $c->id }}" class="flex items-center gap-3 py-2">
                        <button type="button" wire:click="show({{ $c->id }})" class="min-w-0 truncate text-left font-mono {{ $shown?->id === $c->id ? 'text-accent' : 'hover:text-accent' }}">{{ CommandLine::prefix($c->tool) }} {{ $c->command }}</button>
                        <span class="ml-auto shrink-0 text-2xs text-muted">{{ \App\Support\When::withTime($c->created_at) }}</span>
                        <x-status-badge :status="$state($c)" class="shrink-0" />
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
