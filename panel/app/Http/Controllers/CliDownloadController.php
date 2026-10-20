<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The servik command line for developers' computers, from this server's
 * own release (so it works while the repository is private, and its
 * version matches the panel):
 *
 *   curl -fsSL https://panel.example.com:8443/cli/install.sh | sh
 */
class CliDownloadController extends Controller
{
    /**
     * install-cli.sh, set to download from this panel.
     */
    public function script(): Response
    {
        $path = config('servik.cli_downloads').'/install-cli.sh';
        if (! is_file($path)) {
            // A checkout from git: the script is in the repository.
            $path = dirname(base_path()).'/scripts/install-cli.sh';
        }
        abort_unless(is_file($path), 404);
        $script = (string) file_get_contents($path);
        $script = preg_replace('/^SOURCE=.*$/m', 'SOURCE='.escapeshellarg(url('/cli/indir')), $script, 1);

        return response($script, 200, ['Content-Type' => 'text/x-shellscript; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }

    public function download(string $file): BinaryFileResponse
    {
        $path = config('servik.cli_downloads').'/'.$file;
        abort_unless(is_file($path), 404, __('Bu sunucunun sürümünde komut satırı dosyaları yok.'));

        return response()->download($path, $file, ['Content-Type' => $file === 'SHA256SUMS' ? 'text/plain; charset=utf-8' : 'application/octet-stream']);
    }
}
