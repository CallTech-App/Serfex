<?php

namespace Tests\Feature;

use App\Services\Settings\OutgoingMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panel's own mail through this server's mail server is handed to
 * sendmail. Postfix's sendmail waits without end when it cannot write to
 * the mail queue; a scheduled run with a problem to tell then never
 * finished, and the runs left behind used up the panel's database
 * connections.
 */
class OutgoingMailTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/serfex-sendmail-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** A sendmail that runs $script, in place of the server's. */
    private function sendmail(string $script): void
    {
        file_put_contents($this->dir.'/sendmail', "#!/bin/sh\n".$script);
        chmod($this->dir.'/sendmail', 0o755);
        config(['serfex.mail.sendmail' => $this->dir.'/sendmail']);
    }

    public function test_mail_through_this_server_is_handed_to_sendmail(): void
    {
        // Speaks enough SMTP for one message (sendmail -bs) and keeps it.
        $this->sendmail(<<<'SH'
            out="$(dirname "$0")/received"
            printf '220 stub ESMTP\r\n'
            while IFS= read -r line; do
                line=$(printf '%s' "$line" | tr -d '\r')
                case "$line" in
                    EHLO*|HELO*) printf '250 stub\r\n' ;;
                    MAIL*|RCPT*) printf '%s\n' "$line" >> "$out"; printf '250 Ok\r\n' ;;
                    DATA)
                        printf '354 Go ahead\r\n'
                        while IFS= read -r body; do
                            body=$(printf '%s' "$body" | tr -d '\r')
                            [ "$body" = . ] && break
                            printf '%s\n' "$body" >> "$out"
                        done
                        printf '250 Queued\r\n' ;;
                    QUIT) printf '221 Bye\r\n'; exit 0 ;;
                esac
            done
            SH);

        $this->assertNull(app(OutgoingMail::class)->send(['ops@kahve.com.tr'], 'Deneme bildirimi', 'Merhaba'));

        $received = (string) file_get_contents($this->dir.'/received');
        $this->assertStringContainsString('RCPT TO:<ops@kahve.com.tr>', $received);
        $this->assertStringContainsString('Subject: Deneme bildirimi', $received);
        $this->assertStringContainsString('Merhaba', $received);
    }

    public function test_a_sendmail_that_never_answers_is_stopped(): void
    {
        $this->sendmail("sleep 120\n");
        config(['serfex.mail.sendmail_timeout' => 1]);

        $started = microtime(true);
        $error = app(OutgoingMail::class)->send(['ops@kahve.com.tr'], 'Deneme bildirimi', 'Merhaba');

        $this->assertLessThan(20, microtime(true) - $started, 'the send waited for a sendmail that never answers');
        $this->assertSame('Sunucunun posta programı (sendmail) 1 saniye içinde yanıt vermedi ve durduruldu.', $error);
    }
}
