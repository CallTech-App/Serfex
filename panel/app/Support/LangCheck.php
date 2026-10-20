<?php

namespace App\Support;

use Illuminate\View\Compilers\BladeCompiler;

/**
 * Checks the panel's translations (servik:lang-check, run in CI):
 *
 * - every key the code asks for (__(), trans_choice(), <x-t text>) has
 *   its English in lang/en.json;
 * - Turkish text outside __() in PHP, compiled Blade and the agent's
 *   labels has its English too, so labels shown through Blade echoes
 *   (Locales::label) and placeholder values are translated;
 * - no Turkish text is written straight into a Blade view's HTML;
 * - lang/en.json has no keys nobody uses, and each English line keeps
 *   the key's :placeholders; a "|" only separates plural forms.
 *
 * "Turkish text" here means a literal with a Turkish letter (ç ğ ı ö ş ü);
 * a label like "Olaylar" without one is not caught when it is missing,
 * but is kept as used once it is in en.json. Text deliberately left in
 * Turkish (sample data, Turkish-only formats, files written on the
 * server) is listed in IGNORE with the reason.
 */
final class LangCheck
{
    /** Turkish letters: the sign of text for people. */
    private const TURKISH = '/[çğıöşüÇĞİÖŞÜ]/u';

    /**
     * Turkish literals that are not panel text, by where they are.
     *
     * @var array<string, string>
     */
    private const IGNORE = [
        // Turkish month abbreviations of the Turkish date format.
        'app/Support/When.php' => 'Turkish-only date format',
        // Folding Turkish letters for search; not text.
        'app/Livewire/QuickSearch.php' => 'search folding',
        // The Turkish source of agent messages the panel matches on.
        'app/Services/Agent/AgentException.php' => 'agent message matching',
        // Language names are shown in their own language.
        'config/servik.php' => 'language names',
        'app/Support/LangCheck.php' => 'this check',
        'app/Support/Translator.php' => 'Turkish capitalization',
        'app/Support/PanelValidator.php' => 'Turkish capitalization',
    ];

    /**
     * Literals that are Turkish on purpose wherever they are.
     *
     * @var list<string>
     */
    private const IGNORE_TEXT = [
        'Türkçe',
    ];

    /**
     * Keys put together at run time, kept although no literal spells them.
     *
     * @var list<string>
     */
    private const DYNAMIC = [
        // The agent names the panel's PostgreSQL service by its version.
        '/^Panel veritabanı \(PostgreSQL \d+\)$/u',
    ];

    /** @var array<string, list<string>> key => where it is asked for */
    private array $asked = [];

    /** @var array<string, list<string>> Turkish literal => where */
    private array $turkish = [];

    /** @var array<string, true> every literal in the code */
    private array $literals = [];

    /** @var list<string> Turkish written straight into views */
    private array $hardCoded = [];

    public function __construct(private readonly string $panel, private readonly ?string $agent = null) {}

    /**
     * @return array{missing: array<string, list<string>>, unused: list<string>, placeholders: list<string>, hard_coded: list<string>}
     */
    public function run(): array
    {
        foreach ($this->phpFiles() as $file) {
            $this->scanPhp((string) file_get_contents($file), $this->relative($file));
        }
        $compiler = app('blade.compiler');
        foreach ($this->files($this->panel.'/resources/views', '.blade.php') as $file) {
            $this->scanView($compiler, (string) file_get_contents($file), $this->relative($file));
        }
        if ($this->agent !== null && is_dir($this->agent)) {
            $this->scanGo();
        }

        $english = $this->english();
        $missing = [];
        foreach ($this->asked + $this->turkish as $key => $where) {
            if (! isset($english[$key]) && ! in_array($key, self::IGNORE_TEXT, true)) {
                $missing[$key] = $where;
            }
        }
        ksort($missing);

        $unused = [];
        $placeholders = [];
        foreach ($english as $key => $line) {
            if (! isset($this->asked[$key]) && ! isset($this->literals[$key]) && ! $this->dynamic($key)) {
                $unused[] = $key;
            }
            if ($problem = $this->placeholderProblem($key, $line)) {
                $placeholders[] = $problem;
            }
        }

        return ['missing' => $missing, 'unused' => $unused, 'placeholders' => $placeholders, 'hard_coded' => $this->hardCoded];
    }

    /**
     * @return array<string, string>
     */
    public function english(): array
    {
        return (array) json_decode((string) file_get_contents($this->panel.'/lang/en.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Every key the code asks for and every Turkish literal, for writing
     * lang/en.json.
     *
     * @return list<string>
     */
    public function wanted(): array
    {
        $this->run();
        $keys = array_keys($this->asked + $this->turkish);
        sort($keys);

        return $keys;
    }

    private function scanPhp(string $source, string $where, bool $view = false): void
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $significant = function (int $i, int $step) use ($tokens, $count): int {
            for ($j = $i + $step; $j >= 0 && $j < $count; $j += $step) {
                if (! is_array($tokens[$j]) || ! in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    return $j;
                }
            }

            return -1;
        };
        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $this->checkHtml($token[1], $where, $token[2]);

                continue;
            }
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = self::literal($token[1]);
            if ($value === null || $value === '') {
                continue;
            }
            $this->literals[$value] = true;
            // A view's literal attribute (<x-t :text="... ? '...' : '...'">)
            // keeps its entities; Locales::html() looks it up decoded.
            if ($view && str_contains($value, '&')) {
                $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
                $this->literals[$value] = true;
            }
            $before = $significant($i, -1);
            $function = $before >= 0 ? $significant($before, -1) : -1;
            $after = $significant($i, 1);
            $asked = $before >= 0 && $tokens[$before] === '(' && $function >= 0 && is_array($tokens[$function])
                && in_array($tokens[$function][1], ['__', 'trans_choice', 'trans'], true)
                && $after >= 0 && in_array($tokens[$after], [',', ')'], true);
            if ($asked) {
                $this->asked[$value][] = $where.':'.$token[2];
            } elseif (preg_match(self::TURKISH, $value) && ! $this->ignored($where)) {
                $this->turkish[$value][] = $where.':'.$token[2];
            }
        }
    }

    private function scanView(BladeCompiler $compiler, string $source, string $where): void
    {
        // <x-t text="..."> sentences, looked up with their entities decoded.
        if (preg_match_all('/<x-t\b[^>]*?(?<![:\w-])text="([^"]*)"/s', $source, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$text, $offset]) {
                $key = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
                $this->asked[$key][] = $where.':'.(substr_count($source, "\n", 0, $offset) + 1);
                $this->literals[$text] = true;
            }
        }
        // Attribute values are HTML: a component's literal attribute
        // reaches PHP with its entities, so both spellings count as used.
        $compiled = $compiler->compileString($source);
        $this->scanPhp($compiled, $where, true);
    }

    /**
     * Flags Turkish text between tags in compiled view HTML (attributes
     * included: a title="" or placeholder="" is text too).
     */
    private function checkHtml(string $html, string $where, int $line): void
    {
        if (! preg_match(self::TURKISH, $html) || $this->ignored($where)) {
            return;
        }
        // Script and style bodies, comments and Blade component tags with
        // Turkish only inside {{ }} were already compiled away; what is
        // left with a Turkish letter is written into the page.
        foreach (preg_split('/\R/', $html) as $n => $text) {
            if (preg_match(self::TURKISH, $text) && ! $this->allowedHtml($text)) {
                $this->hardCoded[] = $where.' (compiled line '.($line + $n).'): '.trim(mb_strimwidth($text, 0, 160, '…'));
            }
        }
    }

    private function allowedHtml(string $text): bool
    {
        foreach (self::IGNORE_TEXT as $allowed) {
            $text = str_replace($allowed, '', $text);
        }

        return ! preg_match(self::TURKISH, $text);
    }

    private function scanGo(): void
    {
        foreach ($this->files($this->agent, '.go') as $file) {
            if (str_ends_with($file, '_test.go')) {
                continue;
            }
            if (preg_match_all('/"((?:[^"\\\\\n]|\\\\.)*)"|`([^`]*)`/', (string) file_get_contents($file), $m)) {
                foreach ($m[0] as $i => $_) {
                    $value = $m[1][$i] !== '' ? stripcslashes($m[1][$i]) : $m[2][$i];
                    if ($value !== '') {
                        $this->literals[$value] = true;
                    }
                }
            }
        }
    }

    private function placeholderProblem(string $key, string $line): ?string
    {
        $names = fn (string $s) => array_values(array_unique(preg_match_all('/(?<![\p{L}\d]):([a-z_][a-z0-9_]*)/iu', $s, $m) ? array_map('strtolower', $m[1]) : []));
        $want = $names($key);
        sort($want);
        $forms = str_contains($line, '|') ? explode('|', $line) : [$line];
        if (count($forms) > 1 && str_contains($key, '|')) {
            return "\"{$key}\": a key may not hold \"|\" (plural forms belong in the English)";
        }
        foreach ($forms as $form) {
            $form = preg_replace('/^\s*(\{\d+\}|\[[\d*]+,[\d*]+\])\s*/', '', $form);
            $have = $names((string) $form);
            sort($have);
            // A plural form may leave the number out ("one site").
            if (array_diff($have, $want) !== [] || (count($forms) === 1 && $have !== $want)) {
                return "\"{$key}\" → \"{$line}\": placeholders differ";
            }
        }

        return null;
    }

    private function dynamic(string $key): bool
    {
        foreach (self::DYNAMIC as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    private function ignored(string $where): bool
    {
        return isset(self::IGNORE[$where]);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = $this->files($this->panel.'/app', '.php');
        foreach (['routes', 'config'] as $dir) {
            $files = [...$files, ...$this->files($this->panel.'/'.$dir, '.php')];
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function files(string $dir, string $suffix): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with((string) $file, $suffix) && ! ($suffix === '.php' && str_ends_with((string) $file, '.blade.php'))) {
                $files[] = (string) $file;
            }
        }
        sort($files);

        return $files;
    }

    private function relative(string $file): string
    {
        return ltrim(substr($file, strlen($this->panel)), '/');
    }

    /**
     * The value of a single- or double-quoted PHP literal without
     * interpolation, or null.
     */
    private static function literal(string $source): ?string
    {
        if ($source[0] === "'") {
            return strtr(substr($source, 1, -1), ['\\\\' => '\\', "\\'" => "'"]);
        }
        if ($source[0] === '"' && ! str_contains($source, '$')) {
            return stripcslashes(substr($source, 1, -1));
        }

        return null;
    }
}
