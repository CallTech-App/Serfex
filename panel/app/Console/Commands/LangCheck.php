<?php

namespace App\Console\Commands;

use App\Support\LangCheck as Checker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fails when lang/en.json misses a key the panel uses, keeps one nobody
 * uses, changes a key's placeholders, or a view has Turkish written into
 * its HTML. Runs in CI next to Pint.
 */
#[Signature('serfex:lang-check {--agent= : The agent\'s source folder, for labels the agent sends (default ../agent)} {--wanted : Only print the keys lang/en.json should have, as JSON}')]
#[Description('Check the panel\'s translations')]
class LangCheck extends Command
{
    public function handle(): int
    {
        $checker = new Checker(base_path(), $this->option('agent') ?? base_path('../agent'));
        if ($this->option('wanted')) {
            $this->line((string) json_encode($checker->wanted(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $result = $checker->run();
        $failed = false;
        foreach ($result['missing'] as $key => $where) {
            $this->line("missing  {$key}  (".implode(', ', array_slice($where, 0, 2)).')');
            $failed = true;
        }
        foreach ($result['unused'] as $key) {
            $this->line("unused   {$key}");
            $failed = true;
        }
        foreach ($result['placeholders'] as $problem) {
            $this->line("mismatch {$problem}");
            $failed = true;
        }
        foreach ($result['hard_coded'] as $text) {
            $this->line("text     {$text}");
            $failed = true;
        }
        if ($failed) {
            $this->error(sprintf('Translations: %d missing, %d unused, %d placeholder mismatches, %d Turkish texts in views.',
                count($result['missing']), count($result['unused']), count($result['placeholders']), count($result['hard_coded'])));

            return self::FAILURE;
        }
        $this->info('Translations are complete: '.count($checker->english()).' English lines.');

        return self::SUCCESS;
    }
}
