<?php

namespace App\Console\Commands;

use App\Models\WordPressInstall;
use App\Services\Sites\WordPressSites;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler: reads every managed WordPress again, so the
 * site page shows waiting updates without anyone opening it. Nothing is
 * updated.
 */
#[Signature('serfex:wordpress-check')]
#[Description('Check managed WordPress sites for updates')]
class CheckWordPress extends Command
{
    public function handle(WordPressSites $wordpress): int
    {
        $failed = 0;
        WordPressInstall::with('site.systemUser')->where('status', WordPressInstall::ACTIVE)->each(function (WordPressInstall $install) use ($wordpress, &$failed) {
            if ($install->hasRunningOperation()) {
                return;
            }
            $error = $wordpress->refresh($install);
            if ($error !== null) {
                $failed++;
                $this->warn($install->site->domain.': '.$error);
            }
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
