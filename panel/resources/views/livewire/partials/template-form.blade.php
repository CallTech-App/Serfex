{{-- The application template part of an install form (InstallsAppTemplate): $template, $summary, and the component's $answers, $uploads, $adminName, $adminEmail. --}}
@php
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
@endphp
<div class="space-y-3 rounded-lg border border-line p-4">
    <div>
        @if ($template->description)<p>{{ $template->description }}</p>@endif
        <p class="mt-0.5 text-2xs text-muted">@if ($template->isPackage()){{ $template->latest_version ? __('Sürüm paketi :version, kendini günceller.', ['version' => $template->latest_version]) : __('Son sürüm paketi kurulur; uygulama kendini günceller.') }}@else{{ __('Sürüm :version.', ['version' => $template->versionLabel() ?? $template->ref]) }}@endif {{ ucfirst($summary) }}.</p>
    </div>
    @foreach ($template->questions() as $q)
        @php $name = ($q['type'] === 'file' ? 'uploads.' : 'answers.').$q['key']; @endphp
        <div wire:key="tq-{{ $template->id }}-{{ $q['key'] }}">
            <label for="tq-{{ $q['key'] }}" class="mb-1.5 block">{{ $q['label'] ?: $q['key'] }} @unless ($q['required'])<span class="text-2xs text-muted">· {{ __('isteğe bağlı') }}</span>@endunless</label>
            @if ($q['type'] === 'file')
                <input id="tq-{{ $q['key'] }}" type="file" wire:model="{{ $name }}" class="block w-full text-[13px] file:mr-3 file:h-9 file:rounded-md file:border file:border-line file:bg-surface file:px-3 file:text-[13px] hover:file:bg-surface-2">
            @else
                <input id="tq-{{ $q['key'] }}" wire:model="{{ $name }}" @if ($q['secret']) type="password" autocomplete="new-password" @endif spellcheck="false"
                       class="{{ $field }} {{ $q['secret'] ? 'font-mono' : '' }} {{ $errors->has($name) ? 'border-crit' : 'border-line' }}">
            @endif
            @error($name)<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
        </div>
    @endforeach
    @if ($template->create_admin)
        <div>
            <p class="mb-1.5">{{ __('Yönetici hesabı') }}</p>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                <input wire:model="adminName" aria-label="{{ __('Yönetici adı') }}" placeholder="{{ __('Ad') }}" class="{{ $field }} {{ $errors->has('adminName') ? 'border-crit' : 'border-line' }}">
                <input wire:model="adminEmail" type="email" aria-label="{{ __('Yönetici e-postası') }}" placeholder="{{ __('E-posta') }}" class="{{ $field }} font-mono {{ $errors->has('adminEmail') ? 'border-crit' : 'border-line' }}">
            </div>
            @error('adminName')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
            @error('adminEmail')<p class="mt-1 text-[13px] text-crit">{{ $message }}</p>@enderror
            <p class="mt-1 text-2xs text-muted">{{ __('Parolayı Serfex üretir ve kurulum başlarken size bir kez gösterir.') }}</p>
        </div>
    @endif
</div>
