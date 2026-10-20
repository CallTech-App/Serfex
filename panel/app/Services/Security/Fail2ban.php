<?php

namespace App\Services\Security;

use App\Models\IpBlock;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Components\Components;
use Illuminate\Support\Carbon;

/**
 * Fail2ban's bans, shown as IP blocks: Fail2ban bans addresses on its own
 * (SSH, mail and panel logins); the panel copies its bans into the same
 * list as Canlı İzleme's blocks (source "fail2ban", kind = jail), where
 * they can be lifted and the lifting undone.
 */
class Fail2ban
{
    /** Jails and what they protect. */
    public const JAILS = [
        'sshd' => 'SSH girişi',
        'servik-panel' => 'Panel girişi',
        'dovecot' => 'E-posta girişi (IMAP/POP3)',
        'postfix-sasl' => 'E-posta gönderme girişi',
        'recidive' => 'Tekrar eden saldırgan',
    ];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Components $components,
    ) {}

    public static function jailLabel(?string $jail): string
    {
        return self::JAILS[$jail] ?? (string) $jail;
    }

    /**
     * fail2ban.status: installed, running and each jail's bans.
     *
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function status(): array
    {
        return (array) $this->agent->call('fail2ban.status', [], 30);
    }

    /**
     * Copies Fail2ban's current bans into the IP block list: new bans are
     * added, bans Fail2ban lifted are closed.
     *
     * @throws AgentException
     */
    public function sync(): int
    {
        if (! $this->components->isInstalled('fail2ban') && ! IpBlock::active()->where('source', 'fail2ban')->exists()) {
            return 0;
        }
        $status = $this->status();
        $seen = [];
        foreach (($status['running'] ?? false) ? $status['jails'] ?? [] : [] as $jail) {
            foreach ($jail['banned'] ?? [] as $ban) {
                $address = (string) $ban['address'];
                $seen[] = $jail['name'].'|'.$address;
                $until = isset($ban['until']) ? Carbon::parse($ban['until']) : null;
                $block = IpBlock::active()->where('source', 'fail2ban')->where('kind', $jail['name'])->where('address', $address)->first();
                if ($block === null) {
                    IpBlock::create([
                        'address' => $address, 'source' => 'fail2ban', 'kind' => $jail['name'],
                        'reason' => __('Fail2ban: :name için çok sayıda hatalı deneme', ['name' => self::jailLabel($jail['name'])]),
                        'expires_at' => $until,
                    ]);
                } elseif ($until !== null && ! $until->equalTo($block->expires_at)) {
                    $block->update(['expires_at' => $until]);
                }
            }
        }
        $gone = 0;
        foreach (IpBlock::active()->where('source', 'fail2ban')->get() as $block) {
            if (! in_array($block->kind.'|'.$block->address, $seen, true)) {
                $block->update(['removed_at' => now()]);
                $gone++;
            }
        }

        return count($seen);
    }
}
