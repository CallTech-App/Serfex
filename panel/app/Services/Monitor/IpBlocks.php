<?php

namespace App\Services\Monitor;

use App\Models\IpBlock;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * Blocking addresses on the whole server (nftables, through the agent).
 *
 * This is one of the two actions Servik AI may take without asking, so
 * every caller goes through here: a person from the Canlı İzleme page, the
 * API, or the AI with source "ai", whose blocks must end within a day.
 */
class IpBlocks
{
    /** The longest block Servik AI may set on its own. */
    public const AI_MAX_MINUTES = 24 * 60;

    /** Durations offered in the panel, in minutes; null blocks until removed. */
    public const DURATIONS = [60 => '1 saat', 1440 => '24 saat', 10080 => '7 gün', 0 => 'Kalıcı'];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Blocks an address or prefix, or changes the block it already has.
     *
     * @param  int|null  $minutes  null or 0: until removed
     * @param  list<string>  $protect  addresses that must stay reachable, such as the caller's own
     *
     * @throws MonitorException when the address cannot be blocked
     * @throws AgentException
     */
    public function block(string $address, ?int $minutes, string $reason = '', ?Site $site = null, ?string $kind = null,
        ?User $by = null, string $source = 'panel', array $protect = []): IpBlock
    {
        $address = self::normalize($address);
        $minutes = $minutes ?: null;
        if ($minutes !== null && ($minutes < 1 || $minutes > 525600)) {
            throw new MonitorException('Süre 1 dakika ile 1 yıl arasında olmalı.');
        }
        if ($source === 'ai' && ($minutes === null || $minutes > self::AI_MAX_MINUTES)) {
            throw new MonitorException('Servik AI bir adresi en fazla 24 saat engelleyebilir.');
        }
        foreach ($protect as $own) {
            if ($own !== '' && self::contains($address, $own)) {
                throw new MonitorException("{$address} sizin bağlantınızın adresi; engellerseniz panele ulaşamazsınız.");
            }
        }

        try {
            $result = $this->agent->call('firewall.block', [
                'address' => $address,
                'seconds' => ($minutes ?? 0) * 60,
                'reason' => mb_substr($reason, 0, 200),
            ], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            if ($e->getCode() === -32602) {
                throw new MonitorException(self::agentMessage($e->getMessage()), previous: $e);
            }
            throw $e;
        }

        $data = [
            'address' => (string) ($result['address'] ?? $address),
            'reason' => $reason !== '' ? mb_substr($reason, 0, 200) : null,
            'site_id' => $site?->id,
            'kind' => $kind,
            'source' => $source,
            'user_id' => $by?->id,
            'expires_at' => $minutes ? now()->addMinutes($minutes) : null,
        ];
        $existing = IpBlock::active()->where('address', $data['address'])->latest('id')->first();
        if ($existing !== null) {
            $existing->update($data);

            return $existing;
        }

        return IpBlock::create($data);
    }

    /**
     * Lifts a block now.
     *
     * @throws AgentException
     */
    public function unblock(IpBlock $block): void
    {
        if ($block->isActive()) {
            $this->agent->call('firewall.unblock', ['address' => $block->address], config('servik.agent.job_timeout'));
        }
        $block->update(['removed_at' => $block->removed_at ?? now()]);
    }

    /**
     * Checks an address or prefix and writes it the way the agent does.
     *
     * @throws MonitorException
     */
    public static function normalize(string $address): string
    {
        $address = trim($address);
        [$ip, $bits] = array_pad(explode('/', $address, 2), 2, null);
        $packed = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($packed === false) {
            throw new MonitorException("\"{$address}\" bir IP adresi değil.");
        }
        $v4 = strlen($packed) === 4;
        $max = $v4 ? 32 : 128;
        if ($bits !== null && (! ctype_digit($bits) || (int) $bits > $max)) {
            throw new MonitorException("\"{$address}\" geçerli bir adres aralığı değil.");
        }
        $bits = $bits === null ? $max : (int) $bits;
        if ($bits < ($v4 ? 16 : 48)) {
            throw new MonitorException('Aralık çok geniş: en fazla /16 (IPv4) ya da /48 (IPv6) engellenebilir.');
        }
        $packed = self::mask($packed, $bits);
        if (self::contains('127.0.0.0/8', inet_ntop($packed)) || inet_ntop($packed) === '::1' || in_array(inet_ntop($packed), ['0.0.0.0', '::'], true)) {
            throw new MonitorException('Sunucunun kendisine ait yerel adresler engellenemez.');
        }
        $text = inet_ntop($packed);

        return $bits === $max ? $text : $text.'/'.$bits;
    }

    /**
     * True when $ip falls in $prefix (an address or a prefix).
     */
    public static function contains(string $prefix, string $ip): bool
    {
        [$net, $bits] = array_pad(explode('/', $prefix, 2), 2, null);
        $a = @inet_pton($net);
        $b = @inet_pton($ip);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;

        return self::mask($a, $bits) === self::mask($b, $bits);
    }

    private static function mask(string $packed, int $bits): string
    {
        $out = '';
        foreach (str_split($packed) as $i => $byte) {
            $keep = max(0, min(8, $bits - $i * 8));
            $out .= chr(ord($byte) & (0xFF << (8 - $keep)) & 0xFF);
        }

        return $out;
    }

    private static function agentMessage(string $message): string
    {
        return match (true) {
            str_contains($message, "this server's own address") => 'Bu, sunucunun kendi adresi; engellenemez.',
            str_contains($message, 'overlaps') => 'Bu adres zaten engellenmiş bir aralığın içinde.',
            default => 'Adres engellenemedi: '.$message,
        };
    }
}
