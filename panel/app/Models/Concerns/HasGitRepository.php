<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * For models with a Git repository address in $repository: which host it
 * is on, its owner/name and where its deploy keys are added.
 */
trait HasGitRepository
{
    /**
     * github, gitlab, bitbucket or null, from the repository address.
     */
    public function provider(): ?string
    {
        $host = $this->repositoryHost();
        foreach (['github', 'gitlab', 'bitbucket'] as $p) {
            if ($host !== null && str_contains($host, $p)) {
                return $p;
            }
        }

        return null;
    }

    public function providerLabel(): string
    {
        return match ($this->provider()) {
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'bitbucket' => 'Bitbucket',
            default => 'Git',
        };
    }

    /**
     * owner/name of the repository, for display.
     */
    public function repositoryName(): ?string
    {
        if ($this->repository === null) {
            return null;
        }
        $path = preg_replace('~^(?:https://[^/]+/|ssh://[^/]+/|[^@]+@[^:]+:)~', '', $this->repository);

        return Str::of($path)->trim('/')->beforeLast('.git')->value() ?: $this->repository;
    }

    private function repositoryHost(): ?string
    {
        if ($this->repository === null) {
            return null;
        }
        if (preg_match('~^(?:https://|ssh://[^@]+@)([^/:]+)~', $this->repository, $m) || preg_match('~^[^@]+@([^:]+):~', $this->repository, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Where the repository's deploy keys are added, when the provider is known.
     */
    public function deployKeysUrl(): ?string
    {
        $host = $this->repositoryHost();
        $name = $this->repositoryName();

        return match ($this->provider()) {
            'github' => "https://{$host}/{$name}/settings/keys",
            'gitlab' => "https://{$host}/{$name}/-/settings/repository",
            'bitbucket' => "https://{$host}/{$name}/admin/access-keys/",
            default => null,
        };
    }
}
