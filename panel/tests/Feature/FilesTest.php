<?php

namespace Tests\Feature;

use App\Jobs\DeleteSite;
use App\Jobs\PurgeFtpAccount;
use App\Jobs\SyncFtpAccounts;
use App\Livewire\Sites\SiteFiles;
use App\Livewire\Sites\SiteFtp;
use App\Models\Customer;
use App\Models\FtpAccount;
use App\Models\LaravelApp;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use App\Services\Dns\Zones;
use App\Services\Files\FileManager;
use App\Services\Files\FtpAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Dosya Yöneticisi and FTP hesapları: the agent does the file work as the
 * site's user; the panel names the site, keeps Undo working and keeps
 * SFTP accounts within the package, locked while the customer is
 * suspended and gone with the site.
 */
class FilesTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'files.list' => fn ($p) => ['path' => $p['path'], 'entries' => $p['path'] === '' ? [
                ['name' => 'public_html', 'path' => 'public_html', 'type' => 'dir', 'size' => 4096, 'mtime' => 1760860800, 'mode' => '755', 'owned' => true],
                ['name' => 'notlar.txt', 'path' => 'notlar.txt', 'type' => 'file', 'size' => 120, 'mtime' => 1760860800, 'mode' => '644', 'owned' => true],
            ] : [
                ['name' => 'index.php', 'path' => 'public_html/index.php', 'type' => 'file', 'size' => 512, 'mtime' => 1760860800, 'mode' => '644', 'owned' => true],
                ['name' => 'site.zip', 'path' => 'public_html/site.zip', 'type' => 'file', 'size' => 2048, 'mtime' => 1760860800, 'mode' => '644', 'owned' => true],
            ], 'truncated' => false],
            'files.mkdir' => fn ($p) => ['path' => ltrim($p['path'].'/'.$p['name'], '/')],
            'files.delete' => fn ($p) => ['ids' => array_map(fn ($i) => 't'.$i, array_keys($p['paths']))],
            'files.trash.restore' => fn ($p) => ['restored' => array_map(fn ($id) => ['id' => $id, 'path' => 'public_html/index.php'], $p['ids'])],
            'files.trash.list' => ['items' => [['id' => 'abc', 'path' => 'public_html/eski.php', 'kind' => 'deleted', 'type' => 'file', 'size' => 10, 'deleted_at' => 1760860800]]],
            'files.rename' => fn ($p) => ['path' => trim(dirname($p['path']), '.').'/'.$p['name']],
            'files.chmod' => fn ($p) => ['changed' => 1, 'before' => [$p['paths'][0] => '644']],
            'files.search' => ['entries' => [['name' => 'config.php', 'path' => 'public_html/app/config.php', 'type' => 'file', 'size' => 10, 'mtime' => 1760860800, 'mode' => '644', 'owned' => true]], 'truncated' => false],
            'files.text' => ['content' => "<?php echo 'merhaba';\n", 'mtime' => 1760860800123456, 'size' => 22],
            'files.save' => ['mtime' => 1760860900000000, 'trash' => 'v1'],
            'files.upload' => fn ($p) => ['size' => strlen(base64_decode($p['data'])), 'path' => $p['final'] ? ltrim($p['path'].'/'.$p['name'], '/') : '', 'trash' => ''],
            'files.read' => fn ($p) => ['data' => base64_encode('merhaba'), 'offset' => 7, 'size' => 7],
            'ftp.status' => ['installed' => true, 'ready' => true, 'port' => 22, 'accounts' => 0],
            'ftp.account.apply' => fn ($p) => ['name' => $p['name'], 'folder' => '/'.$p['dir'], 'created' => true, 'port' => 22],
            'ftp.account.delete' => ['deleted' => true],
            'site.delete' => ['removed_user' => true],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);
        $this->site->systemUser->update(['name' => 'kahve']);
        $this->actingAs(User::factory()->create());
    }

    private function account(string $name = 'kahve_deploy', string $dir = 'public_html'): FtpAccount
    {
        return FtpAccount::create(['site_id' => $this->site->id, 'system_user_id' => $this->site->system_user_id, 'name' => $name, 'directory' => $dir, 'password_changed_at' => now()]);
    }

    // --- Dosya Yöneticisi ---

    public function test_the_site_page_links_the_file_manager_and_ftp_accounts(): void
    {
        $this->account();
        $this->get('/sites/'.$this->site->id)->assertOk()
            ->assertSee(route('sites.files', $this->site))
            ->assertSee(route('sites.ftp', $this->site))
            ->assertSee('1 hesap');
    }

    public function test_lists_the_site_folder_as_the_sites_user_and_opens_folders(): void
    {
        $this->get('/sites/'.$this->site->id.'/files')->assertOk()
            ->assertSee('Dosya Yöneticisi')
            ->assertSee('/home/kahve/web/')
            ->assertSee('public_html')
            ->assertSee('notlar.txt');
        $this->assertSame(['user' => 'kahve', 'domain' => 'kahveatolyesi.com.tr', 'path' => ''], $this->agent->called('files.list')[0]);

        Livewire::test(SiteFiles::class, ['site' => $this->site->id])
            ->call('open', 'public_html')
            ->assertSet('path', 'public_html')
            ->assertSee('index.php')
            ->assertSee('Arşivi aç')
            // Dots never climb out; the agent refuses anything else outside.
            ->call('open', '../../etc')
            ->assertSet('path', 'etc');
    }

    public function test_a_refusal_shows_in_words(): void
    {
        $this->agent->answers['files.list'] = AgentException::rpcError('files.list', FileManager::ERROR, 'not_found: eksik: no such file or directory');

        Livewire::test(SiteFiles::class, ['site' => $this->site->id])
            ->assertSee('Klasör açılamadı')
            ->assertSee('"eksik" bulunamadı');
    }

    public function test_new_folder_rename_delete_and_permissions_each_have_undo(): void
    {
        $page = Livewire::test(SiteFiles::class, ['site' => $this->site->id])
            ->call('open', 'public_html')
            ->call('openNew', 'folder')
            ->set('newName', 'resimler')
            ->call('create')
            ->assertSet('newKind', '')
            ->assertDispatched('toast', fn ($name, $p) => $p['message'] === 'Klasör oluşturuldu: resimler' && $p['undo'] === ['method' => 'remove', 'args' => [['public_html/resimler']]]);
        $this->assertSame('resimler', $this->agent->called('files.mkdir')[0]['name']);

        $page->call('openRename', 'public_html/index.php')
            ->set('renameName', 'ana.php')
            ->call('rename')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'undoRename', 'args' => ['public_html/ana.php', 'index.php']]);

        $page->set('selected', ['public_html/index.php', 'public_html/site.zip'])
            ->call('confirmDelete')
            ->assertSet('deleteOpen', true)
            ->call('delete')
            ->assertDispatched('toast', fn ($name, $p) => $p['message'] === '2 öğe çöp kutusuna taşındı.' && $p['undo'] === ['method' => 'restoreTrash', 'args' => [['t0', 't1']]]);
        $page->call('restoreTrash', ['t0', 't1']);
        $this->assertSame([['user' => 'kahve', 'domain' => 'kahveatolyesi.com.tr', 'ids' => ['t0', 't1']]], $this->agent->called('files.trash.restore'));

        // A row's own action leaves the selection alone.
        $page->set('selected', ['public_html/site.zip'])
            ->call('openPermissions', 'public_html/index.php', '644')
            ->set('permMode', '600')
            ->call('chmod')
            ->assertSet('selected', ['public_html/site.zip'])
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'undoChmod', 'args' => [['public_html/index.php' => '644']]]);
        $page->call('openPermissions', 'public_html/index.php')->set('permMode', '9x')->call('chmod')->assertHasErrors('permMode');
        $this->assertCount(1, $this->agent->called('files.chmod'));
        $this->assertSame(['public_html/index.php'], $this->agent->called('files.chmod')[0]['paths']);
    }

    public function test_searches_below_the_folder_and_shows_the_trash(): void
    {
        Livewire::test(SiteFiles::class, ['site' => $this->site->id])
            ->set('search', 'not')
            ->assertSee('notlar.txt')
            ->assertDontSee('>public_html</button>', false)
            ->call('searchAll')
            ->assertSee('config.php')
            ->assertSee('/public_html/app')
            ->set('trashOpen', true)
            ->assertSee('public_html/eski.php');
        $this->assertSame('not', $this->agent->called('files.search')[0]['query']);
    }

    public function test_extracts_and_compresses_through_the_agent(): void
    {
        $this->agent->answers['files.extract'] = ['entries' => 12];
        $this->agent->answers['files.compress'] = ['path' => 'public_html/yedek.zip'];

        Livewire::test(SiteFiles::class, ['site' => $this->site->id])
            ->call('open', 'public_html')
            ->call('openExtract', 'public_html/site.zip')
            ->assertSet('extractTo', 'public_html')
            ->call('extract')
            ->assertDispatched('toast', message: '12 öğe çıkarıldı.')
            ->set('selected', ['public_html/index.php'])
            ->call('openCompress')
            ->set('archiveName', 'yedek')
            ->call('compress')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'remove', 'args' => [['public_html/yedek.zip']]]);
        $this->assertSame(['public_html/site.zip', 'public_html', false], array_values(array_intersect_key($this->agent->called('files.extract')[0], array_flip(['path', 'to', 'overwrite']))));
    }

    public function test_the_editor_reads_and_saves_text_with_its_version_in_the_trash(): void
    {
        $base = '/sites/'.$this->site->id.'/files';
        $this->getJson($base.'/text?path=public_html/index.php')->assertOk()
            ->assertJson(['content' => "<?php echo 'merhaba';\n", 'mtime' => 1760860800123456]);

        $this->postJson($base.'/save', ['path' => 'public_html/index.php', 'content' => "<?php echo 'selam';\n", 'mtime' => 1760860800123456])
            ->assertOk()->assertJson(['trash' => 'v1']);
        $save = $this->agent->called('files.save')[0];
        $this->assertSame("<?php echo 'selam';\n", base64_decode($save['data']));
        $this->assertSame(1760860800123456, $save['mtime']);

        // Someone changed the file meanwhile.
        $this->agent->answers['files.save'] = AgentException::rpcError('files.save', FileManager::ERROR, 'conflict: public_html/index.php: changed since it was opened');
        $this->postJson($base.'/save', ['path' => 'public_html/index.php', 'content' => 'x', 'mtime' => 1])
            ->assertStatus(422)->assertJson(['code' => 'conflict', 'error' => 'Dosya siz düzenlerken değişti. Kaydetmeden önce yeniden açın.']);

        $this->postJson($base.'/save', ['path' => 'a.txt', 'content' => str_repeat('a', FileManager::MAX_EDIT + 1), 'mtime' => 0])->assertStatus(422);

        Livewire::test(SiteFiles::class, ['site' => $this->site->id])
            ->call('saved', 'public_html/index.php', 'v1')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'restoreTrash', 'args' => [['v1']]]);
    }

    public function test_uploads_in_chunks_and_downloads(): void
    {
        $base = '/sites/'.$this->site->id.'/files';
        $this->call('POST', $base.'/upload?path=public_html&name=logo.png&upload=up-1&offset=0', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'abc')
            ->assertOk()->assertJson(['size' => 3, 'path' => '']);
        $this->call('POST', $base.'/upload?path=public_html&name=logo.png&upload=up-1&offset=3&final=1', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'def')
            ->assertOk()->assertJson(['path' => 'public_html/logo.png']);
        $calls = $this->agent->called('files.upload');
        $this->assertSame([0, 3], array_column($calls, 'offset'));
        $this->assertSame([false, true], array_column($calls, 'final'));
        $this->assertSame('def', base64_decode($calls[1]['data']));

        $this->call('POST', $base.'/upload?path=&name=buyuk.iso&upload=up-2&offset='.(FileManager::MAX_UPLOAD - 1), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'ab')
            ->assertStatus(422)->assertJson(['error' => 'Dosyalar en fazla 2 GB olabilir. Daha büyükleri SFTP ile yükleyin.']);

        $response = $this->get($base.'/download?path=public_html/index.php')->assertOk();
        $this->assertSame('merhaba', $response->streamedContent());
        $this->assertStringContainsString('index.php', $response->headers->get('Content-Disposition'));

        $this->agent->answers['files.read'] = AgentException::rpcError('files.read', FileManager::ERROR, 'outside: ../../etc/passwd: leaves the site');
        $this->get($base.'/download?path=../../etc/passwd')->assertNotFound();
    }

    public function test_a_full_disk_refuses_uploads_but_not_ftp_accounts(): void
    {
        $customer = Customer::factory()->create(['username' => 'kahve2']);
        $this->site->systemUser->update(['customer_id' => $customer->id, 'disk_bytes' => 30 * 1024 ** 3]);

        $this->call('POST', '/sites/'.$this->site->id.'/files/upload?path=&name=a.txt&upload=up-3&offset=0', [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'a')
            ->assertStatus(422)->assertJsonFragment(['error' => 'Disk kotası doldu (30 GB / 20 GB). Yer açın ya da paketi yükseltin.']);
        $this->assertSame([], $this->agent->called('files.upload'));
        $this->assertNull(app(FtpAccounts::class)->refusal($this->site->systemUser->fresh()));
    }

    public function test_old_trash_is_emptied_daily_on_every_site(): void
    {
        $this->agent->answers['files.trash.purge'] = ['deleted' => 3];
        Site::factory()->create(['domain' => 'ikinci.com.tr']);

        $this->artisan('serfex:files-trash')->expectsOutput('Çöp kutularından 6 öğe silindi.')->assertSuccessful();
        $this->assertSame([7, 7], array_column($this->agent->called('files.trash.purge'), 'days'));
    }

    // --- FTP hesapları ---

    public function test_adds_an_account_and_shows_its_password_once(): void
    {
        Livewire::test(SiteFtp::class, ['site' => $this->site->id])
            ->assertSee('Henüz FTP hesabı yok')
            ->call('openCreate')
            ->assertSet('directory', 'public_html')
            ->set('suffix', 'deploy')
            ->set('password', 'Uzun-Parola-2026')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('credentials.Kullanıcı', 'kahve_deploy')
            ->assertSet('credentials.Parola', 'Uzun-Parola-2026')
            ->assertSet('credentials.Klasör', '/public_html')
            ->assertSee('Bağlantı bilgileri');

        $this->assertSame([[
            'name' => 'kahve_deploy', 'user' => 'kahve', 'domain' => 'kahveatolyesi.com.tr', 'dir' => 'public_html', 'locked' => false, 'password' => 'Uzun-Parola-2026',
        ]], $this->agent->called('ftp.account.apply'));
        $account = FtpAccount::sole();
        $this->assertSame('public_html', $account->directory);
        // A missing folder is made first, as the site's user.
        $this->assertSame([['user' => 'kahve', 'domain' => 'kahveatolyesi.com.tr', 'path' => '', 'name' => 'public_html']], $this->agent->called('files.mkdir'));
        // The password is never stored.
        $this->assertStringNotContainsString('Uzun-Parola-2026', json_encode($account->getAttributes()));

        // The list shows it without the password.
        Livewire::test(SiteFtp::class, ['site' => $this->site->id])
            ->assertSee('kahve_deploy')
            ->assertDontSee('Uzun-Parola-2026');
    }

    public function test_a_laravel_site_defaults_to_the_whole_site_folder(): void
    {
        LaravelApp::factory()->create(['site_id' => $this->site->id]);
        Livewire::test(SiteFtp::class, ['site' => $this->site->id])
            ->call('openCreate')
            ->assertSet('directory', '');
    }

    public function test_refuses_bad_names_folders_passwords_and_taken_names(): void
    {
        $this->account();
        $page = Livewire::test(SiteFtp::class, ['site' => $this->site->id])->call('openCreate');
        $page->set('suffix', 'Deploy!')->call('save')->assertHasErrors('suffix');
        $page->set('suffix', 'deploy')->call('save')->assertHasErrors(['suffix' => 'Bu ad kullanılıyor.']);
        $page->set('suffix', 'kahve')->set('directory', '../..')->call('save')->assertHasErrors('directory');
        $page->set('directory', 'public_html')->set('password', 'kisa')->call('save')->assertHasErrors('password');
        // Site accounts' Linux names are taken too.
        $page->set('suffix', str_repeat('a', 40))->set('password', 'Uzun-Parola-2026')->call('save')->assertHasErrors('suffix');
        $this->assertSame([], $this->agent->called('ftp.account.apply'));
    }

    public function test_a_server_refusal_keeps_nothing(): void
    {
        $this->agent->answers['ftp.account.apply'] = AgentException::rpcError('ftp.account.apply', -32602, 'folder public_html/yok does not exist in the site');

        Livewire::test(SiteFtp::class, ['site' => $this->site->id])
            ->call('openCreate')
            ->set('suffix', 'deploy')
            ->set('directory', 'public_html/yok')
            ->call('save')
            ->assertHasErrors('suffix')
            ->assertSee('public_html/yok does not exist');
        $this->assertSame(0, FtpAccount::withTrashed()->count());
    }

    public function test_edits_the_folder_without_touching_the_password_or_sets_a_new_one(): void
    {
        $account = $this->account();
        $page = Livewire::test(SiteFtp::class, ['site' => $this->site->id])
            ->call('openEdit', $account->id)
            ->assertSet('suffix', 'deploy')
            ->set('directory', '/public_html/uploads/')
            ->call('save')
            ->assertSet('credentials', null)
            ->assertDispatched('toast', message: 'kahve_deploy kaydedildi.');
        $this->assertSame('public_html/uploads', $account->fresh()->directory);
        $this->assertSame(['public_html', 'uploads'], array_column($this->agent->called('files.mkdir'), 'name'));
        $this->assertArrayNotHasKey('password', $this->agent->called('ftp.account.apply')[0]);

        $page->call('openEdit', $account->id)
            ->set('changePassword', true)
            ->set('password', 'Yeni-Parola-2026!')
            ->call('save')
            ->assertSet('credentials.Parola', 'Yeni-Parola-2026!');
        $this->assertSame('Yeni-Parola-2026!', $this->agent->called('ftp.account.apply')[1]['password']);
    }

    public function test_delete_locks_at_once_undo_brings_it_back_and_the_purge_removes_it(): void
    {
        $account = $this->account();
        Livewire::test(SiteFtp::class, ['site' => $this->site->id])
            ->call('confirmDelete', $account->id)
            ->assertSee('silinsin mi?')
            ->call('delete')
            ->assertDispatched('toast', fn ($name, $p) => $p['undo'] === ['method' => 'restore', 'args' => [$account->id]])
            ->assertDontSee('kahve_deploy');
        $this->assertTrue($account->fresh()->trashed());
        $this->assertTrue($this->agent->called('ftp.account.apply')[0]['locked']);
        Queue::assertPushed(PurgeFtpAccount::class, fn ($job) => $job->accountId === $account->id && $job->delay !== null);

        Livewire::test(SiteFtp::class, ['site' => $this->site->id])
            ->call('restore', $account->id)
            ->assertSee('kahve_deploy');
        $this->assertFalse($account->fresh()->trashed());
        $this->assertFalse($this->agent->called('ftp.account.apply')[1]['locked']);
        // The purge after an Undo does nothing.
        (new PurgeFtpAccount($account->id))->handle(app(FtpAccounts::class));
        $this->assertSame([], $this->agent->called('ftp.account.delete'));

        app(FtpAccounts::class)->delete($account->fresh());
        (new PurgeFtpAccount($account->id))->handle(app(FtpAccounts::class));
        $this->assertSame([['name' => 'kahve_deploy']], $this->agent->called('ftp.account.delete'));
        $this->assertSame(0, FtpAccount::withTrashed()->count());
    }

    public function test_deleting_the_site_removes_its_accounts_first(): void
    {
        $this->account();
        $this->account('kahve_eski')->delete();

        (new DeleteSite($this->site))->handle($this->agent, app(Zones::class));

        $methods = $this->agent->methods();
        $this->assertSame(['ftp.account.delete', 'ftp.account.delete', 'site.delete'], array_values(array_filter($methods, fn ($m) => in_array($m, ['ftp.account.delete', 'site.delete'], true))));
        $this->assertSame(0, FtpAccount::withTrashed()->count());
    }

    // --- Customers ---

    public function test_customers_get_both_tools_for_their_own_sites_only_within_the_package(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $customer->package->update(['max_ftp_accounts' => 1]);
        $mine = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        $this->actingAs($customer->user);

        $this->get('/sites/'.$mine->id.'/files')->assertOk()->assertSee('adapansiyon.com');
        $this->get('/sites/'.$mine->id.'/ftp')->assertOk()->assertSee('ada_');
        $this->get('/sites/'.$this->site->id.'/files')->assertNotFound();
        $this->get('/sites/'.$this->site->id.'/ftp')->assertNotFound();
        $this->getJson('/sites/'.$this->site->id.'/files/text?path=public_html/index.php')->assertNotFound();
        $this->get('/sites/'.$this->site->id.'/files/download?path=public_html/index.php')->assertNotFound();
        $this->assertSame([], array_filter($this->agent->called('files.text'), fn ($p) => $p['domain'] === 'kahveatolyesi.com.tr'));

        $page = Livewire::test(SiteFtp::class, ['site' => $mine->id])
            ->call('openCreate')
            ->set('suffix', 'web')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('ada_web', FtpAccount::sole()->name);
        $page->set('credentials', null)
            ->call('openCreate')
            ->assertSet('editing', false)
            ->assertDispatched('toast', fn ($name, $p) => str_contains($p['message'], 'FTP hesabı'));

        // Someone else's account is out of reach by id.
        $theirs = $this->account();
        $page->call('openEdit', $theirs->id)->assertNotFound();
    }

    public function test_suspending_the_customer_locks_their_accounts(): void
    {
        $customer = Customer::factory()->create(['username' => 'ada']);
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        FtpAccount::create(['site_id' => $site->id, 'system_user_id' => $site->system_user_id, 'name' => 'ada_web', 'directory' => 'public_html']);

        app(Customers::class)->suspend($customer, 'Ödeme gecikti');
        Queue::assertPushed(SyncFtpAccounts::class, fn ($job) => $job->systemUserId === $site->system_user_id);
        (new SyncFtpAccounts($site->system_user_id))->handle(app(FtpAccounts::class));
        $this->assertTrue($this->agent->called('ftp.account.apply')[0]['locked']);
        $this->assertArrayNotHasKey('password', $this->agent->called('ftp.account.apply')[0]);

        app(Customers::class)->unsuspend($customer->fresh());
        (new SyncFtpAccounts($site->system_user_id))->handle(app(FtpAccounts::class));
        $this->assertFalse($this->agent->called('ftp.account.apply')[1]['locked']);
    }
}
