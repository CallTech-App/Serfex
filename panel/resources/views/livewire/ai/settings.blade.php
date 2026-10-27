@php
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $money = fn ($v) => '$'.\App\Support\Num::format((float) $v, 2);
    $tokens = fn ($v) => \App\Support\Num::format((int) $v, 0);
@endphp
<div class="space-y-6">
    <div>
        <nav class="flex items-center gap-1.5 text-[13px] text-muted" aria-label="{{ __('Konum') }}">
            <a href="{{ route('ai.index') }}" wire:navigate class="flex items-center gap-1 hover:text-accent"><x-icon name="arrow-left" class="size-3.5" />Serfex AI</a>
            <x-icon name="chevron-right" class="size-3.5" />
            <span class="text-fg">{{ __('Ayarlar') }}</span>
        </nav>
        <h1 class="mt-2 text-[22px] font-semibold tracking-tight">{{ __('Serfex AI ayarları') }}</h1>
        <p class="mt-1 text-[13px] text-muted">{{ __('Claude bağlantısı, model, Serfex AI\'ın kendi başına yapabilecekleri ve harcama.') }}</p>
    </div>

    <section class="rounded-xl border border-line bg-surface">
        <form wire:submit="save" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <div class="divide-y divide-line">
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span>Serfex AI</span>
                    <label class="flex items-center gap-2.5">
                        <input type="checkbox" wire:model="enabled" class="size-4 accent-accent">
                        {{ __('Asistanı kullan') }}
                    </label>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="key" class="sm:pt-2">{{ __('Claude API anahtarı') }}</label>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <input id="key" type="password" wire:model="key" placeholder="{{ $masked ?? 'sk-ant-…' }}" spellcheck="false" autocomplete="off"
                                   class="{{ $field }} font-mono sm:w-80 {{ $errors->has('key') ? 'border-crit' : 'border-line' }}">
                            <button type="button" wire:click="test" wire:loading.attr="disabled" wire:target="test"
                                    class="flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3 hover:bg-surface-2">
                                <x-icon name="loader-circle" class="animate-spin" wire:loading wire:target="test" />{{ __('Bağlantıyı dene') }}
                            </button>
                            @if ($masked)
                                <button type="button" wire:click="removeKey" class="h-9 px-2 text-muted hover:text-crit">{{ __('Anahtarı sil') }}</button>
                            @endif
                        </div>
                        @error('key')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            @if ($check)
                                <p @class(['mt-1.5 text-[13px]', 'text-ok' => $check[0], 'text-crit' => ! $check[0]])>{{ $check[1] }}</p>
                            @else
                                <p class="mt-1.5 text-2xs text-muted">{{ __(':masked Anahtar panelin uygulama anahtarıyla şifrelenerek saklanır ve bir daha gösterilmez.', ['masked' => $masked ? __('Kayıtlı: :masked. Değiştirmek için yenisini yazın.', ['masked' => $masked]) : __('Anthropic Console\'dan (console.anthropic.com) bir anahtar oluşturun.')]) }}</p>
                            @endif
                        @enderror
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span class="sm:pt-0.5">{{ __('Model') }}</span>
                    <div class="space-y-2">
                        @foreach (\App\Services\Ai\Models::ALL as $id => [$label, $note, $in, $out])
                            <label class="flex items-start gap-2.5">
                                <input type="radio" wire:model="model" value="{{ $id }}" class="mt-0.5 size-4 accent-accent">
                                <span>{{ $label }} <span class="text-muted">· {{ $note }}</span>
                                    <span class="num block text-2xs text-muted">{{ __('Milyon token başına :input girdi, :output çıktı', ['input' => '$'.rtrim(rtrim(number_format($in, 2), '0'), '.'), 'output' => '$'.rtrim(rtrim(number_format($out, 2), '0'), '.')]) }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <span>{{ __('Onaysız işlemler') }}</span>
                    <div>
                        <label class="flex items-start gap-2.5">
                            <input type="checkbox" wire:model="auto" class="mt-0.5 size-4 accent-accent">
                            {{ __('Saldıran adresleri en fazla 24 saat engelleyebilir ve siteleri en fazla 2 saat sınırlayabilir') }}
                        </label>
                        <p class="mt-1 ml-[26px] text-2xs text-muted">{{ __('Her ikisi de süresi dolunca kendiliğinden kalkar ve tek tıkla geri alınabilir. Kapalıyken bunları da öneri olarak sunar. Diğer her değişiklik her zaman onayınızı bekler.') }}</p>
                    </div>
                </div>
                <div class="grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6">
                    <label for="budget" class="sm:pt-2">{{ __('Aylık harcama sınırı') }}</label>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="text-muted">$</span>
                            <input id="budget" wire:model="budget" inputmode="decimal" placeholder="{{ __('Sınırsız') }}"
                                   class="{{ $field }} num w-32 {{ $errors->has('budget') ? 'border-crit' : 'border-line' }}">
                        </div>
                        @error('budget')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Bu ayın tahmini harcaması sınıra ulaşınca Serfex AI ay sonuna kadar yeni soru almaz. Boş bırakılırsa sınır yoktur.') }}</p>
                        @enderror
                    </div>
                </div>
            </div>
            <div class="mt-5">
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
            </div>
        </form>
        <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Sorularınız ve Serfex AI\'ın okuduğu panel bilgileri (site adları, durumlar, kayıtlar, günlük satırları) yanıt için Anthropic\'e gönderilir. Parolalar, .env değerleri, özel anahtarlar ve API anahtarları gönderilmez.') }}</p>
    </section>

    <section class="rounded-xl border border-line bg-surface">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Kullanım') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[480px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="px-5 py-2.5 font-medium"></th>
                        <th class="px-5 py-2.5 text-right font-medium">{{ __('İstek') }}</th>
                        <th class="px-5 py-2.5 text-right font-medium">{{ __('Girdi token') }}</th>
                        <th class="px-5 py-2.5 text-right font-medium">{{ __('Çıktı token') }}</th>
                        <th class="px-5 py-2.5 text-right font-medium">{{ __('Tahmini tutar') }}</th>
                    </tr>
                </thead>
                <tbody class="num divide-y divide-line">
                    @foreach (['this' => $thisMonth, 'last' => $lastMonth] as $month => $row)
                        <tr>
                            <td class="px-5 py-2.5">{{ $month === 'this' ? __('Bu ay') : __('Geçen ay') }}</td>
                            <td class="px-5 py-2.5 text-right">{{ $tokens($row->requests) }}</td>
                            <td class="px-5 py-2.5 text-right">{{ $tokens($row->input) }}</td>
                            <td class="px-5 py-2.5 text-right">{{ $tokens($row->output) }}</td>
                            <td class="px-5 py-2.5 text-right">{{ $money($row->cost) }}@if ($month === 'this' && $savedBudget) <span class="text-muted">/ {{ $money($savedBudget) }}</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="border-t border-line px-5 py-3 text-2xs text-muted">{{ __('Tutarlar liste fiyatlarından hesaplanan tahmindir; kesin fatura Anthropic Console\'dadır.') }}</p>
    </section>
</div>
