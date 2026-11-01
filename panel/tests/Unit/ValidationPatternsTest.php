<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A `regex:` rule whose pattern does not compile makes PHP throw inside the
 * validator, so the administrator gets a 500 instead of a message. Every
 * pattern written as a literal in app/ must compile.
 */
class ValidationPatternsTest extends TestCase
{
    public function test_every_literal_regex_rule_compiles(): void
    {
        $app = dirname(__DIR__, 2).'/app';
        $checked = 0;
        $broken = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("/'(?:not_)?regex:((?:[^'\\\\]|\\\\.)+)'/", $source, $matches);
            foreach ($matches[1] as $literal) {
                // Undo PHP single-quoted string escaping.
                $pattern = strtr($literal, ['\\\\' => '\\', "\\'" => "'"]);
                $checked++;
                $error = null;
                set_error_handler(function (int $no, string $message) use (&$error): bool {
                    $error = $message;

                    return true;
                });
                $result = preg_match($pattern, '');
                restore_error_handler();
                if ($result === false || $error !== null) {
                    $broken[] = substr($file->getPathname(), strlen($app) + 1).': '.$pattern.' ('.($error ?? preg_last_error_msg()).')';
                }
            }
        }

        $this->assertGreaterThan(30, $checked);
        $this->assertSame([], $broken);
    }
}
