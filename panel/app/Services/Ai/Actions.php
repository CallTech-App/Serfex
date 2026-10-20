<?php

namespace App\Services\Ai;

use App\Enums\ZoneStatus;
use App\Models\AiAction;
use App\Models\AiConversation;
use App\Models\AiEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\IpBlock;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Models\LaravelWorker;
use App\Models\ResourceLimit;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Audit\AuditLog;
use App\Services\Dns\Zones;
use App\Services\Laravel\LaravelApps;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\MailQueue;
use App\Services\Monitor\MonitorException;
use App\Services\Monitor\ResourceLimits;
use App\Services\Ssl\Certificates;
use App\Support\DnsRecords;
use Illuminate\Support\Str;

/**
 * What Servik AI may change. IP blocks of at most a day and temporary
 * limits of at most two hours it applies by itself (when the settings
 * allow it); everything else becomes a proposal that a person applies with
 * one click. Every change goes through the panel's own services, and
 * every one that can be undone keeps what its Undo needs.
 */
class Actions
{
    /** Proposals older than this can no longer be applied. */
    public const PROPOSAL_HOURS = 24;

    /** Applied changes can be undone for this long. */
    public const UNDO_HOURS = 24;

    /** The most worker processes a proposal may set. */
    public const MAX_WORKER_PROCESSES = 20;

    /** action => what it does, for Claude. */
    public const CATALOG = [
        'block_ip' => 'Block an address or prefix server-wide for longer than 24 hours or permanently (minutes 0). Needs address, minutes; domain optional.',
        'unblock_ip' => 'Lift an active block. Needs address.',
        'limit_site' => 'Temporary CPU/memory/PHP worker limit longer than 2 hours (up to 7 days). Needs domain, minutes and at least one of cpu_percent, memory_mb, php_workers.',
        'lift_limit' => 'End a site\'s temporary limit now. Needs domain.',
        'restart_php' => 'Stop the site\'s PHP-FPM workers so fresh ones start (fixes hung workers). Needs domain.',
        'dns_add_record' => 'Add a DNS record to a zone hosted here. Needs domain (the zone) and record.',
        'dns_update_record' => 'Change one DNS record. Needs domain, old_record (name, type, content) and record (the new values).',
        'dns_delete_record' => 'Delete one DNS record. Needs domain and old_record.',
        'ssl_renew' => 'Request or renew the site\'s Let\'s Encrypt certificate now. Needs domain.',
        'https_redirect' => 'Turn the HTTP to HTTPS redirect on or off. Needs domain and enabled.',
        'laravel_deploy' => 'Deploy a new release of the site\'s Laravel app from Git. Needs domain; ref (branch or tag) optional.',
        'laravel_rollback' => 'Put an earlier successful release live again. Needs domain and deployment_number.',
        'laravel_restart_workers' => 'Restart the app\'s queue workers (they pick up new code). Needs domain.',
        'laravel_scale_worker' => 'Set how many processes a queue worker runs. Needs domain, worker (its name) and processes.',
        'mail_hold' => 'Put a sender domain\'s queued mail on hold (stops a spam run). Needs domain (the sender domain).',
        'mail_release' => 'Release a sender domain\'s held mail. Needs domain.',
        'mail_flush' => 'Retry all deferred mail now.',
    ];

    public function __construct(
        private readonly AiSettings $settings,
        private readonly IpBlocks $blocks,
        private readonly ResourceLimits $limits,
        private readonly Zones $zones,
        private readonly Certificates $certificates,
        private readonly LaravelApps $apps,
        private readonly MailQueue $queue,
        private readonly AgentClient $agent,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $record = ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string', 'description' => '@ for the zone itself, or a label such as www'],
            'type' => ['type' => 'string', 'enum' => DnsRecords::TYPES],
            'content' => ['type' => 'string'],
            'priority' => ['type' => 'integer', 'description' => 'MX and SRV only'],
            'ttl' => ['type' => 'integer'],
        ], 'required' => ['name', 'type', 'content']];

        return [
            ['name' => 'block_ip', 'description' => 'Block an attacking address or prefix (at most /16 IPv4, /48 IPv6) on the whole server for at most 24 hours. You may do this WITHOUT asking, but only for addresses that the monitor or the logs show attacking (get_attacks, read_site_log). Never block the administrator\'s own address, search engines or the server itself. If the settings do not allow it, it becomes a proposal.', 'input_schema' => ['type' => 'object', 'properties' => [
                'address' => ['type' => 'string'],
                'minutes' => ['type' => 'integer', 'description' => '1 to 1440 (default 60)'],
                'reason' => ['type' => 'string', 'description' => 'Short reason in Turkish, shown to the administrator'],
                'domain' => ['type' => 'string', 'description' => 'The attacked site, if any'],
            ], 'required' => ['address', 'reason']]],
            ['name' => 'limit_site', 'description' => 'Put a temporary cap on a site\'s CPU (percent of one core, 25-200), memory (256-2048 MB) and/or PHP workers (2-6) for at most 120 minutes, to protect other sites from one that overloads the server. You may do this WITHOUT asking; the agent lifts it on time. If the settings do not allow it, it becomes a proposal.', 'input_schema' => ['type' => 'object', 'properties' => [
                'domain' => ['type' => 'string'],
                'cpu_percent' => ['type' => 'integer'],
                'memory_mb' => ['type' => 'integer'],
                'php_workers' => ['type' => 'integer'],
                'minutes' => ['type' => 'integer', 'description' => '15 to 120 (default 30)'],
                'reason' => ['type' => 'string', 'description' => 'Short reason in Turkish'],
            ], 'required' => ['domain', 'reason']]],
            ['name' => 'propose_action', 'description' => "Propose a change. The panel shows it as a card that the administrator applies (or cancels) with one click; nothing changes until then. Propose only what fixes the problem you found, one action per call. Actions:\n".
                collect(self::CATALOG)->map(fn ($d, $a) => "- {$a}: {$d}")->implode("\n"), 'input_schema' => ['type' => 'object', 'properties' => [
                    'action' => ['type' => 'string', 'enum' => array_keys(self::CATALOG)],
                    'reason' => ['type' => 'string', 'description' => 'Why, in one or two plain Turkish sentences, shown on the card'],
                    'domain' => ['type' => 'string'],
                    'address' => ['type' => 'string'],
                    'minutes' => ['type' => 'integer'],
                    'cpu_percent' => ['type' => 'integer'],
                    'memory_mb' => ['type' => 'integer'],
                    'php_workers' => ['type' => 'integer'],
                    'record' => $record,
                    'old_record' => $record,
                    'enabled' => ['type' => 'boolean'],
                    'ref' => ['type' => 'string'],
                    'deployment_number' => ['type' => 'integer'],
                    'worker' => ['type' => 'string'],
                    'processes' => ['type' => 'integer'],
                ], 'required' => ['action', 'reason']]],
        ];
    }

    /**
     * Handles block_ip, limit_site and propose_action calls.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: bool}
     */
    public function call(string $tool, array $input, string $id, AiConversation $conversation): array
    {
        if (! in_array($tool, ['block_ip', 'limit_site', 'propose_action'], true)) {
            return ["Unknown tool {$tool}.", true];
        }
        $action = $tool === 'propose_action' ? (string) ($input['action'] ?? '') : $tool;
        $reason = mb_substr(trim((string) ($input['reason'] ?? '')), 0, 500);
        try {
            if ($tool !== 'propose_action') {
                $input['minutes'] ??= $tool === 'block_ip' ? 60 : 30;
            }
            [$params, $title] = $this->validate($action, $input);
        } catch (MonitorException $e) {
            AiEvent::record('failed', __('Geçersiz istek (:action): :message', ['action' => $action, 'message' => $e->getMessage()]), $conversation, tool: $tool, data: $input);

            return [$e->getMessage(), true];
        }

        $auto = $tool !== 'propose_action' && $this->settings->autoActions() && $this->withinAiLimits($action, $params);
        $record = AiAction::create([
            'ai_conversation_id' => $conversation->id,
            'tool_use_id' => $id,
            'kind' => $auto ? 'auto' : 'proposal',
            'action' => $action,
            'params' => $params,
            'title' => $title,
            'reason' => $reason !== '' ? $reason : null,
            'status' => 'pending',
        ]);

        if (! $auto) {
            AiEvent::record('proposed', $title, $conversation, tool: $tool, data: $params);
            $why = $tool === 'propose_action' ? '' : ' (doing it without approval is '.($this->settings->autoActions() ? 'only allowed within 24 h blocks / 2 h limits' : 'turned off in the settings').')';

            return ["Proposal shown to the administrator as a card{$why}: \"{$title}\". Nothing has changed yet; it is applied only if they press Apply. Do not say it is done.", false];
        }

        try {
            $this->perform($record, null, $conversation);
        } catch (MonitorException|AgentException $e) {
            $message = self::message($e);
            $record->update(['status' => 'failed', 'error' => $message, 'decided_at' => now()]);
            AiEvent::record('failed', $title.': '.$message, $conversation, tool: $tool, data: $params);

            return ['Failed: '.$message, true];
        }
        $record->update(['status' => 'applied', 'decided_at' => now()]);
        AiEvent::record('auto', $title, $conversation, tool: $tool, data: $params);
        AuditLog::record(__(':title (onaysız, kendi sınırları içinde)', ['title' => $title]), $conversation->user, 'ai', [
            'actor' => 'Servik AI', 'section' => 'Servik AI', 'url' => route('ai.audit', [], false),
        ]);

        return ["Done without approval: \"{$title}\". The administrator sees it with an Undo button.", false];
    }

    /**
     * Applies a pending proposal on behalf of $user.
     *
     * @throws MonitorException when it cannot be applied
     */
    public function apply(AiAction $action, User $user): void
    {
        if (! $action->isPending()) {
            throw new MonitorException(__('Bu öneri zaten karara bağlandı.'));
        }
        if ($action->created_at->lt(now()->subHours(self::PROPOSAL_HOURS))) {
            throw new MonitorException(__('Bu önerinin süresi doldu; durum değişmiş olabilir. Servik AI\'ya yeniden sorun.'));
        }
        $conversation = $action->conversation;
        try {
            // Validated again: the server may have changed since.
            [$params] = $this->validate($action->action, $action->params);
            $action->params = $params;
            $this->perform($action, $user, $conversation);
        } catch (MonitorException|AgentException $e) {
            $message = self::message($e);
            $action->update(['status' => 'failed', 'error' => $message, 'user_id' => $user->id, 'decided_at' => now()]);
            AiEvent::record('failed', $action->title.': '.$message, $conversation, $user, $action->action);

            throw new MonitorException($message, previous: $e);
        }
        $action->update(['status' => 'applied', 'user_id' => $user->id, 'decided_at' => now()]);
        AiEvent::record('applied', $action->title, $conversation, $user, $action->action, $action->params);
    }

    public function reject(AiAction $action, User $user): void
    {
        if (! $action->isPending()) {
            return;
        }
        $action->update(['status' => 'rejected', 'user_id' => $user->id, 'decided_at' => now()]);
        AiEvent::record('rejected', $action->title, $action->conversation, $user, $action->action);
    }

    public function canUndo(AiAction $action): bool
    {
        return $action->status === 'applied' && $action->undo !== null
            && $action->decided_at?->gt(now()->subHours(self::UNDO_HOURS));
    }

    /**
     * Reverses an applied change.
     *
     * @throws MonitorException when it cannot be undone
     */
    public function undo(AiAction $action, User $user): void
    {
        if (! $this->canUndo($action)) {
            throw new MonitorException(__('Bu işlem geri alınamaz.'));
        }
        $u = $action->undo;
        try {
            match ($action->action) {
                'block_ip' => ($b = IpBlock::find($u['block'])) && $b->isActive() ? $this->blocks->unblock($b) : null,
                'unblock_ip' => $this->blocks->block($u['address'], $u['minutes'], $u['reason'] ?? '', Site::find($u['site']), $u['kind'] ?? null, $user),
                'limit_site', 'lift_limit' => $this->restoreLimit($u),
                'dns_add_record', 'dns_update_record', 'dns_delete_record' => $this->restoreZone($u),
                'https_redirect' => $this->certificates->setRedirect($this->site($u['domain']), (bool) $u['enabled']),
                'laravel_deploy', 'laravel_rollback' => $this->restoreRelease($u, $user),
                'laravel_scale_worker' => $this->scale(LaravelWorker::findOrFail($u['worker']), (int) $u['processes']),
                'mail_hold' => $this->queue->release($u['domain']),
                'mail_release' => $this->queue->hold($u['domain']),
            };
        } catch (MonitorException|AgentException $e) {
            throw new MonitorException(self::message($e), previous: $e);
        }
        $action->update(['status' => 'undone', 'undone_at' => now()]);
        AiEvent::record('undone', $action->title, $action->conversation, $user, $action->action);
    }

    /**
     * Lines showing what a change does, for the card's details.
     *
     * @return list<string>
     */
    public static function details(AiAction $action): array
    {
        $p = $action->params;
        $rec = fn (array $r) => DnsRecords::fqdn($r['name'], $p['domain']).' '.$r['type'].' '.(isset($r['priority']) ? $r['priority'].' ' : '').$r['content'].(isset($r['ttl']) ? ' (TTL '.$r['ttl'].')' : '');

        return match ($action->action) {
            'block_ip' => [__('+ engel :address · :duration', ['address' => $p['address'], 'duration' => $p['minutes'] ? __(IpBlocks::DURATIONS[$p['minutes']] ?? ':m dk', ['m' => $p['minutes']]) : __('kalıcı')])],
            'unblock_ip' => [__('− engel :address', ['address' => $p['address']])],
            'limit_site' => [__('+ sınır :domain · :p · :minutes dk', ['domain' => $p['domain'], 'p' => self::limitSummary($p), 'minutes' => $p['minutes']])],
            'lift_limit' => [__('− sınır :domain', ['domain' => $p['domain']])],
            'dns_add_record' => ['+ '.$rec($p['record'])],
            'dns_update_record' => ['− '.$rec($p['old_record']), '+ '.$rec($p['record'])],
            'dns_delete_record' => ['− '.$rec($p['old_record'])],
            'https_redirect' => [($p['enabled'] ? '+ ' : '− ').'return 301 https://$host$request_uri;'],
            'laravel_scale_worker' => [__(':worker: :from → :processes süreç', ['worker' => $p['worker'], 'from' => $p['from'], 'processes' => $p['processes']])],
            default => [],
        };
    }

    /**
     * Whether the action can be undone once applied.
     */
    public static function undoable(string $action): bool
    {
        return ! in_array($action, ['restart_php', 'ssl_renew', 'laravel_restart_workers', 'mail_flush'], true);
    }

    // --- Validation -----------------------------------------------------

    /**
     * Checks an action's input and returns its normalized params and the
     * card's title, written by the panel rather than by the model.
     *
     * @param  array<string, mixed>  $in
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws MonitorException
     */
    public function validate(string $action, array $in): array
    {
        $domain = Str::lower(trim((string) ($in['domain'] ?? '')));

        switch ($action) {
            case 'block_ip':
                $address = IpBlocks::normalize((string) ($in['address'] ?? ''));
                $minutes = (int) ($in['minutes'] ?? 0);
                if ($minutes < 0 || $minutes > 525600) {
                    throw new MonitorException('minutes must be 0 (permanent) to 525600.');
                }
                $site = $domain !== '' ? Site::where('domain', $domain)->first() : null;
                $title = $minutes === 0
                    ? __(':address adresini kalıcı olarak engelle', ['address' => $address])
                    : __(':address adresini :duration engelle', ['address' => $address, 'duration' => self::duration($minutes)]);

                return [['address' => $address, 'minutes' => $minutes, 'domain' => $site?->domain], $title];

            case 'unblock_ip':
                $address = IpBlocks::normalize((string) ($in['address'] ?? ''));
                IpBlock::active()->where('address', $address)->exists() || throw new MonitorException("{$address} is not blocked.");

                return [['address' => $address], __(':address engelini kaldır', ['address' => $address])];

            case 'limit_site':
                $site = $this->site($domain);
                $p = ['domain' => $site->domain, 'cpu' => (int) ($in['cpu_percent'] ?? $in['cpu'] ?? 0) ?: null,
                    'memory' => (int) ($in['memory_mb'] ?? $in['memory'] ?? 0) ?: null, 'php' => (int) ($in['php_workers'] ?? $in['php'] ?? 0) ?: null,
                    'minutes' => (int) ($in['minutes'] ?? 0)];
                if ($p['cpu'] === null && $p['memory'] === null && $p['php'] === null) {
                    throw new MonitorException('Give at least one of cpu_percent, memory_mb, php_workers.');
                }
                if (($p['cpu'] !== null && ($p['cpu'] < 25 || $p['cpu'] > 400)) || ($p['memory'] !== null && ($p['memory'] < 256 || $p['memory'] > 8192))
                    || ($p['php'] !== null && ($p['php'] < 1 || $p['php'] > 50))) {
                    throw new MonitorException('cpu_percent 25-400, memory_mb 256-8192, php_workers 1-50.');
                }
                if ($p['minutes'] < 5 || $p['minutes'] > ResourceLimits::MAX_MINUTES) {
                    throw new MonitorException('minutes must be 5 to '.ResourceLimits::MAX_MINUTES.'.');
                }

                return [$p, __(':domain sitesini :duration sınırla (:summary)', ['domain' => $site->domain, 'duration' => self::duration($p['minutes']), 'summary' => self::limitSummary($p)])];

            case 'lift_limit':
                $site = $this->site($domain);
                $site->activeLimit() ?? throw new MonitorException("{$site->domain} has no active limit.");

                return [['domain' => $site->domain], __(':domain sitesinin geçici sınırını kaldır', ['domain' => $site->domain])];

            case 'restart_php':
                $site = $this->site($domain);

                return [['domain' => $site->domain], __(':domain için PHP işçilerini yeniden başlat', ['domain' => $site->domain])];

            case 'dns_add_record':
            case 'dns_update_record':
            case 'dns_delete_record':
                return $this->validateDns($action, $domain, $in);

            case 'ssl_renew':
                $site = $this->site($domain);

                return [['domain' => $site->domain], __(':domain için Let\'s Encrypt sertifikası al', ['domain' => $site->domain])];

            case 'https_redirect':
                $site = $this->site($domain);
                if (! array_key_exists('enabled', $in)) {
                    throw new MonitorException('enabled is required.');
                }
                $on = (bool) $in['enabled'];
                if ($on && ! $site->hasHttps()) {
                    throw new MonitorException("{$site->domain} has no installed certificate; redirecting to HTTPS would break it.");
                }

                return [['domain' => $site->domain, 'enabled' => $on], $on ? __(":domain için HTTP'den HTTPS'e yönlendirmeyi aç", ['domain' => $site->domain]) : __(":domain için HTTP'den HTTPS'e yönlendirmeyi kapat", ['domain' => $site->domain])];

            case 'laravel_deploy':
                $app = $this->app($domain);
                $app->canDeploy() || throw new MonitorException($app->repository === null
                    ? 'This app is not connected to a Git repository.' : 'A deployment is already running or the app is busy.');
                $ref = trim((string) ($in['ref'] ?? '')) ?: $app->branch;
                if ($ref !== null && ! preg_match('#^[A-Za-z0-9._/-]{1,200}$#', $ref)) {
                    throw new MonitorException('ref is not a valid branch or tag name.');
                }

                return [['domain' => $app->site->domain, 'ref' => $ref], $ref ? __(':domain uygulamasını :ref dalından yeniden dağıt', ['domain' => $app->site->domain, 'ref' => $ref]) : __(':domain uygulamasını yeniden dağıt', ['domain' => $app->site->domain])];

            case 'laravel_rollback':
                $app = $this->app($domain);
                $number = (int) ($in['deployment_number'] ?? 0);
                $to = $app->deployments()->where('number', $number)->first();
                if ($to === null || $to->release === null) {
                    throw new MonitorException("Deployment #{$number} has no release to return to.");
                }
                if ($to->release === $app->current_release) {
                    throw new MonitorException("#{$number} is already live.");
                }
                $app->hasRunningDeployment() && throw new MonitorException('A deployment is running now.');

                return [['domain' => $app->site->domain, 'deployment_number' => $number], __(':domain uygulamasını #:number sürümüne geri döndür', ['domain' => $app->site->domain, 'number' => $number])];

            case 'laravel_restart_workers':
                $app = $this->app($domain);

                return [['domain' => $app->site->domain], __(':domain kuyruk işçilerini yeniden başlat', ['domain' => $app->site->domain])];

            case 'laravel_scale_worker':
                $app = $this->app($domain);
                $worker = $app->workers()->where('name', (string) ($in['worker'] ?? ''))->first()
                    ?? throw new MonitorException('No worker named "'.($in['worker'] ?? '').'". Workers: '.$app->workers()->pluck('name')->implode(', '));
                $n = (int) ($in['processes'] ?? -1);
                if ($n < 0 || $n > self::MAX_WORKER_PROCESSES) {
                    throw new MonitorException('processes must be 0 to '.self::MAX_WORKER_PROCESSES.'.');
                }

                return [['domain' => $app->site->domain, 'worker' => $worker->name, 'processes' => $n, 'from' => $worker->processes],
                    $n > $worker->processes
                        ? __(':domain :worker işçisini :n sürece çıkar', ['domain' => $app->site->domain, 'worker' => $worker->name, 'n' => $n])
                        : __(':domain :worker işçisini :n sürece indir', ['domain' => $app->site->domain, 'worker' => $worker->name, 'n' => $n])];

            case 'mail_hold':
            case 'mail_release':
                if ($domain === '' || ! preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/', $domain)) {
                    throw new MonitorException('domain must be the sender domain.');
                }

                return [['domain' => $domain], $action === 'mail_hold' ? __(':domain kaynaklı kuyruktaki postaları beklet', ['domain' => $domain]) : __(':domain için bekletilen postaları serbest bırak', ['domain' => $domain])];

            case 'mail_flush':
                return [[], __('Bekleyen tüm postaları şimdi yeniden dene')];
        }

        throw new MonitorException("Unknown action \"{$action}\".");
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws MonitorException
     */
    private function validateDns(string $action, string $domain, array $in): array
    {
        $zone = DnsZone::with('records')->where('name', $domain)->first()
            ?? throw new MonitorException("No DNS zone {$domain} is hosted here.");
        if ($zone->status === ZoneStatus::Deleting) {
            throw new MonitorException('The zone is being deleted.');
        }
        $others = $zone->records->map(fn (DnsRecord $r) => $r->toRecord())->all();
        $p = ['domain' => $zone->name];

        if ($action !== 'dns_add_record') {
            $old = self::record((array) ($in['old_record'] ?? []), $zone, partial: true);
            $match = array_values(array_filter($others, fn ($r) => $r['name'] === $old['name'] && $r['type'] === $old['type']
                && $r['content'] === $old['content'] && ($old['priority'] === null || $r['priority'] === $old['priority'])));
            if (count($match) !== 1) {
                throw new MonitorException('old_record matches '.count($match).' records; give its exact name, type and content (see get_dns_zone).');
            }
            $p['old_record'] = $match[0];
            $others = array_values(array_filter($others, fn ($r) => $r !== $match[0]));
        }
        if ($action !== 'dns_delete_record') {
            $new = self::record((array) ($in['record'] ?? []), $zone);
            if ($new['type'] === 'CNAME' && $new['name'] === '@') {
                throw new MonitorException('A CNAME cannot be at the zone apex.');
            }
            foreach ($others as $o) {
                if ($o['name'] !== $new['name']) {
                    continue;
                }
                if ($o['type'] === $new['type'] && $o['content'] === $new['content'] && $o['priority'] === $new['priority']) {
                    throw new MonitorException('That record already exists.');
                }
                if ($new['type'] === 'CNAME' || $o['type'] === 'CNAME') {
                    throw new MonitorException('A CNAME must be the only record of its name.');
                }
            }
            $p['record'] = $new;
        }
        $label = fn (array $r) => DnsRecords::fqdn($r['name'], $zone->name).' '.$r['type'];

        return [$p, match ($action) {
            'dns_add_record' => __(':name bölgesine :record kaydı ekle', ['name' => $zone->name, 'record' => $label($p['record'])]),
            'dns_update_record' => __(':name bölgesinde :old_record kaydını değiştir', ['name' => $zone->name, 'old_record' => $label($p['old_record'])]),
            'dns_delete_record' => __(':name bölgesinden :old_record kaydını sil', ['name' => $zone->name, 'old_record' => $label($p['old_record'])]),
        }];
    }

    /**
     * @param  array<string, mixed>  $in
     * @return array{name: string, type: string, content: string, priority: ?int, ttl: int}
     *
     * @throws MonitorException
     */
    private static function record(array $in, DnsZone $zone, bool $partial = false): array
    {
        $type = strtoupper(trim((string) ($in['type'] ?? '')));
        if (! in_array($type, DnsRecords::TYPES, true)) {
            throw new MonitorException('Record type must be one of '.implode(', ', DnsRecords::TYPES).'.');
        }
        $name = DnsRecords::normalizeName((string) ($in['name'] ?? '@'), $zone->name) ?? throw new MonitorException('Invalid record name.');
        [$content, $error] = DnsRecords::normalizeContent($type, (string) ($in['content'] ?? ''), $zone->name);
        if ($error !== null) {
            throw new MonitorException('Invalid content: '.$error);
        }
        $withPriority = in_array($type, DnsRecords::WITH_PRIORITY, true);
        $priority = $withPriority && isset($in['priority']) ? (int) $in['priority'] : null;
        if ($withPriority && ! $partial && ($priority === null || ! DnsRecords::isValidPriority($priority))) {
            throw new MonitorException('priority (0-65535) is required for '.$type.'.');
        }
        $ttl = (int) ($in['ttl'] ?? $zone->default_ttl);
        if ($ttl < DnsRecords::MIN_TTL || $ttl > DnsRecords::MAX_TTL) {
            throw new MonitorException('ttl must be '.DnsRecords::MIN_TTL.' to '.DnsRecords::MAX_TTL.'.');
        }

        return ['name' => $name, 'type' => $type, 'content' => $content, 'priority' => $priority, 'ttl' => $ttl];
    }

    /**
     * True when Servik AI may apply the change by itself.
     *
     * @param  array<string, mixed>  $p
     */
    private function withinAiLimits(string $action, array $p): bool
    {
        return match ($action) {
            'block_ip' => $p['minutes'] >= 1 && $p['minutes'] <= IpBlocks::AI_MAX_MINUTES,
            'limit_site' => $p['minutes'] <= ResourceLimits::AI_MAX_MINUTES
                && ($p['cpu'] === null || $p['cpu'] <= 200) && ($p['memory'] === null || $p['memory'] <= 2048) && ($p['php'] === null || $p['php'] <= 6),
            default => false,
        };
    }

    // --- Applying -------------------------------------------------------

    /**
     * Makes the change. $user is the person who approved it; null when the
     * AI acts by itself.
     *
     * @throws MonitorException|AgentException
     */
    private function perform(AiAction $action, ?User $user, AiConversation $conversation): void
    {
        $p = $action->params;
        $source = $user === null ? 'ai' : 'panel';
        $reason = Str::limit('Servik AI: '.($action->reason ?? $action->title), 200, '…');
        $undo = null;

        switch ($action->action) {
            case 'block_ip':
                $site = $p['domain'] ? Site::where('domain', $p['domain'])->first() : null;
                $protect = array_filter([$conversation->client_ip]);
                $block = $this->blocks->block($p['address'], $p['minutes'] ?: null, $reason, $site, null, $user, $source, $protect);
                $undo = ['block' => $block->id];
                break;

            case 'unblock_ip':
                $block = IpBlock::active()->where('address', $p['address'])->latest('id')->firstOrFail();
                $this->blocks->unblock($block);
                $undo = ['address' => $block->address, 'reason' => $block->reason, 'site' => $block->site_id, 'kind' => $block->kind,
                    'minutes' => $block->expires_at ? max(1, (int) ceil(now()->diffInMinutes($block->expires_at))) : null];
                break;

            case 'limit_site':
                $site = $this->site($p['domain']);
                $previous = $site->activeLimit();
                $limit = $this->limits->apply($site, $p['cpu'], $p['memory'], $p['php'], $p['minutes'], $reason, $user, $source);
                $undo = ['limit' => $limit->id, 'previous' => $previous?->only(['site_id', 'cpu', 'memory_mb', 'php_workers', 'reason', 'source', 'user_id', 'expires_at'])];
                break;

            case 'lift_limit':
                $limit = $this->site($p['domain'])->activeLimit() ?? throw new MonitorException(__('Sitenin etkin bir sınırı yok.'));
                $this->limits->lift($limit);
                $undo = ['limit' => null, 'previous' => $limit->only(['site_id', 'cpu', 'memory_mb', 'php_workers', 'reason', 'source', 'user_id', 'expires_at'])];
                break;

            case 'restart_php':
                $this->limits->restartPhp($this->site($p['domain']));
                break;

            case 'dns_add_record':
            case 'dns_update_record':
            case 'dns_delete_record':
                $zone = DnsZone::where('name', $p['domain'])->firstOrFail();
                $before = $zone->records()->get()->map(fn (DnsRecord $r) => $r->toRecord())->all();
                $this->zones->change($zone, function (DnsZone $zone) use ($p) {
                    if (isset($p['old_record'])) {
                        $o = $p['old_record'];
                        $zone->records()->where('name', $o['name'])->where('type', $o['type'])->where('content', $o['content'])
                            ->when($o['priority'] !== null, fn ($q) => $q->where('priority', $o['priority']))->limit(1)->get()->each->delete();
                    }
                    if (isset($p['record'])) {
                        $zone->records()->create($p['record']);
                    }
                });
                $undo = ['zone' => $zone->id, 'records' => $before, 'after' => self::recordsHash($zone)];
                break;

            case 'ssl_renew':
                $site = $this->site($p['domain']);
                $pending = $site->pendingCertificate();
                $current = $site->certificate;
                match (true) {
                    $pending !== null && $pending->isLetsEncrypt() => $this->certificates->reissue($pending),
                    $current !== null && $current->isLetsEncrypt() => $this->certificates->reissue($current),
                    default => $this->certificates->letsEncrypt($site),
                };
                break;

            case 'https_redirect':
                $site = $this->site($p['domain']);
                $undo = ['domain' => $site->domain, 'enabled' => (bool) $site->https_redirect];
                $this->certificates->setRedirect($site, (bool) $p['enabled']);
                break;

            case 'laravel_deploy':
                $app = $this->app($p['domain']);
                $live = $app->deployments()->where('release', $app->current_release)->whereNotNull('release')->latest('number')->first();
                $deployment = $this->apps->deploy($app, $p['ref'], $user, triggeredBy: $user?->name ? __(':name (Servik AI önerisi)', ['name' => $user->name]) : 'Servik AI');
                $undo = ['app' => $app->id, 'deployment' => $deployment->id, 'previous' => $live?->id];
                break;

            case 'laravel_rollback':
                $app = $this->app($p['domain']);
                $to = $app->deployments()->where('number', $p['deployment_number'])->firstOrFail();
                $live = $app->deployments()->where('release', $app->current_release)->whereNotNull('release')->latest('number')->first();
                $deployment = $this->apps->rollback($app, $to, $user);
                $undo = ['app' => $app->id, 'deployment' => $deployment->id, 'previous' => $live?->id];
                break;

            case 'laravel_restart_workers':
                $app = $this->app($p['domain']);
                $this->agent->call('laravel.queue.restart', $app->target(), config('servik.agent.job_timeout'));
                break;

            case 'laravel_scale_worker':
                $app = $this->app($p['domain']);
                $worker = $app->workers()->where('name', $p['worker'])->firstOrFail();
                $undo = ['worker' => $worker->id, 'processes' => $worker->processes];
                $this->scale($worker, (int) $p['processes']);
                break;

            case 'mail_hold':
                $this->queue->hold($p['domain']);
                $undo = ['domain' => $p['domain']];
                break;

            case 'mail_release':
                $this->queue->release($p['domain']);
                $undo = ['domain' => $p['domain']];
                break;

            case 'mail_flush':
                $this->queue->flush();
                break;

            default:
                throw new MonitorException(__('Bilinmeyen işlem.'));
        }
        $action->undo = $undo;
        $action->save();
    }

    // --- Undo helpers ---------------------------------------------------

    /**
     * @param  array<string, mixed>  $u
     */
    private function restoreLimit(array $u): void
    {
        if ($u['limit'] !== null && ($limit = ResourceLimit::with('site')->find($u['limit'])) && $limit->isActive()) {
            $this->limits->lift($limit);
        }
        $p = $u['previous'] ?? null;
        if ($p !== null && ($site = Site::find($p['site_id'])) && ($minutes = (int) ceil(now()->diffInMinutes($p['expires_at'], false))) > 0) {
            $this->limits->apply($site, $p['cpu'], $p['memory_mb'], $p['php_workers'], $minutes, (string) $p['reason'],
                User::find($p['user_id']), $p['source'] === 'ai' && $minutes > ResourceLimits::AI_MAX_MINUTES ? 'panel' : $p['source']);
        }
    }

    /**
     * @param  array<string, mixed>  $u
     *
     * @throws MonitorException
     */
    private function restoreZone(array $u): void
    {
        $zone = DnsZone::find($u['zone']) ?? throw new MonitorException(__('DNS bölgesi artık yok.'));
        if (self::recordsHash($zone) !== $u['after']) {
            throw new MonitorException(__('Bölgenin kayıtları o zamandan beri değişti; geri almak sonraki değişiklikleri de silerdi. Kaydı DNS sayfasından düzeltin.'));
        }
        $this->zones->replaceRecords($zone, $u['records']);
    }

    /**
     * @param  array<string, mixed>  $u
     *
     * @throws MonitorException
     */
    private function restoreRelease(array $u, User $user): void
    {
        $app = LaravelApp::find($u['app']) ?? throw new MonitorException(__('Uygulama artık yok.'));
        $started = LaravelDeployment::find($u['deployment']);
        if ($started !== null && $started->status->isBusy() && $started->started_at === null) {
            // Not started yet: it simply does not run.
            $started->delete();

            return;
        }
        $previous = $u['previous'] ? LaravelDeployment::find($u['previous']) : null;
        if ($previous === null || $previous->release === null) {
            throw new MonitorException(__('Dönülecek önceki sürüm bulunamadı.'));
        }
        if ($app->hasRunningDeployment()) {
            throw new MonitorException(__('Şu an bir dağıtım çalışıyor; bitince yeniden deneyin.'));
        }
        $this->apps->rollback($app, $previous, $user);
    }

    private function scale(LaravelWorker $worker, int $processes): void
    {
        $worker->update(['processes' => $processes]);
        $this->apps->syncProcesses($worker->app);
    }

    // --- Lookups and labels ----------------------------------------------

    /**
     * @throws MonitorException
     */
    private function site(string $domain): Site
    {
        return Site::with('systemUser', 'certificate', 'certificates')->where('domain', Str::lower(trim($domain)))->first()
            ?? throw new MonitorException("No site named \"{$domain}\".");
    }

    /**
     * @throws MonitorException
     */
    private function app(string $domain): LaravelApp
    {
        $site = $this->site($domain);

        return $site->laravelApp ?? throw new MonitorException("{$site->domain} has no Laravel application.");
    }

    private static function recordsHash(DnsZone $zone): string
    {
        return sha1(json_encode($zone->records()->orderBy('id')->get()->map(fn (DnsRecord $r) => $r->toRecord())->all()));
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private static function limitSummary(array $p): string
    {
        return (new ResourceLimit(['cpu' => $p['cpu'], 'memory_mb' => $p['memory'], 'php_workers' => $p['php']]))->summary();
    }

    private static function duration(int $minutes): string
    {
        return match (true) {
            $minutes % 1440 === 0 => trans_choice(':count gün', intdiv($minutes, 1440)),
            $minutes % 60 === 0 => trans_choice(':count saat', intdiv($minutes, 60)),
            default => trans_choice(':count dakika', $minutes),
        };
    }

    private static function message(MonitorException|AgentException $e): string
    {
        return match (true) {
            $e instanceof AgentUnavailableException => __('Sunucu ajanına ulaşılamıyor.'),
            $e instanceof AgentException => $e->reason,
            default => $e->getMessage(),
        };
    }
}
