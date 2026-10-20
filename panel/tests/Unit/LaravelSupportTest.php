<?php

namespace Tests\Unit;

use App\Services\Laravel\CommandLine;
use App\Services\Laravel\EnvFile;
use App\Services\Laravel\LogParser;
use App\Services\Laravel\PhpConstraint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LaravelSupportTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool|null}>
     */
    public static function constraints(): array
    {
        return [
            'caret' => ['^8.3', '8.4', true],
            'caret too old' => ['^8.3', '8.2', false],
            'caret next major' => ['^8.2', '9.0', false],
            'alternatives' => ['^8.2|^8.3', '8.3', true],
            'double bar' => ['^7.4 || ^8.0', '8.5', true],
            'range' => ['>=8.2 <8.5', '8.5', false],
            'range ok' => ['>=8.2 <8.5', '8.4', true],
            'comma range' => ['>=8.1,<8.4', '8.3', true],
            'tilde minor' => ['~8.3', '8.5', true],
            'tilde patch' => ['~8.3.1', '8.4', false],
            'wildcard' => ['8.3.*', '8.3', true],
            'exact minor' => ['8.4', '8.3', false],
            'any' => ['*', '8.1', true],
            'unreadable' => ['dev-main', '8.4', null],
        ];
    }

    #[DataProvider('constraints')]
    public function test_php_constraints(string $constraint, string $version, ?bool $expected): void
    {
        $this->assertSame($expected, PhpConstraint::allows($constraint, $version));
    }

    public function test_command_lines_split_like_a_shell(): void
    {
        $this->assertSame(['migrate', '--force'], CommandLine::split('  migrate   --force '));
        $this->assertSame(['tinker', "--execute=echo config('app.name');"], CommandLine::split('tinker --execute="echo config(\'app.name\');"'));
        $this->assertSame(['make:model', 'Order Line'], CommandLine::split("make:model 'Order Line'"));
        $this->assertSame(['a b', 'c"d'], CommandLine::split('a\ b "c\"d"'));
        $this->assertSame(['run', 'build', '|', 'tee'], CommandLine::split('run build | tee'));
        $this->assertSame(['x', ''], CommandLine::split("x ''"));
        $this->assertSame([], CommandLine::split('   '));
    }

    public function test_unclosed_quotes_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommandLine::split('migrate --path="database');
    }

    public function test_env_values_are_read_like_phpdotenv(): void
    {
        $env = "# Uygulama\nAPP_NAME=\"Hızlı Kargo\"\nAPP_KEY=base64:abc=\nexport APP_DEBUG=false\nMAIL_FROM='bildirim@ornek.com' # yorum\nEMPTY=\nBROKEN LINE\nTOKEN=abc#notcomment\nNOTE=a #b\n";
        $this->assertSame([
            'APP_NAME' => 'Hızlı Kargo',
            'APP_KEY' => 'base64:abc=',
            'APP_DEBUG' => 'false',
            'MAIL_FROM' => 'bildirim@ornek.com',
            'EMPTY' => '',
            'TOKEN' => 'abc#notcomment',
            'NOTE' => 'a',
        ], EnvFile::values($env));
    }

    public function test_env_edits_keep_comments_and_order(): void
    {
        $env = "# Uygulama\nAPP_NAME=Laravel\nAPP_DEBUG=true\n\n# Veritabanı\nDB_CONNECTION=sqlite\n";
        $out = EnvFile::apply($env, ['APP_NAME' => 'Hızlı Kargo', 'DB_CONNECTION' => 'sqlite', 'MAIL_HOST' => 'smtp.ornek.com']);

        $this->assertSame("# Uygulama\nAPP_NAME='Hızlı Kargo'\n\n# Veritabanı\nDB_CONNECTION=sqlite\n\nMAIL_HOST=smtp.ornek.com\n", $out);
        $this->assertSame(['APP_NAME' => 'Hızlı Kargo', 'DB_CONNECTION' => 'sqlite', 'MAIL_HOST' => 'smtp.ornek.com'], EnvFile::values($out));
        // Unchanged values keep their original spelling.
        $this->assertSame("A=\"x\"\n", EnvFile::apply("A=\"x\"\n", ['A' => 'x']));
    }

    public function test_env_quoting_round_trips(): void
    {
        foreach (['plain', 'with space', "it's", "two\nlines", 'dollar $HOME', 'back\\slash "q"', ''] as $value) {
            $this->assertSame(['K' => $value], EnvFile::values('K='.EnvFile::quote($value)), $value);
        }
    }

    public function test_env_compare_and_summary(): void
    {
        $env = "APP_NAME=x\nSECRET_TOKEN=y\n";
        $example = "APP_NAME=\nAPP_URL=\n";
        $this->assertSame(['missing' => ['APP_URL'], 'extra' => ['SECRET_TOKEN']], EnvFile::compare($env, $example));
        $this->assertSame(['missing' => [], 'extra' => []], EnvFile::compare($env, null));

        $this->assertSame('APP_URL eklendi, SECRET_TOKEN silindi, APP_NAME değişti', EnvFile::summary($env, "APP_NAME=z\nAPP_URL=u\n"));
        $this->assertTrue(EnvFile::isSecret('DB_PASSWORD'));
        $this->assertTrue(EnvFile::isSecret('APP_KEY'));
        $this->assertFalse(EnvFile::isSecret('APP_URL'));
    }

    public function test_log_entries_newest_first_with_traces(): void
    {
        $log = <<<'LOG'
            trace line of an entry cut off above
            [2026-10-18 21:00:00] production.INFO: Kargo eşitlendi {"adet":12}
            [2026-10-18 21:05:13] production.ERROR: SQLSTATE[HY000] Connection refused
            #0 /home/kahve/web/app/Foo.php(12): bar()
            #1 {main}
            [2026-10-18T21:06:00.123456+03:00] local.WARNING: Yavaş istek
            LOG;
        $entries = LogParser::parse($log);

        $this->assertCount(3, $entries);
        $this->assertSame(['warning', 'error', 'info'], array_column($entries, 'level'));
        $this->assertSame('2026-10-18 21:06:00', $entries[0]['at']);
        $this->assertSame("#0 /home/kahve/web/app/Foo.php(12): bar()\n#1 {main}", $entries[1]['detail']);
        $this->assertTrue(LogParser::atLeast('critical', 'error'));
        $this->assertFalse(LogParser::atLeast('info', 'warning'));
        $this->assertSame('crit', LogParser::tone('error'));
    }
}
