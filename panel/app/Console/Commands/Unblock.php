<?php

namespace App\Console\Commands;

use App\Models\IpBlock;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Audit\AuditLog;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\MonitorException;
use App\Services\Security\Fail2ban;
use App\Support\Locales;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Lets an address in again from the server's console: `sudo serfex
 * firewall unblock <address>`, for an administrator the firewall keeps
 * out of the panel. Lifts everything that holds the address: its own
 * block, a blocked range around it and Fail2ban's bans.
 */
#[Signature('serfex:unblock {address : The IP address or range to let in again} {--json : Print the result as JSON (serfex firewall unblock)}')]
#[Description('Lift every block that keeps an address out')]
class Unblock extends Command
{
    public function handle(IpBlocks $blocks, Fail2ban $fail2ban, AgentClient $agent): int
    {
        try {
            $address = IpBlocks::normalize((string) $this->argument('address'));
        } catch (MonitorException $e) {
            return $this->refuse($e->getMessage());
        }

        try {
            // The panel reads Fail2ban's bans once a minute: one made a
            // moment ago is not in its list yet.
            $fail2ban->sync();
        } catch (AgentException) {
            // The blocks the panel knows are still lifted below.
        }
        $held = IpBlock::active()->get()->filter(fn (IpBlock $b) => IpBlocks::covers($b->address, $address))->values();
        $name = fn (IpBlock $b) => $b->source === 'fail2ban' ? $b->address.' (Fail2ban: '.Locales::label(Fail2ban::jailLabel($b->kind)).')' : $b->address;
        try {
            $ids = $held->isEmpty() ? [] : $blocks->unblockMany($held, null, 'console');
            $lifted = $held->whereIn('id', $ids)->map($name)->values()->all();
            // The firewall may hold a block the panel has no row for (its
            // database was restored from a backup).
            if (! $held->where('source', '!=', 'fail2ban')->contains('address', $address)) {
                $found = (array) $agent->call('firewall.unblock.many', ['addresses' => [$address]], config('serfex.agent.job_timeout'));
                if ((int) ($found['removed'] ?? 0) > 0) {
                    $lifted[] = $address;
                }
            }
        } catch (AgentException $e) {
            return $this->refuse(__('Engel kaldırılamadı: :reason', ['reason' => $e->forPeople()]));
        }

        if ($lifted !== []) {
            AuditLog::record(__(':address adresinin engeli sunucu konsolundan kaldırıldı.', ['address' => $address]), null, 'system',
                ['actor' => __('Sunucu konsolu'), 'section' => __('Güvenlik'), 'url' => route('security.blocks', ['tab' => 'history'], false)]);
        }
        $left = $held->whereNotIn('id', $ids);
        if ($left->isNotEmpty()) {
            return $this->refuse(__(':address hâlâ engelli; kaldırılamayan: :blocks.', ['address' => $address, 'blocks' => $left->map($name)->implode(', ')]));
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['address' => $address, 'removed' => count($lifted), 'lifted' => $lifted], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        if ($lifted === []) {
            $this->components->info(__(':address engelli değil.', ['address' => $address]));

            return self::SUCCESS;
        }
        $this->components->info(__(':address adresinin engeli kaldırıldı.', ['address' => $address]));
        foreach ($lifted as $line) {
            $this->line('  '.$line);
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
