<?php

namespace App\Livewire\Setup;

use App\Jobs\SetupDnsServer;
use App\Models\Certificate;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Backups\BackupSettings;
use App\Services\Components\Components;
use App\Services\Dns\DnsSettings;
use App\Services\Dns\Zones;
use App\Services\Monitor\MonitorException;
use App\Services\Php\PhpVersions;
use App\Services\Security\Firewall;
use App\Services\Security\Ssh;
use App\Services\Settings\Notifications;
use App\Services\Settings\OutgoingMail;
use App\Services\Settings\PanelSettings;
use App\Services\Setup\FirstRun;
use App\Services\Sites\SiteDomains;
use App\Services\Ssl\Certificates;
use App\Services\Updates\Updates;
use App\Support\DnsRecords;
use App\Support\Hostnames;
use App\Support\Locales;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The setup wizard: what a new hosting server needs before the first
 * site, one step at a time. Each step saves through the same services as
 * its settings page (Araçlar ve Ayarlar, DNS, Güvenlik, Yedekler) and can
 * be passed over; the summary lists what is left, and so does Genel Bakış
 * until the wizard is finished.
 */
#[Layout('components.layouts.setup')]
#[Title('Kurulum sihirbazı · Servik')]
class SetupWizard extends Component
{
    #[Url(as: 'adim', except: 'sunucu')]
    public string $step = 'sunucu';

    // Sunucu
    public string $hostname = '';

    public string $ipv4 = '';

    public string $ipv6 = '';

    public string $timezone = '';

    public string $locale = '';

    // Ad sunucuları
    public string $domain = '';

    public string $ns1 = '';

    public string $ns2 = '';

    public string $ns2Ip = '';

    public bool $installDns = true;

    public bool $createZone = true;

    // Panel adresi
    public string $panelHostname = '';

    public bool $issueCertificate = true;

    // Bildirimler
    public string $emails = '';

    // Varsayılanlar
    public string $webStack = Site::NGINX;

    public string $phpVersion = '';

    public string $channel = 'stable';

    // Yedekler
    public bool $backups = true;

    public string $backupTime = '03:00';

    /** @var array{ipv4: list<string>, ipv6: list<string>} */
    public array $detected = ['ipv4' => [], 'ipv6' => []];

    public function mount(FirstRun $firstRun, PanelSettings $settings, DnsSettings $dns): void
    {
        if (! array_key_exists($this->step, FirstRun::STEPS) && $this->step !== 'ozet') {
            $this->step = 'sunucu';
        }
        $firstRun->start();

        try {
            $server = $settings->server();
            $this->hostname = (string) ($server['hostname'] ?? '');
            $found = (array) app(AgentClient::class)->call('system.addresses');
            $this->detected = ['ipv4' => array_values($found['ipv4'] ?? []), 'ipv6' => array_values($found['ipv6'] ?? [])];
        } catch (AgentException) {
            // The fields still work; the step says the agent is away.
        }
        $this->hostname = (string) (Setting::get('server.hostname') ?: $this->hostname);
        $this->ipv4 = (string) ($dns->ipv4() ?? ($this->detected['ipv4'][0] ?? ''));
        $this->ipv6 = (string) ($dns->ipv6() ?? ($this->detected['ipv6'][0] ?? ''));
        $this->timezone = $settings->timezone();
        $this->locale = $settings->locale();

        $this->domain = (string) Setting::get('setup.domain', $this->guessDomain());
        $nameservers = $dns->nameservers();
        $this->ns1 = $nameservers[0] ?? ($this->domain !== '' ? 'ns1.'.$this->domain : '');
        $this->ns2 = $nameservers[1] ?? ($this->domain !== '' ? 'ns2.'.$this->domain : '');
        $this->ns2Ip = (string) Setting::get('setup.ns2_ip', '');
        $dnsState = Setting::get('dns.server', [])['state'] ?? null;
        $this->installDns = $dnsState !== 'ready';
        $this->createZone = $this->domain === '' || ! DnsZone::where('name', $this->domain)->exists();

        $this->panelHostname = (string) (Setting::get('panel.hostname') ?: ($this->domain !== '' ? 'panel.'.$this->domain : ''));

        $saved = app(Notifications::class)->settings()['emails'];
        $this->emails = implode(', ', $saved !== [] ? $saved : [auth()->user()->email]);

        $this->webStack = app(Components::class)->defaultStack();
        $this->phpVersion = (string) app(PhpVersions::class)->default();
        $this->channel = app(Updates::class)->channel();

        $schedule = app(BackupSettings::class)->schedule();
        $this->backups = (bool) $schedule['enabled'];
        $this->backupTime = (string) $schedule['time'];
    }

    public function go(string $step): void
    {
        if (array_key_exists($step, FirstRun::STEPS) || $step === 'ozet') {
            $this->resetValidation();
            $this->step = $step;
        }
    }

    public function back(): void
    {
        $slugs = [...array_keys(FirstRun::STEPS), 'ozet'];
        $at = array_search($this->step, $slugs, true);
        $this->go($slugs[max(0, (int) $at - 1)]);
    }

    /**
     * Passes over the step; it stays open on the summary.
     */
    public function skip(FirstRun $firstRun): void
    {
        $firstRun->mark($this->step, 'skipped');
        $this->next();
    }

    public function save(FirstRun $firstRun): void
    {
        $saved = match ($this->step) {
            'sunucu' => $this->saveServer(),
            'ad-sunuculari' => $this->saveNameservers(),
            'panel' => $this->savePanel(),
            'bildirimler' => $this->saveNotifications(),
            'guvenlik' => $this->saveFirewall(),
            'varsayilanlar' => $this->saveDefaults(),
            'yedekler' => $this->saveBackups(),
            default => false,
        };
        if (! $saved) {
            return;
        }
        $firstRun->mark($this->step, 'done');
        $this->next();
    }

    public function finish(FirstRun $firstRun): void
    {
        $firstRun->complete();
        session()->flash('toast', __('Kurulum tamamlandı. Sihirbazı Araçlar ve Ayarlar → Kurulum sihirbazı\'ndan yeniden açabilirsiniz.'));
        $this->redirect(route('dashboard'));
    }

    private function saveServer(): bool
    {
        $settings = app(PanelSettings::class);
        $this->hostname = strtolower(trim($this->hostname));
        $this->ipv4 = trim($this->ipv4);
        $this->ipv6 = trim($this->ipv6);
        $this->validate([
            'hostname' => ['required', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/'],
            'ipv4' => ['required', 'ipv4'],
            'ipv6' => ['nullable', 'ipv6'],
            'timezone' => ['required', 'string', 'max:64', fn ($attr, $value, $fail) => in_array($value, timezone_identifiers_list(), true) ? null : $fail(__('Listeden bir saat dilimi seçin.'))],
            'locale' => ['required', Rule::in(array_keys(Locales::all()))],
        ], [
            'hostname.regex' => __('Tam bir sunucu adı girin, örneğin srv1.ornek.com.tr. Posta ve ters DNS (PTR) kaydı için noktalı tam ad gerekir.'),
        ], ['hostname' => __('sunucu adı'), 'ipv4' => __('IPv4 adresi'), 'ipv6' => __('IPv6 adresi'), 'timezone' => __('saat dilimi'), 'locale' => __('dil')]);

        try {
            if ($this->hostname !== (string) ($settings->server()['hostname'] ?? '')) {
                $settings->setHostname($this->hostname);
            } else {
                Setting::put('server.hostname', $this->hostname);
            }
            if ($this->timezone !== $settings->timezone()) {
                $settings->setTimezone($this->timezone);
            }
        } catch (AgentException $e) {
            $this->addError('hostname', __('Sunucu ayarı uygulanamadı: :reason', ['reason' => $e->reason]));

            return false;
        }
        app(DnsSettings::class)->save(['ipv4' => $this->ipv4, 'ipv6' => $this->ipv6 ?: null]);
        if ($this->locale !== $settings->locale()) {
            $settings->setLocale($this->locale);
            Locales::apply(Locales::forUser(auth()->user()));
        }
        // Until the name servers step is saved, its suggestions follow the
        // server's name.
        $guess = $this->guessDomain();
        if (Setting::get('setup.domain') === null && $guess !== '' && $guess !== $this->domain) {
            $old = $this->domain;
            $follows = fn (string $value, string $label) => $value === '' || ($old !== '' && $value === $label.'.'.$old);
            $this->ns1 = $follows($this->ns1, 'ns1') ? 'ns1.'.$guess : $this->ns1;
            $this->ns2 = $follows($this->ns2, 'ns2') ? 'ns2.'.$guess : $this->ns2;
            $this->panelHostname = $follows($this->panelHostname, 'panel') ? 'panel.'.$guess : $this->panelHostname;
            $this->domain = $guess;
            $this->createZone = ! DnsZone::where('name', $guess)->exists();
        }

        return true;
    }

    private function saveNameservers(): bool
    {
        $this->domain = Hostnames::normalize($this->domain);
        $this->ns1 = rtrim(mb_strtolower(trim($this->ns1)), '.');
        $this->ns2 = rtrim(mb_strtolower(trim($this->ns2)), '.');
        $this->ns2Ip = trim($this->ns2Ip);
        $this->validate([
            'domain' => ['required', fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) ? null : $fail(__('Geçerli bir alan adı girin, örneğin ornek.com.tr.'))],
            'ns1' => ['required', 'different:ns2', fn ($attr, $value, $fail) => $this->validNameserver($value) ? null : $fail(__('Tam adı yazın, örneğin ns1.ornek.com.tr.'))],
            'ns2' => ['required', fn ($attr, $value, $fail) => $this->validNameserver($value) ? null : $fail(__('Tam adı yazın, örneğin ns2.ornek.com.tr.'))],
            'ns2Ip' => ['nullable', 'ipv4'],
        ], ['ns1.different' => __('İki ad sunucusu farklı adlar olmalı.')], ['domain' => __('alan adı'), 'ns1' => __('1. ad sunucusu'), 'ns2' => __('2. ad sunucusu'), 'ns2Ip' => __('2. ad sunucusunun IP adresi')]);

        $dns = app(DnsSettings::class);
        $dns->save(['nameservers' => [$this->ns1, $this->ns2]]);
        Setting::put('setup.domain', $this->domain);
        Setting::put('setup.ns2_ip', $this->ns2Ip !== '' && $this->ns2Ip !== $dns->ipv4() ? $this->ns2Ip : null);

        $state = Setting::get('dns.server', [])['state'] ?? null;
        if ($this->installDns && ! in_array($state, ['ready', 'installing'], true)) {
            Setting::put('dns.server', ['state' => 'installing', 'at' => now()->toIso8601String()]);
            SetupDnsServer::dispatch();
        }
        if ($this->createZone && ! DnsZone::where('name', $this->domain)->exists()) {
            $zone = app(Zones::class)->create($this->domain);
            $this->pointNs2($zone);
        }
        if (($zone = DnsZone::where('name', $this->domain)->first()) !== null) {
            $this->addAddress($zone, $this->hostname);
        }
        if ($this->panelHostname === '') {
            $this->panelHostname = 'panel.'.$this->domain;
        }

        return true;
    }

    private function savePanel(): bool
    {
        $this->panelHostname = Hostnames::normalize($this->panelHostname);
        $this->validate([
            'panelHostname' => [
                'required',
                fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) ? null : $fail(__('Geçerli bir ad girin, örneğin panel.ornek.com.tr.')),
                fn ($attr, $value, $fail) => app(SiteDomains::class)->siteFor($value) !== null ? $fail(__('Bu ad bir sitede kullanılıyor; panel için ayrı bir ad seçin, örneğin panel.:value.', ['value' => $value])) : null,
                fn ($attr, $value, $fail) => $value === Setting::get('mail.hostname') ? $fail(__('Bu ad posta sunucusunun adı; panel için ayrı bir ad seçin.')) : null,
            ],
        ], [], ['panelHostname' => __('panel adresi')]);

        $previous = (string) Setting::get('panel.hostname', '');
        Setting::put('panel.hostname', $this->panelHostname);
        $zone = $this->zoneFor($this->panelHostname);
        if ($zone !== null) {
            $this->addAddress($zone, $this->panelHostname);
        }
        if ($this->issueCertificate && ($this->panelHostname !== $previous || ! $this->panelCertificateInstalled())) {
            app(Certificates::class)->issuePanel($this->panelHostname);
        }

        return true;
    }

    private function saveNotifications(): bool
    {
        $list = array_values(array_unique(array_filter(array_map(
            fn ($e) => mb_strtolower(trim($e)),
            preg_split('/[\s,;]+/', $this->emails) ?: [],
        ))));
        if ($list === []) {
            $this->addError('emails', __('En az bir e-posta adresi girin.'));

            return false;
        }
        foreach ($list as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addError('emails', __(':email geçerli bir e-posta adresi değil.', ['email' => $email]));

                return false;
            }
        }
        if (count($list) > 10) {
            $this->addError('emails', __('En fazla 10 adres girin.'));

            return false;
        }
        app(Notifications::class)->save(['emails' => $list]);
        $this->emails = implode(', ', $list);

        return true;
    }

    private function saveFirewall(): bool
    {
        $firewall = app(Firewall::class);
        try {
            // SSH's ports as they are now, so the rules keep them open.
            app(Ssh::class)->status();
        } catch (AgentException) {
            // The ports last read stay (22 by default).
        }
        try {
            $firewall->save([...$firewall->settings(), 'enabled' => true], request()->ip());
        } catch (MonitorException $e) {
            $this->addError('firewall', $e->getMessage());

            return false;
        } catch (AgentException $e) {
            $this->addError('firewall', Firewall::message($e));

            return false;
        }

        return true;
    }

    private function saveDefaults(): bool
    {
        $phpVersions = app(PhpVersions::class)->installed() ?? [];
        $this->validate([
            'webStack' => ['required', Rule::in([Site::NGINX, Site::APACHE])],
            'phpVersion' => $phpVersions === [] ? ['nullable'] : ['required', Rule::in($phpVersions)],
            'channel' => ['required', Rule::in(array_keys(Updates::CHANNELS))],
        ], [], ['webStack' => __('web sunucusu'), 'phpVersion' => __('PHP sürümü'), 'channel' => __('güncelleme kanalı')]);
        if ($this->webStack === Site::APACHE && ! app(Components::class)->isInstalled('apache')) {
            $this->addError('webStack', __('Apache bu sunucuda kurulu değil. Yazılım Yönetimi\'nden kurduktan sonra seçebilirsiniz.'));

            return false;
        }
        Setting::put('web.default_stack', $this->webStack);
        if ($this->phpVersion !== '') {
            Setting::put('php.default_version', $this->phpVersion);
        }
        app(Updates::class)->setChannel($this->channel);

        return true;
    }

    private function saveBackups(): bool
    {
        $this->validate([
            'backupTime' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ], ['backupTime.regex' => __('Saati SS:DD biçiminde girin, örneğin 03:00.')], ['backupTime' => __('yedekleme saati')]);
        app(BackupSettings::class)->save(['enabled' => $this->backups, 'time' => $this->backupTime]);

        return true;
    }

    private function next(): void
    {
        $slugs = [...array_keys(FirstRun::STEPS), 'ozet'];
        $at = array_search($this->step, $slugs, true);
        $this->go($slugs[min(count($slugs) - 1, (int) $at + 1)]);
    }

    /**
     * The domain the server's name belongs to: srv1.ornek.com.tr gives
     * ornek.com.tr.
     */
    private function guessDomain(): string
    {
        $parts = explode('.', strtolower($this->hostname));
        if (count($parts) < 3) {
            return count($parts) === 2 ? strtolower($this->hostname) : '';
        }
        // Two-label public suffixes such as com.tr or co.uk keep three labels.
        $keep = in_array($parts[count($parts) - 2], ['com', 'net', 'org', 'gen', 'web', 'biz', 'info', 'av', 'bel', 'edu', 'k12', 'gov', 'co', 'ac'], true) && strlen(end($parts)) === 2 ? 3 : 2;

        return implode('.', array_slice($parts, -$keep));
    }

    private function validNameserver(string $host): bool
    {
        return str_contains($host, '.') && DnsRecords::hostname($host, 'invalid') !== null;
    }

    /**
     * The zone on this server that $hostname belongs to, if any.
     */
    private function zoneFor(string $hostname): ?DnsZone
    {
        $parts = explode('.', $hostname);
        while (count($parts) >= 2) {
            $zone = DnsZone::where('name', implode('.', $parts))->first();
            if ($zone !== null) {
                return $zone;
            }
            array_shift($parts);
        }

        return null;
    }

    /**
     * Adds A (and AAAA) records for $hostname inside $zone when it has none.
     */
    private function addAddress(DnsZone $zone, string $hostname): void
    {
        if ($hostname !== $zone->name && ! str_ends_with($hostname, '.'.$zone->name)) {
            return;
        }
        $name = $hostname === $zone->name ? '@' : substr($hostname, 0, -strlen('.'.$zone->name));
        if ($zone->records()->where('name', $name)->whereIn('type', ['A', 'AAAA', 'CNAME'])->exists()) {
            return;
        }
        $dns = app(DnsSettings::class);
        app(Zones::class)->change($zone, function (DnsZone $zone) use ($name, $dns) {
            foreach (['A' => $dns->ipv4(), 'AAAA' => $dns->ipv6()] as $type => $ip) {
                if ($ip) {
                    $zone->records()->create(['name' => $name, 'type' => $type, 'content' => $ip, 'ttl' => $zone->default_ttl]);
                }
            }
        });
    }

    /**
     * The second name server on its own address, when one was given.
     */
    private function pointNs2(DnsZone $zone): void
    {
        $ip = Setting::get('setup.ns2_ip');
        if (! $ip || ! str_ends_with($this->ns2, '.'.$zone->name)) {
            return;
        }
        $label = substr($this->ns2, 0, -strlen('.'.$zone->name));
        app(Zones::class)->change($zone, function (DnsZone $zone) use ($label, $ip) {
            $zone->records()->where('name', $label)->where('type', 'A')->update(['content' => $ip]);
        });
    }

    private function panelCertificateInstalled(): bool
    {
        return Certificate::where('kind', 'panel')->whereNotNull('not_after')->exists();
    }

    public function render(FirstRun $firstRun, PanelSettings $settings): View
    {
        $dns = app(DnsSettings::class);
        $firewall = app(Firewall::class);

        return view('livewire.setup.wizard', [
            'steps' => FirstRun::STEPS,
            'checklist' => collect($firstRun->checklist())->keyBy('step'),
            'timezones' => $this->timezoneOptions($settings),
            'dnsServer' => Setting::get('dns.server', []),
            'glue' => [[$this->ns1, $dns->ipv4() ?: $this->ipv4], [$this->ns2, $this->ns2Ip ?: ($dns->ipv4() ?: $this->ipv4)]],
            'port' => $settings->port(),
            'mailProblem' => $this->step === 'bildirimler' ? app(OutgoingMail::class)->problem() : null,
            'firewallOn' => $firewall->enabled(),
            'firewallServices' => $this->step === 'guvenlik' ? array_filter($firewall->services(), fn ($s) => $s['enabled']) : [],
            'apacheInstalled' => app(Components::class)->isInstalled('apache'),
            'phpVersions' => $this->step === 'varsayilanlar' ? (app(PhpVersions::class)->installed() ?? []) : [],
            'backupTarget' => app(BackupSettings::class)->target(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function timezoneOptions(PanelSettings $settings): array
    {
        if ($this->step !== 'sunucu') {
            return [];
        }
        $zones = array_values(array_intersect($settings->timezones(), timezone_identifiers_list()));
        if (! in_array($this->timezone, $zones, true)) {
            array_unshift($zones, $this->timezone);
        }

        return $zones;
    }
}
