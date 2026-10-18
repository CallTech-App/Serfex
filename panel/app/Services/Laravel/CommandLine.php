<?php

namespace App\Services\Laravel;

use InvalidArgumentException;

/**
 * Splits a command typed in the panel into arguments, like a shell would
 * for quotes and backslashes, without running a shell: pipes, redirects
 * and variables are passed on as plain text.
 */
final class CommandLine
{
    /** The tools a command can run with, as the agent names them. */
    public const TOOLS = ['artisan', 'composer', 'npm', 'pnpm', 'yarn', 'bun'];

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException when a quote is not closed
     */
    public static function split(string $line): array
    {
        $args = [];
        $current = '';
        $inArg = false;
        $quote = null;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $c = $line[$i];
            if ($quote === "'") {
                if ($c === "'") {
                    $quote = null;
                } else {
                    $current .= $c;
                }

                continue;
            }
            if ($c === '\\' && $i + 1 < $length && ($quote === null || in_array($line[$i + 1], ['"', '\\', '$', '`'], true))) {
                $current .= $line[++$i];
                $inArg = true;

                continue;
            }
            if ($quote === '"') {
                if ($c === '"') {
                    $quote = null;
                } else {
                    $current .= $c;
                }

                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
                $inArg = true;

                continue;
            }
            if (ctype_space($c)) {
                if ($inArg) {
                    $args[] = $current;
                    $current = '';
                    $inArg = false;
                }

                continue;
            }
            $current .= $c;
            $inArg = true;
        }
        if ($quote !== null) {
            throw new InvalidArgumentException('Kapanmamış tırnak var.');
        }
        if ($inArg) {
            $args[] = $current;
        }

        return $args;
    }

    /**
     * How a tool is written before its arguments, as shown in the panel.
     */
    public static function prefix(string $tool): string
    {
        return $tool === 'artisan' ? 'php artisan' : $tool;
    }
}
