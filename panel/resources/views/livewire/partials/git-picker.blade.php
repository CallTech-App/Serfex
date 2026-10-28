{{-- The repository picker (PicksGitRepository): $picker from picker(), $pickedRepo, $pickedBranches, $branchError and $branch of the component. --}}
@php
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $link = 'text-accent hover:underline';
@endphp
@if ($picker['account'] === null)
    <div class="rounded-lg border border-line bg-surface-2 px-4 py-3">
        <p>{{ __(':provider hesabınızı bağlayın: depolarınız burada listelenir, Serfex dağıtım anahtarını ve webhook\'u depoya kendisi ekler.', ['provider' => $picker['label']]) }}</p>
        <a href="{{ $picker['connect'] }}" class="mt-2.5 flex h-9 w-fit items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="plug" />{{ __(':provider ile bağlan', ['provider' => $picker['label']]) }}</a>
    </div>
    @error('pickedRepo')<p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>@enderror
@elseif ($picker['error'])
    <div class="rounded-lg border border-line bg-crit-soft px-4 py-3">
        <p>{{ $picker['error'] }}</p>
        <div class="mt-2.5 flex flex-wrap gap-2">
            @if ($picker['expired'])
                <a href="{{ $picker['connect'] }}" class="flex h-9 items-center gap-2 rounded-md bg-accent px-3.5 text-[13.5px] font-medium text-accent-fg hover:brightness-110"><x-icon name="refresh-cw" />{{ __('Yeniden bağlan') }}</a>
            @else
                <button type="button" wire:click="refreshRepositories" class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2"><x-icon name="refresh-cw" />{{ __('Yeniden dene') }}</button>
            @endif
        </div>
    </div>
@else
    <div wire:key="picker-{{ $picker['provider'] }}-{{ md5(implode(',', array_column($picker['repos'], 'name'))) }}" class="space-y-2"
         x-data="{ open: false, q: '', picked: $wire.entangle('pickedRepo').live, repos: @js(array_map(fn ($r) => ['name' => $r['name'], 'private' => $r['private'], 'admin' => $r['admin']], $picker['repos'])),
                   get list() { const q = this.q.trim().toLowerCase(); return (q ? this.repos.filter(r => r.name.toLowerCase().includes(q)) : this.repos).slice(0, 100) } }">
        <div @keydown.escape.stop="open = false" @click.outside="open = false">
            <button type="button" @click="open = ! open; if (open) $nextTick(() => $refs.q.focus())" aria-haspopup="listbox" :aria-expanded="open"
                    class="{{ $field }} flex items-center gap-2 text-left {{ $errors->has('pickedRepo') ? 'border-crit' : 'border-line' }}">
                <x-icon :name="$picker['provider'] === 'bitbucket' ? 'git-branch' : $picker['provider']" class="size-4 shrink-0 text-muted" />
                <span class="min-w-0 flex-1 truncate" :class="picked ? 'font-mono' : 'text-muted'" x-text="picked || @js(__('Depo seçin'))"></span>
                <x-icon name="chevron-down" class="size-3.5 shrink-0 text-muted" />
            </button>
            <div x-show="open" x-cloak class="mt-1 rounded-lg border border-line bg-surface shadow-sm">
                <div class="border-b border-line p-2">
                    <input x-ref="q" x-model="q" type="search" spellcheck="false" autocomplete="off" placeholder="{{ __('Depo ara…') }}" aria-label="{{ __('Depo ara') }}"
                           @keydown.enter.prevent="if (list.length) { picked = list[0].name; open = false }"
                           class="h-8 w-full rounded-md border border-line bg-surface px-2.5 text-[13px] outline-none focus:border-accent">
                </div>
                <ul class="scroll-thin max-h-64 overflow-y-auto py-1 text-[13px]" role="listbox">
                    <template x-for="r in list" :key="r.name">
                        <li>
                            <button type="button" role="option" :aria-selected="picked === r.name" @click="picked = r.name; open = false"
                                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left hover:bg-surface-2" :class="picked === r.name && 'bg-accent-soft text-accent'">
                                <span class="w-3.5 shrink-0"><span x-show="r.private" title="{{ __('Özel depo') }}"><x-icon name="lock" class="size-3.5 text-muted" /></span></span>
                                <span class="min-w-0 flex-1 truncate font-mono" x-text="r.name"></span>
                                <span x-show="! r.admin" class="shrink-0 text-2xs text-muted">{{ __('yönetici değilsiniz') }}</span>
                            </button>
                        </li>
                    </template>
                    <li x-show="list.length === 0" class="px-3 py-2 text-muted">{{ __('Eşleşen depo yok.') }}</li>
                </ul>
            </div>
        </div>
        @error('pickedRepo')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror

        @if ($pickedRepo !== '')
            <div class="flex flex-wrap items-center gap-2">
                <label for="picker-branch" class="text-muted">{{ __('Dal') }}</label>
                @if ($pickedBranches)
                    <select id="picker-branch" wire:model="branch" class="h-9 max-w-full min-w-40 rounded-md border bg-surface px-2.5 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('branch') ? 'border-crit' : 'border-line' }}">
                        @foreach ($pickedBranches as $b)
                            <option value="{{ $b }}">{{ $b }}</option>
                        @endforeach
                    </select>
                @else
                    <input id="picker-branch" wire:model="branch" spellcheck="false" placeholder="main" class="h-9 w-40 rounded-md border bg-surface px-3 font-mono text-[13px] outline-none focus:border-accent {{ $errors->has('branch') ? 'border-crit' : 'border-line' }}">
                @endif
                <span wire:loading wire:target="pickedRepo" class="text-[13px] text-muted"><x-icon name="loader-circle" class="size-3.5 animate-spin" /></span>
            </div>
            @error('branch')<p class="text-[13px] text-crit">{{ $message }}</p>@enderror
            @if ($branchError)<p class="text-[13px] text-crit">{{ $branchError }}</p>@endif
        @endif

        <p class="text-2xs text-muted">
            <x-t text=":provider hesabı :name ile listeleniyor."><x-slot:provider>{{ $picker['label'] }}</x-slot:provider><x-slot:name><span class="font-mono">{{ $picker['account']->username }}</span></x-slot:name></x-t>
            <button type="button" wire:click="refreshRepositories" class="{{ $link }}">{{ __('Listeyi yenile') }}</button>
            · {{ __('Serfex depoya salt okunur bir dağıtım anahtarı ve bir webhook ekler.') }}
        </p>
    </div>
@endif
