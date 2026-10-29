<?php

namespace App\Models;

use App\Models\Concerns\SyncsMailSenders;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A redirect of a site: a whole domain (and its www) sent to an address,
 * or a path on the site's own names sent elsewhere.
 */
#[Fillable(['site_id', 'kind', 'source', 'with_www', 'target', 'code', 'keep_path'])]
class SiteRedirect extends Model
{
    use SyncsMailSenders;

    /** Changes to these alter the domains the site may send mail as. */
    protected const SENDER_FIELDS = ['site_id', 'kind', 'source', 'with_www'];

    public const DOMAIN = 'domain';

    public const PATH = 'path';

    public const CODES = [
        301 => 'Kalıcı (301)',
        302 => 'Geçici (302)',
    ];

    protected $attributes = [
        'with_www' => false,
        'code' => 301,
        'keep_path' => true,
    ];

    protected function casts(): array
    {
        return [
            'with_www' => 'boolean',
            'keep_path' => 'boolean',
            'code' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isDomain(): bool
    {
        return $this->kind === self::DOMAIN;
    }

    /**
     * Host names a domain redirect answers to.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        if (! $this->isDomain()) {
            return [];
        }

        return $this->with_www ? [$this->source, 'www.'.$this->source] : [$this->source];
    }

    /**
     * Parameters for the agent's site.apply redirects.
     *
     * @return array<string, mixed>
     */
    public function agentSpec(): array
    {
        return [
            ...($this->isDomain() ? ['hosts' => $this->hosts()] : ['path' => $this->source]),
            'target' => $this->target,
            'code' => $this->code,
            'keep_path' => $this->keep_path,
        ];
    }
}
