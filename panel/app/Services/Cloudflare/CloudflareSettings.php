<?php

namespace App\Services\Cloudflare;

use App\Models\Setting;

/**
 * Server-wide choices for zones at Cloudflare.
 */
class CloudflareSettings
{
    /**
     * Whether the addresses of sites (the domain, www and other names a
     * site answers to) go through Cloudflare's proxy when Serfex writes
     * them. Mail, webmail and other hosts never do.
     */
    public function proxySites(): bool
    {
        return (bool) Setting::get('cloudflare.proxy_sites', true);
    }

    /**
     * Whether Serfex sets a zone's SSL/TLS mode to Full (strict) once the
     * site's certificate is in place.
     */
    public function strictSsl(): bool
    {
        return (bool) Setting::get('cloudflare.strict_ssl', true);
    }

    /**
     * Whether a Laravel deployment purges the site's pages from
     * Cloudflare's cache.
     */
    public function purgeAfterDeploy(): bool
    {
        return (bool) Setting::get('cloudflare.purge_after_deploy', false);
    }

    /**
     * @param  array{proxy_sites?: bool, strict_ssl?: bool, purge_after_deploy?: bool}  $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::put('cloudflare.'.$key, (bool) $value);
        }
    }
}
