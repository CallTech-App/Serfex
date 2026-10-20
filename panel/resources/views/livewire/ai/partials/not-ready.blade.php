{{-- Shown instead of the chat until Servik AI has a key and is on. --}}
<div class="grid flex-1 place-items-center px-6 py-12 text-center text-[13.5px]">
    <div class="max-w-sm">
        <span class="mx-auto grid size-10 place-items-center rounded-full bg-accent-soft text-accent"><x-icon name="sparkles" class="size-5" /></span>
        <p class="mt-3 font-medium">{{ $hasKey ? __('Servik AI kapalı') : __('Servik AI için Claude API anahtarı gerekiyor') }}</p>
        <p class="mt-1 text-muted">{{ $hasKey
            ? __('Ayarlardan açtığınızda sunucunuz hakkında soru sorabilirsiniz.')
            : __('Anthropic hesabınızdan bir API anahtarı alıp ayarlara girin. Anahtar şifreli saklanır.') }}</p>
        <a href="{{ route('ai.settings') }}" wire:navigate class="mt-4 inline-flex h-9 items-center rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Servik AI ayarları') }}</a>
    </div>
</div>
