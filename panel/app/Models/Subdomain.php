<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A subdomain of a site (blog.example.com): served from its own document
 * root inside the site folder, with the site's PHP and as its user.
 */
#[Fillable(['site_id', 'name', 'web_root'])]
class Subdomain extends Model
{
    /** Folder new subdomains get, below the site folder. */
    public const FOLDER = 'subdomains';

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The part before the site's domain: "blog" for blog.example.com.
     */
    public function label(): string
    {
        $suffix = '.'.$this->site->domain;

        return str_ends_with($this->name, $suffix) ? substr($this->name, 0, -strlen($suffix)) : $this->name;
    }

    /**
     * True when the subdomain is served from a folder of the main site.
     */
    public function insideMainSite(): bool
    {
        return $this->web_root === Site::PUBLIC_HTML || str_starts_with($this->web_root, Site::PUBLIC_HTML.'/');
    }
}
