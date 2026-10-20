@php
    use App\Models\NotificationEvent;
    use App\Services\Settings\OutgoingMail;
    use App\Support\When;
    $field = 'h-9 w-full rounded-md border bg-surface px-3 text-[13px] outline-none focus:border-accent';
    $row = 'grid items-start gap-2 py-3.5 sm:grid-cols-[220px_1fr] sm:gap-6';
    $btn = 'flex h-9 items-center gap-2 rounded-md border border-line bg-surface px-3.5 text-[13.5px] hover:bg-surface-2';
    $channel = ['email' => __('E-posta'), 'webhook' => 'Webhook'];
@endphp
<x-settings-page current="settings.notifications" title="{{ __('Bildirimler') }}" description="{{ __('Bir sorun çıktığında ve düzeldiğinde kimin, nasıl haberdar olacağı. Her sorun bir kez bildirilir.') }}">
    <section class="rounded-xl border border-line bg-surface">
        <form wire:submit="save" class="max-w-3xl px-5 py-5 text-[13.5px]">
            <div class="divide-y divide-line">
                <div class="{{ $row }}">
                    <label for="emails" class="sm:pt-2">{{ __('Alıcılar') }}</label>
                    <div>
                        <input id="emails" wire:model="emails" spellcheck="false" autocomplete="off" placeholder="{{ implode(', ', $defaultRecipients) ?: 'ops@ornek.com.tr' }}"
                               class="{{ $field }} font-mono sm:w-96 {{ $errors->has('emails') ? 'border-crit' : 'border-line' }}">
                        @error('emails')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Virgülle ayırın. Boş bırakılırsa tam yöneticilerin adreslerine gider.') }}</p>
                        @enderror
                    </div>
                </div>
                <div class="{{ $row }}">
                    <span>{{ __('Ne zaman') }}</span>
                    <div class="space-y-2.5">
                        @foreach (NotificationEvent::KINDS as $key => [$label, $help])
                            <label class="flex items-start gap-2.5">
                                <input type="checkbox" wire:model="events.{{ $key }}" class="mt-0.5 size-4 accent-accent">
                                <span>{{ $label }}<span class="block text-2xs text-muted">{{ $help }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <div class="{{ $row }}">
                    <label for="webhook" class="sm:pt-2">{{ __('Webhook adresi') }}</label>
                    <div>
                        <input id="webhook" wire:model="webhookUrl" spellcheck="false" autocomplete="off" placeholder="{{ __('https://hooks.ornek.com/servik') }}"
                               class="{{ $field }} font-mono sm:w-96 {{ $errors->has('webhookUrl') ? 'border-crit' : 'border-line' }}">
                        @error('webhookUrl')
                            <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p>
                        @else
                            <p class="mt-1.5 text-2xs text-muted"><x-t text="Sorunlar bu adrese JSON olarak POST edilir; Slack, Discord, Teams ya da kendi sisteminiz. Gövdenin HMAC-SHA256 imzası :part başlığındadır."><x-slot:part><span class="font-mono">X-Servik-Signature</span></x-slot:part></x-t></p>
                        @enderror
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-[13px]">
                            <label for="webhook-locale" class="text-muted">{{ __('Başlıkların dili') }}</label>
                            <select id="webhook-locale" wire:model="webhookLocale" class="h-8 rounded-md border border-line bg-surface px-2 text-[13px] outline-none focus:border-accent">
                                <option value="">{{ __('Sunucunun dili (:name)', ['name' => \App\Support\Locales::all()[\App\Support\Locales::serverDefault()]]) }}</option>
                                @foreach (\App\Support\Locales::all() as $code => $name)
                                    <option value="{{ $code }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-3 text-[13px]">
                            @if ($secret)
                                <span class="font-mono text-[12.5px]">{{ $secret }}</span>
                                <button type="button" wire:click="newSecret" class="text-accent hover:underline">{{ __('Yenile') }}</button>
                            @else
                                <button type="button" wire:click="$set('showSecret', true)" class="text-accent hover:underline">{{ __('İmza anahtarını göster') }}</button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-5 flex flex-wrap items-center gap-2">
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
                <button type="button" wire:click="test" wire:loading.attr="disabled" class="{{ $btn }}">
                    <x-icon name="send" />
                    <span wire:loading.remove wire:target="test">{{ __('Deneme bildirimi gönder') }}</span>
                    <span wire:loading wire:target="test">{{ __('Gönderiliyor…') }}</span>
                </button>
            </div>
            @if ($tested)
                <ul class="mt-3 space-y-1 text-[13px]" role="status">
                    @foreach ($tested as $via => $error)
                        <li class="flex items-start gap-2">
                            <x-icon :name="$error ? 'circle-x' : 'circle-check'" @class(['mt-0.5 shrink-0', 'text-crit' => $error, 'text-ok' => ! $error]) />
                            <span>{{ $channel[$via] }}: {{ $error ?? __('gönderildi.') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </form>
    </section>

    <section class="rounded-xl border border-line bg-surface">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Panelin e-posta gönderimi') }}</h2>
        <form wire:submit="saveMail" class="max-w-3xl px-5 pb-5 text-[13.5px]">
            <div class="divide-y divide-line">
                <div class="{{ $row }}">
                    <span>{{ __('Gönderim yolu') }}</span>
                    <div class="space-y-2">
                        <label class="flex items-start gap-2.5">
                            <input type="radio" wire:model.live="mode" value="server" class="mt-0.5 size-4 accent-accent">
                            <span>{{ __('Bu sunucunun posta sunucusu') }}<span class="block text-2xs text-muted">{{ __('E-posta bölümünde kurulan Postfix ile gönderilir.') }}</span></span>
                        </label>
                        <label class="flex items-start gap-2.5">
                            <input type="radio" wire:model.live="mode" value="smtp" class="mt-0.5 size-4 accent-accent">
                            <span>{{ __('Başka bir SMTP sunucusu') }}<span class="block text-2xs text-muted">{{ __('Google Workspace, Microsoft 365, Amazon SES ya da kendi posta sunucunuz.') }}</span></span>
                        </label>
                    </div>
                </div>
                @if ($mode === 'smtp')
                    <div class="{{ $row }}">
                        <label for="smtp-host" class="sm:pt-2">{{ __('SMTP sunucusu') }}</label>
                        <div>
                            <div class="flex flex-wrap gap-2">
                                <input id="smtp-host" wire:model="host" spellcheck="false" placeholder="{{ __('smtp.ornek.com.tr') }}" class="{{ $field }} min-w-0 flex-1 font-mono sm:w-72 sm:flex-none {{ $errors->has('host') ? 'border-crit' : 'border-line' }}">
                                <input wire:model="port" inputmode="numeric" aria-label="{{ __('Port') }}" class="{{ $field }} num w-20 font-mono {{ $errors->has('port') ? 'border-crit' : 'border-line' }}">
                            </div>
                            @error('host') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                            @error('port') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="{{ $row }}">
                        <label for="smtp-enc" class="sm:pt-2">{{ __('Şifreleme') }}</label>
                        <select id="smtp-enc" wire:model="encryption" class="{{ $field }} border-line sm:w-56">
                            @foreach (OutgoingMail::ENCRYPTION as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="{{ $row }}">
                        <label for="smtp-user" class="sm:pt-2">{{ __('Kullanıcı adı') }}</label>
                        <input id="smtp-user" wire:model="username" spellcheck="false" autocomplete="off" class="{{ $field }} border-line font-mono sm:w-72">
                    </div>
                    <div class="{{ $row }}">
                        <label for="smtp-pass" class="sm:pt-2">{{ __('Parola') }}</label>
                        <div>
                            <input id="smtp-pass" type="password" wire:model="password" autocomplete="new-password" placeholder="{{ $hasPassword ? __('kayıtlı; değiştirmek için yazın') : '' }}" class="{{ $field }} border-line sm:w-72">
                            <p class="mt-1.5 text-2xs text-muted">{{ __('Şifreli saklanır, bir daha gösterilmez.') }}</p>
                        </div>
                    </div>
                @endif
                <div class="{{ $row }}">
                    <label for="from" class="sm:pt-2">{{ __('Gönderen adresi') }}</label>
                    <div>
                        <input id="from" wire:model="fromAddress" spellcheck="false" placeholder="{{ $fromDefault }}" class="{{ $field }} font-mono sm:w-72 {{ $errors->has('fromAddress') ? 'border-crit' : 'border-line' }}">
                        @error('fromAddress') <p class="mt-1.5 text-[13px] text-crit">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            @if ($mailProblem)
                <p class="mt-3 flex items-start gap-2 rounded-lg bg-warn-soft p-3 text-[13px]"><x-icon name="triangle-alert" class="mt-0.5 shrink-0 text-warn" />{{ $mailProblem }}</p>
            @endif
            <div class="mt-5">
                <button type="submit" class="h-9 rounded-md bg-accent px-3.5 font-medium text-accent-fg hover:brightness-110">{{ __('Kaydet') }}</button>
            </div>
        </form>
    </section>

    <section class="min-w-0 rounded-xl border border-line bg-surface">
        <h2 class="flex h-12 items-center border-b border-line px-5 text-[14.5px] font-semibold">{{ __('Son bildirimler') }}</h2>
        <div class="overflow-x-auto">
            <table class="w-full text-[13.5px] sm:min-w-[640px]">
                <thead>
                    <tr class="border-b border-line text-left text-2xs tracking-[.06em] text-muted uppercase">
                        <th class="py-2.5 pr-3 pl-5 font-medium">{{ __('Sorun') }}</th>
                        <th class="hidden px-3 py-2.5 font-medium sm:table-cell">{{ __('Durum') }}</th>
                        <th class="hidden px-3 py-2.5 font-medium md:table-cell">{{ __('Gönderildi') }}</th>
                        <th class="py-2.5 pr-5 pl-3 text-right font-medium">{{ __('Zaman') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($recent as $event)
                        <tr wire:key="event-{{ $event->id }}">
                            <td class="py-3 pr-3 pl-5">
                                <span class="text-2xs text-muted">{{ $event->kindName() }}</span>
                                <span class="block">{{ $event->titleIn() }}</span>
                                @if ($event->error)
                                    <span class="block text-[12.5px] text-crit">{{ $event->error }}</span>
                                @endif
                            </td>
                            <td class="hidden px-3 py-3 sm:table-cell">
                                <x-status-badge :status="$event->state === 'open' ? new \App\Support\Badge(__('Sürüyor'), 'warn') : new \App\Support\Badge(__('Düzeldi'), 'ok')" />
                            </td>
                            <td class="hidden px-3 py-3 text-[13px] md:table-cell">
                                @forelse ((array) $event->sent as $via => $ok)
                                    <span @class(['mr-2', 'text-muted' => $ok, 'text-crit' => ! $ok])>{{ $channel[$via] ?? $via }} {{ $ok ? '✓' : '✗' }}</span>
                                @empty
                                    <span class="text-muted">—</span>
                                @endforelse
                            </td>
                            <td class="num py-3 pr-5 pl-3 text-right whitespace-nowrap text-muted">{{ When::withTime($event->resolved_at ?? $event->opened_at) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-5 py-10 text-center">
                                <p class="font-medium">{{ __('Henüz bildirim yok') }}</p>
                                <p class="mt-1 text-[13px] text-muted">{{ __('Servik her 5 dakikada bir denetler; bir sorun çıkınca burada ve seçtiğiniz kanallarda görürsünüz.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-settings-page>
