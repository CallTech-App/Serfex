<?php

namespace Tests\Integration;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Mail\MailPassword;
use PHPUnit\Framework\TestCase;

/**
 * Talks to a real servik-agent binary in dry-run mode, so site changes land
 * in a scratch directory instead of the machine. Set SERVIK_AGENT_BIN to its
 * path (CI builds it from ../agent); the test is skipped otherwise.
 */
class AgentIntegrationTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    private string $socket;

    private string $root;

    protected function setUp(): void
    {
        $bin = getenv('SERVIK_AGENT_BIN');
        if (! $bin || ! is_executable($bin)) {
            $this->markTestSkipped('SERVIK_AGENT_BIN is not set.');
        }

        $dir = sys_get_temp_dir().'/servik-agent-'.getmypid().'-'.bin2hex(random_bytes(4));
        $this->socket = $dir.'/agent.sock';
        $this->root = $dir.'/root';
        $this->process = proc_open(
            [$bin, '-socket', $this->socket, '-dry-run', '-root', $this->root],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        for ($i = 0; $i < 50 && ! file_exists($this->socket); $i++) {
            usleep(100_000);
        }
        $this->assertFileExists($this->socket, 'agent did not create its socket');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if (isset($this->root) && is_dir(dirname($this->root))) {
            exec('rm -rf '.escapeshellarg(dirname($this->root)));
        }
    }

    public function test_ping_and_info(): void
    {
        $client = new AgentClient($this->socket);

        $this->assertTrue($client->ping());
        $this->assertSame('linux', $client->call('agent.info')['os']);
    }

    public function test_unknown_method_is_reported(): void
    {
        $client = new AgentClient($this->socket);

        $this->expectException(AgentException::class);
        $this->expectExceptionCode(-32601);
        $client->call('does.not.exist');
    }

    public function test_site_lifecycle(): void
    {
        $client = new AgentClient($this->socket);

        $this->assertSame(['8.3', '8.4', '8.5'], $client->call('php.versions'));

        $spec = ['domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve', 'php_version' => '8.4', 'aliases' => ['www.kahveatolyesi.com.tr']];
        $result = $client->call('site.apply', $spec, 30);

        $this->assertTrue($result['user_created']);
        $this->assertSame('/home/kahve/web/kahveatolyesi.com.tr/public_html', $result['document_root']);
        // The link targets the real /etc path, so inside the scratch root it dangles.
        $this->assertSame('/etc/nginx/sites-available/kahveatolyesi.com.tr.conf', readlink($this->root.'/etc/nginx/sites-enabled/kahveatolyesi.com.tr.conf'));
        $this->assertStringContainsString(
            'server_name kahveatolyesi.com.tr www.kahveatolyesi.com.tr;',
            file_get_contents($this->root.'/etc/nginx/sites-available/kahveatolyesi.com.tr.conf'),
        );
        $this->assertFileExists($this->root.'/etc/php/8.4/fpm/pool.d/kahveatolyesi.com.tr.conf');

        $this->assertFalse($client->call('site.apply', $spec, 30)['changed'], 'apply must be idempotent');

        $deleted = $client->call('site.delete', ['domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve', 'remove_files' => true, 'remove_user' => true], 30);
        $this->assertTrue($deleted['user_removed']);
        $this->assertFileDoesNotExist($this->root.'/etc/nginx/sites-available/kahveatolyesi.com.tr.conf');
        $this->assertDirectoryDoesNotExist($this->root.'/home/kahve');
    }

    public function test_certificate_lifecycle(): void
    {
        $client = new AgentClient($this->socket);
        $spec = ['domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve', 'php_version' => '8.4', 'aliases' => ['www.kahveatolyesi.com.tr']];
        $client->call('site.apply', $spec, 30);

        // Dry runs use a simulated CA but the same challenge plumbing.
        $issued = $client->call('ssl.issue', ['name' => 'kahveatolyesi.com.tr', 'names' => ['kahveatolyesi.com.tr', 'www.kahveatolyesi.com.tr'], 'challenge' => 'http-01'], 30);
        $this->assertSame(['kahveatolyesi.com.tr', 'www.kahveatolyesi.com.tr'], $issued['names']);
        $this->assertGreaterThan(time(), strtotime($issued['not_after']));
        $this->assertFileExists($this->root.'/etc/servik/ssl/certs/kahveatolyesi.com.tr/fullchain.pem');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->root.'/etc/servik/ssl/certs/kahveatolyesi.com.tr/privkey.pem')), -4));

        $client->call('site.apply', [...$spec, 'tls' => ['certificate' => 'kahveatolyesi.com.tr', 'redirect' => true]], 30);
        $vhost = file_get_contents($this->root.'/etc/nginx/sites-available/kahveatolyesi.com.tr.conf');
        $this->assertStringContainsString('listen 443 ssl', $vhost);
        $this->assertStringContainsString('ssl_certificate /etc/servik/ssl/certs/kahveatolyesi.com.tr/fullchain.pem;', $vhost);
        $this->assertStringContainsString('return 301 https://$host$request_uri;', $vhost);
        $this->assertStringContainsString('/.well-known/acme-challenge/', $vhost);

        $this->assertSame($issued['serial'], $client->call('ssl.get', ['name' => 'kahveatolyesi.com.tr'])['serial']);
        try {
            $client->call('ssl.delete', ['name' => 'kahveatolyesi.com.tr']);
            $this->fail('a certificate in use must not be deleted');
        } catch (AgentException $e) {
            $this->assertSame(AgentException::INVALID_PARAMS, $e->getCode());
        }

        $client->call('site.apply', $spec, 30);
        $this->assertTrue($client->call('ssl.delete', ['name' => 'kahveatolyesi.com.tr'])['deleted']);
        $this->assertFileDoesNotExist($this->root.'/etc/servik/ssl/certs/kahveatolyesi.com.tr/fullchain.pem');
    }

    public function test_invalid_site_is_rejected_with_a_reason(): void
    {
        $client = new AgentClient($this->socket);

        try {
            $client->call('site.apply', ['domain' => 'localhost', 'user' => 'kahve', 'php_version' => '8.4', 'aliases' => []]);
            $this->fail('expected an AgentException');
        } catch (AgentException $e) {
            $this->assertSame(AgentException::INVALID_PARAMS, $e->getCode());
            $this->assertStringContainsString('at least two labels', $e->reason);
        }
    }

    public function test_dns_lifecycle(): void
    {
        $client = new AgentClient($this->socket);

        $this->assertFalse($client->call('dns.status')['configured']);
        try {
            $client->call('dns.zone.get', ['zone' => 'kahveatolyesi.com.tr']);
            $this->fail('expected the DNS server to be missing');
        } catch (AgentException $e) {
            $this->assertSame(-32001, $e->getCode());
        }

        $setup = $client->call('dns.setup', [], 30);
        $this->assertTrue($setup['installed']);
        $this->assertSame(['installed' => true, 'configured' => true, 'running' => true, 'version' => 'simulated'], $client->call('dns.status'));
        $this->assertStringContainsString('launch=lmdb', file_get_contents($this->root.'/etc/powerdns/pdns-servik.conf'));
        $this->assertSame(['ipv4' => ['203.0.113.10'], 'ipv6' => ['2001:db8::10']], $client->call('system.addresses'));

        $spec = [
            'zone' => 'kahveatolyesi.com.tr',
            'soa' => ['primary' => 'ns1.kahveatolyesi.com.tr.', 'hostmaster' => 'hostmaster@kahveatolyesi.com.tr', 'refresh' => 10800, 'retry' => 3600, 'expire' => 604800, 'minimum' => 3600, 'ttl' => 3600],
            'records' => [
                ['name' => '@', 'type' => 'NS', 'ttl' => 3600, 'content' => 'ns1.kahveatolyesi.com.tr.'],
                ['name' => 'ns1', 'type' => 'A', 'ttl' => 3600, 'content' => '203.0.113.10'],
                ['name' => '@', 'type' => 'MX', 'ttl' => 3600, 'content' => '10 mail.kahveatolyesi.com.tr.'],
                ['name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'content' => 'v=spf1 a mx ~all'],
            ],
        ];
        $applied = $client->call('dns.zone.apply', $spec, 30);
        $this->assertTrue($applied['created']);
        $this->assertFalse($client->call('dns.zone.apply', $spec, 30)['changed'], 'apply must be idempotent');

        $zone = $client->call('dns.zone.get', ['zone' => 'kahveatolyesi.com.tr']);
        $this->assertSame($applied['serial'], $zone['serial']);
        $this->assertContains(['name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'content' => 'v=spf1 a mx ~all'], $zone['records']);

        try {
            $client->call('dns.zone.apply', [...$spec, 'records' => [...$spec['records'], ['name' => '@', 'type' => 'CNAME', 'ttl' => 3600, 'content' => 'x.example.com.']]], 30);
            $this->fail('expected an apex CNAME to be rejected');
        } catch (AgentException $e) {
            $this->assertSame(AgentException::INVALID_PARAMS, $e->getCode());
            $this->assertStringContainsString('CNAME', $e->reason);
        }

        $this->assertTrue($client->call('dns.zone.delete', ['zone' => 'kahveatolyesi.com.tr'], 30)['deleted']);
    }

    public function test_mail_lifecycle(): void
    {
        $client = new AgentClient($this->socket);

        try {
            $client->call('mail.domain.apply', ['domain' => 'kahveatolyesi.com.tr'], 30);
            $this->fail('expected the mail server to be missing');
        } catch (AgentException $e) {
            $this->assertSame(-32002, $e->getCode());
        }

        $setup = $client->call('mail.setup', ['hostname' => 'mail.servik.net.tr', 'certificate' => '', 'hourly_limit' => 500, 'webmail' => true], 60);
        $this->assertTrue($setup['installed']);
        $this->assertSame('https://mail.servik.net.tr/', $setup['webmail_url']);
        $status = $client->call('mail.status');
        $this->assertTrue($status['configured']);

        // The same shape MailDomain::agentSpec() sends.
        $spec = [
            'domain' => 'kahveatolyesi.com.tr',
            'spam_filter' => true,
            'mailboxes' => [
                ['name' => 'siparis', 'password_hash' => MailPassword::hash('Kahve-fincan-7'), 'quota_mb' => 1024, 'forward_to' => [], 'keep_copy' => true,
                    'autoreply' => ['subject' => 'İzindeyim', 'body' => 'Pazartesi dönüyorum.', 'until' => '']],
            ],
            'aliases' => [['name' => 'satis', 'to' => ['siparis@kahveatolyesi.com.tr']]],
            'catch_all' => ['siparis@kahveatolyesi.com.tr'],
        ];
        $applied = $client->call('mail.domain.apply', $spec, 30);
        $this->assertSame('servik._domainkey', $applied['dkim']['name']);
        $this->assertStringStartsWith('v=DKIM1; k=rsa; p=', $applied['dkim']['record']);
        $this->assertFalse($client->call('mail.domain.apply', $spec, 30)['changed'], 'apply must be idempotent');
        $this->assertStringContainsString('siparis@kahveatolyesi.com.tr:{SHA512-CRYPT}', file_get_contents($this->root.'/etc/dovecot/servik/users'));

        $this->assertSame(['siparis' => 0], $client->call('mail.usage', ['domain' => 'kahveatolyesi.com.tr'])['mailboxes']);
        $login = $client->call('mail.webmail.login', ['address' => 'siparis@kahveatolyesi.com.tr']);
        $this->assertStringStartsWith('https://mail.servik.net.tr/?_task=login&_action=servik-sso&_token=', $login['url']);

        try {
            $client->call('mail.domain.apply', [...$spec, 'mailboxes' => [[...$spec['mailboxes'][0], 'password_hash' => 'plain']]], 30);
            $this->fail('expected a plain password to be rejected');
        } catch (AgentException $e) {
            $this->assertSame(AgentException::INVALID_PARAMS, $e->getCode());
        }

        $this->assertTrue($client->call('mail.domain.delete', ['domain' => 'kahveatolyesi.com.tr', 'remove_files' => true], 30)['deleted']);
    }

    public function test_laravel_lifecycle(): void
    {
        $client = new AgentClient($this->socket);
        $target = ['domain' => 'api.hizlikargo.app', 'user' => 'hizlikargo', 'php_version' => '8.4'];
        $app = $target + ['path' => 'laravel/current'];
        $client->call('site.apply', ['domain' => $target['domain'], 'user' => $target['user'], 'php_version' => '8.4'], 30);

        // The same shape DeployLaravelApp sends for a first install.
        $deploy = $client->call('laravel.deploy', $target + [
            'run' => 'install-1', 'release' => '1',
            'source' => ['type' => 'create', 'package' => 'laravel/laravel'],
            'build' => ['frontend' => true, 'package_manager' => 'npm', 'migrate' => true],
            'hooks' => ['before_build' => '', 'before_activate' => '', 'after_activate' => ''], 'env' => ['APP_ENV' => 'production', 'APP_DEBUG' => 'false'], 'health' => null, 'rollback' => false, 'keep' => 5,
        ], 60);
        $this->assertSame('1', $deploy['release']);
        $this->assertFalse($deploy['rolled_back']);
        $this->assertNotSame('', $deploy['laravel']);

        $log = $client->call('laravel.run.read', ['domain' => $target['domain'], 'run' => 'install-1', 'offset' => 0]);
        $this->assertGreaterThan(0, $log['size']);

        // The site now serves the release.
        $client->call('site.apply', ['domain' => $target['domain'], 'user' => $target['user'], 'php_version' => '8.4', 'web_root' => 'laravel/current/public'], 30);
        $this->assertStringContainsString(
            'root /home/hizlikargo/web/api.hizlikargo.app/laravel/current/public;',
            file_get_contents($this->root.'/etc/nginx/sites-available/api.hizlikargo.app.conf'),
        );

        $status = $client->call('laravel.status', $app);
        $this->assertTrue($status['exists']);
        $this->assertSame('1', $status['current']);

        $env = $client->call('laravel.env.get', $app);
        $this->assertStringContainsString('APP_ENV=production', $env['env']);
        $client->call('laravel.env.set', $app + ['content' => $env['env']."APP_NAME='Hızlı Kargo'\n"], 30);
        $this->assertStringContainsString("APP_NAME='Hızlı Kargo'", $client->call('laravel.env.get', $app)['env']);

        $client->call('laravel.maintenance', $app + ['down' => true, 'secret' => 'servik-bypass', 'retry' => 60], 30);
        $this->assertSame('servik-bypass', $client->call('laravel.status', $app)['maintenance']['secret']);
        $client->call('laravel.maintenance', $app + ['down' => false], 30);
        $this->assertNull($client->call('laravel.status', $app)['maintenance']);

        $client->call('laravel.queue.apply', $app + ['workers' => [['name' => 'default', 'connection' => '', 'queue' => 'default', 'processes' => 2, 'timeout' => 60, 'tries' => 3, 'backoff' => 0, 'sleep' => 3, 'max_jobs' => 0, 'max_time' => 3600, 'memory' => 128]]], 30);
        $this->assertFileExists($this->root.'/etc/systemd/system/servik-queue-api.hizlikargo.app_default@.service');
        $client->call('laravel.schedule.apply', $app + ['enabled' => true], 30);
        $this->assertFileExists($this->root.'/etc/systemd/system/servik-schedule-api.hizlikargo.app.timer');

        try {
            $client->call('laravel.run', $app + ['run' => 'cmd-1', 'tool' => 'rm', 'args' => ['-rf', '/']], 30);
            $this->fail('expected an unknown tool to be rejected');
        } catch (AgentException $e) {
            $this->assertSame(AgentException::INVALID_PARAMS, $e->getCode());
        }

        $client->call('laravel.remove', $target + ['remove_files' => true], 30);
        $this->assertFileDoesNotExist($this->root.'/etc/systemd/system/servik-queue-api.hizlikargo.app_default@.service');
        $this->assertFileDoesNotExist($this->root.'/etc/systemd/system/servik-schedule-api.hizlikargo.app.timer');
        $this->assertDirectoryDoesNotExist($this->root.'/home/hizlikargo/web/api.hizlikargo.app/laravel');
        $client->call('site.delete', ['domain' => $target['domain'], 'user' => $target['user'], 'remove_files' => true, 'remove_user' => true], 30);
    }

    public function test_monitor_lifecycle(): void
    {
        $client = new AgentClient($this->socket);
        $site = ['domain' => 'magazaonline.com.tr', 'user' => 'magaza', 'php_version' => '8.4'];
        $client->call('site.apply', $site, 30);

        // The dry-run agent makes up load for its sites every two seconds.
        $usage = null;
        for ($i = 0; $i < 50 && $usage === null; $i++) {
            usleep(200_000);
            foreach ($client->call('monitor.live', ['window' => 10])['sites'] as $u) {
                $usage = $u['domain'] === $site['domain'] ? $u : $usage;
            }
        }
        $this->assertNotNull($usage, 'the site never showed up in monitor.live');
        $this->assertSame('magaza', $usage['user']);
        $this->assertSame(10, $usage['php']['max']);

        $blocked = $client->call('firewall.block', ['address' => '45.155.205.233', 'seconds' => 3600, 'reason' => 'İstek seli'], 30);
        $this->assertSame('45.155.205.233', $blocked['address']);
        $this->assertSame(['45.155.205.233'], array_column($client->call('firewall.list'), 'address'));
        try {
            $client->call('firewall.block', ['address' => '127.0.0.1', 'seconds' => 60], 30);
            $this->fail('expected loopback to be refused');
        } catch (AgentException $e) {
            $this->assertSame(AgentException::INVALID_PARAMS, $e->getCode());
        }
        $this->assertTrue($client->call('firewall.unblock', ['address' => '45.155.205.233'], 30)['removed']);
        $this->assertSame([], $client->call('firewall.list'));

        $pool = $this->root.'/etc/php/8.4/fpm/pool.d/magazaonline.com.tr.conf';
        $client->call('monitor.limit.apply', ['domain' => $site['domain'], 'cpu' => 50, 'memory_mb' => 512, 'php_workers' => 2, 'seconds' => 600], 30);
        $this->assertStringContainsString("pm.max_children = 2\n", file_get_contents($pool));
        $this->assertSame([50], array_column($client->call('monitor.limits'), 'cpu'));
        // A site change keeps the limit.
        $client->call('site.apply', $site, 30);
        $this->assertStringContainsString("pm.max_children = 2\n", file_get_contents($pool));
        $client->call('monitor.limit.remove', ['domain' => $site['domain']], 30);
        $this->assertStringContainsString("pm.max_children = 10\n", file_get_contents($pool));
        $this->assertSame([], $client->call('monitor.limits'));

        $this->assertArrayHasKey('stopped', $client->call('php.restart', ['domain' => $site['domain']], 30));
        $this->assertFalse($client->call('mail.queue')['installed']);

        $client->call('site.delete', ['domain' => $site['domain'], 'user' => $site['user'], 'remove_files' => true, 'remove_user' => true], 30);
    }

    public function test_database_lifecycle(): void
    {
        $client = new AgentClient($this->socket);

        try {
            $client->call('db.database.create', ['server' => 'mysql', 'name' => 'kahve_shop', 'site' => 'kahveatolyesi.com.tr'], 30);
            $this->fail('expected a database server that is not installed to be refused');
        } catch (AgentException $e) {
            $this->assertSame(-32003, $e->getCode());
        }

        $this->assertSame('8.0.46', $client->call('db.server.install', ['product' => 'mysql'], 60)['version']);
        $this->assertTrue($client->call('db.manager.setup', ['port' => 8443], 60)['phpmyadmin']);
        $status = $client->call('db.status');
        $this->assertTrue($status['servers']['mysql']['running']);
        $this->assertFalse($status['servers']['postgresql']['installed']);

        $client->call('db.database.create', ['server' => 'mysql', 'name' => 'kahve_shop', 'site' => 'kahveatolyesi.com.tr'], 30);
        $client->call('db.user.apply', [
            'server' => 'mysql', 'name' => 'kahve_app', 'password' => 'Uzun-ve-guclu-parola-42', 'hosts' => [],
            'grants' => [['database' => 'kahve_shop', 'access' => 'all']],
        ], 30);
        $listing = $client->call('db.list', ['server' => 'mysql'], 30);
        $this->assertSame(['kahve_shop'], array_column($listing['databases'], 'name'));
        $this->assertSame(['kahve_app'], array_column($listing['users'], 'name'));

        $login = $client->call('db.manager.login', ['server' => 'mysql', 'database' => 'kahve_shop'], 30);
        $this->assertSame([8443, 'phpmyadmin'], [$login['port'], $login['tool']]);
        $this->assertStringStartsWith('/phpmyadmin/', $login['path']);

        $this->assertTrue($client->call('db.database.delete', ['server' => 'mysql', 'name' => 'kahve_shop', 'backup' => true], 60)['deleted']);
        $client->call('db.user.delete', ['server' => 'mysql', 'name' => 'kahve_app'], 30);
        $this->assertSame(['databases' => [], 'users' => []], $client->call('db.list', ['server' => 'mysql'], 30));
    }

    public function test_files_and_ftp_lifecycle(): void
    {
        $client = new AgentClient($this->socket);
        $client->call('site.apply', ['domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve', 'php_version' => '8.4'], 30);
        $site = ['user' => 'kahve', 'domain' => 'kahveatolyesi.com.tr'];

        $up = $client->call('files.upload', $site + ['path' => 'public_html', 'name' => 'menu.txt', 'upload' => 'up-integration1', 'offset' => 0, 'data' => base64_encode("Türk kahvesi\n"), 'final' => true], 30);
        $this->assertSame('public_html/menu.txt', $up['path']);
        $this->assertSame("Türk kahvesi\n", file_get_contents($this->root.'/home/kahve/web/kahveatolyesi.com.tr/public_html/menu.txt'));
        $this->assertContains('menu.txt', array_column($client->call('files.list', $site + ['path' => 'public_html'], 30)['entries'], 'name'));

        $text = $client->call('files.text', $site + ['path' => 'public_html/menu.txt'], 30);
        $saved = $client->call('files.save', $site + ['path' => 'public_html/menu.txt', 'data' => base64_encode("Filtre kahve\n"), 'mtime' => $text['mtime']], 30);
        $this->assertNotSame('', $saved['trash'], 'the previous version goes to the trash');

        $deleted = $client->call('files.delete', $site + ['paths' => ['public_html/menu.txt']], 30);
        $this->assertFileDoesNotExist($this->root.'/home/kahve/web/kahveatolyesi.com.tr/public_html/menu.txt');
        $client->call('files.trash.restore', $site + ['ids' => $deleted['ids']], 30);
        $this->assertSame("Filtre kahve\n", file_get_contents($this->root.'/home/kahve/web/kahveatolyesi.com.tr/public_html/menu.txt'));

        try {
            $client->call('files.list', $site + ['path' => '../../../etc'], 30);
            $this->fail('expected a path outside the site to be refused');
        } catch (AgentException $e) {
            $this->assertSame(-32008, $e->getCode());
        }

        $ftp = $client->call('ftp.account.apply', $site + ['name' => 'kahve_deploy', 'dir' => 'public_html', 'password' => 'Uzun-Parola-2026', 'locked' => false], 60);
        $this->assertSame('/public_html', $ftp['folder']);
        $this->assertFileExists($this->root.'/etc/ssh/sshd_config.d/50-servik-sftp.conf');
        $this->assertSame(['kahve_deploy'], array_column($client->call('ftp.account.list', [], 30), 'name'));
        $client->call('ftp.account.delete', ['name' => 'kahve_deploy'], 60);
        $this->assertSame([], $client->call('ftp.account.list', [], 30));
    }
}
