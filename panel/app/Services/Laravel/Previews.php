<?php

namespace App\Services\Laravel;

use App\Jobs\CreateLaravelPreview;
use App\Jobs\DeleteLaravelPreview;
use App\Models\LaravelApp;
use App\Models\LaravelPreview;
use App\Models\Site;
use App\Models\User;
use App\Services\Customers\Quotas;
use App\Support\Hostnames;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Preview environments: a branch or pull request of an application
 * deployed on <name>.<domain>, with its own site, .env and SQLite
 * database, removed when the pull request closes.
 */
class Previews
{
    /**
     * Values every preview's .env gets on top of a copy of the
     * application's: nothing it does reaches the application's users, its
     * queues, caches or sessions.
     */
    public const ENV = [
        'APP_ENV' => 'staging',
        'APP_KEY' => '',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'log',
        'CACHE_STORE' => 'file',
        'SESSION_DRIVER' => 'file',
        'SESSION_DOMAIN' => 'null',
        'BROADCAST_CONNECTION' => 'log',
        'SCOUT_DRIVER' => 'null',
    ];

    /**
     * The name of the preview of a pull request or branch: pr-212, or the
     * branch as a host name label.
     */
    public static function nameFor(string $branch, ?int $pr = null): string
    {
        if ($pr !== null) {
            return 'pr-'.$pr;
        }
        $slug = trim(substr(Str::slug(str_replace(['/', '_', '.'], '-', $branch)), 0, 40), '-');

        return $slug === '' ? 'preview' : $slug;
    }

    /**
     * Starts a preview of $branch. Opening the same pull request again
     * deploys its preview instead.
     *
     * @throws RuntimeException with a message for the user
     */
    public function create(LaravelApp $app, string $branch, ?User $user = null, ?int $pr = null, ?string $title = null, ?string $url = null, string $source = 'manual', ?string $by = null): LaravelPreview
    {
        $by ??= $source === 'manual' ? $user?->name : self::trigger($source);
        if ($app->isPreview() || ! $app->isManaged() || $app->repository === null) {
            throw new RuntimeException('Önizleme ortamı yalnızca Git deposundan dağıtılan uygulamalarda açılabilir.');
        }
        $name = self::nameFor($branch, $pr);
        $existing = $app->previews()->where('name', $name)->first();
        if ($existing !== null) {
            if ($existing->status === 'failed' && $existing->preview_app_id === null) {
                $existing->update(['status' => 'creating', 'error' => null, 'branch' => $branch]);
                CreateLaravelPreview::dispatch($existing);

                return $existing;
            }
            $this->redeploy($existing, $title, $by);

            return $existing;
        }
        if (($refusal = app(Quotas::class)->refusal($app->site->customer(), 'disk')) !== null) {
            throw new RuntimeException($refusal);
        }
        $domain = $name.'.'.$app->site->domain;
        if (! Hostnames::isValidDomain($domain) || strlen($domain) > 200) {
            throw new RuntimeException("{$domain} geçerli bir alan adı değil; daha kısa bir dal adı seçin.");
        }
        if (Site::where('domain', $domain)->exists()) {
            throw new RuntimeException("{$domain} zaten bir site; önizleme için başka bir ad gerekir.");
        }
        if ($app->previews()->where('status', '!=', 'deleting')->count() >= $app->preview_limit) {
            throw new RuntimeException("En fazla {$app->preview_limit} önizleme ortamı açılabilir; önce birini silin.");
        }
        $preview = $app->previews()->create([
            'name' => $name,
            'branch' => $branch,
            'pr_number' => $pr,
            'pr_title' => $title !== null ? Str::limit($title, 250) : null,
            'pr_url' => $url,
            'source' => in_array($source, ['manual', 'api'], true) ? $source : 'webhook',
            'status' => 'creating',
            'created_by' => $by,
        ]);
        CreateLaravelPreview::dispatch($preview);

        return $preview;
    }

    /**
     * Deploys the preview's branch again (a pull request got new commits).
     */
    public function redeploy(LaravelPreview $preview, ?string $title = null, ?string $by = null): void
    {
        if ($title !== null) {
            $preview->update(['pr_title' => Str::limit($title, 250)]);
        }
        $app = $preview->previewApp;
        if ($app === null || ! $app->canDeploy()) {
            return;
        }
        app(LaravelApps::class)->deploy($app, $preview->branch, triggeredBy: $by);
    }

    /**
     * Removes a preview: its application, site, certificate, DNS records
     * and database.
     */
    public function delete(LaravelPreview $preview): void
    {
        if ($preview->status === 'deleting') {
            return;
        }
        $preview->update(['status' => 'deleting']);
        DeleteLaravelPreview::dispatch($preview);
    }

    /**
     * The .env of a new preview: the application's, the preview values, and
     * the application's own preview overrides.
     *
     * @param  array<string, string>  $parent
     * @return array<string, string>
     */
    public static function env(LaravelApp $app, LaravelPreview $preview, Site $site, array $parent): array
    {
        $base = '/home/'.$site->systemUser->name.'/web/'.$site->domain.'/laravel';
        $env = array_merge($parent, self::ENV, [
            'APP_URL' => ($app->site->hasHttps() ? 'https://' : 'http://').$site->domain,
            'APP_NAME' => ($parent['APP_NAME'] ?? $app->site->domain).' ('.$preview->name.')',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $base.'/shared/database/database.sqlite',
            // Keys in a shared Redis or Memcached do not collide.
            'CACHE_PREFIX' => Str::slug($site->domain, '_').'_cache_',
            'REDIS_PREFIX' => Str::slug($site->domain, '_').'_',
        ]);
        foreach (EnvFile::values((string) $app->preview_env) as $key => $value) {
            $env[$key] = $value;
        }

        return array_map('strval', $env);
    }

    private static function trigger(string $source): string
    {
        return match ($source) {
            'github' => 'GitHub pull request',
            'gitlab' => 'GitLab merge request',
            'bitbucket' => 'Bitbucket pull request',
            default => 'Webhook',
        };
    }
}
