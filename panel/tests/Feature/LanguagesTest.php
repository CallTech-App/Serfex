<?php

namespace Tests\Feature;

use App\Livewire\Account\AccountSecurity;
use App\Livewire\Settings\GeneralSettings;
use App\Mail\PanelMail;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DnsZone;
use App\Models\LaravelApp;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Audit\AuditLog;
use App\Services\Customers\Customers;
use App\Services\Settings\Notifications;
use App\Services\Settings\PanelSettings;
use App\Support\Bytes;
use App\Support\Locales;
use App\Support\Num;
use App\Support\When;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Languages: Turkish and English, the server's default, each person's own
 * choice, the browser's before signing in, formats, mail and webhooks in
 * the reader's language, and English pages with no Turkish left in them.
 */
class LanguagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Turkish words that give away an untranslated text on an English
     * page (whole words, case sensitive).
     */
    private const TURKISH_WORDS = ['ve', 'bir', 'için', 'ile', 'yok', 'bu', 'olarak', 'değil', 'henüz', 'yeni', 'Kaydet', 'Ekle', 'İptal', 'Sil',
        'gün', 'saat', 'dakika', 'Siteler', 'Müşteriler', 'Yedekler', 'Hizmetler', 'Güvenlik', 'Ayarlar', 'Bugün', 'Dün', 'sunucu', 'Sunucu',
        'dosya', 'kullanıcı', 'yükleniyor', 'bekleniyor', 'başarısız', 'Genel', 'Durum', 'Ara', 'Kapat', 'Geri', 'al', 'Tümü', 'Yok', 'Var'];

    private FakeAgent $agent;

    /** @var array<string, list<string>> url => Turkish found on it */
    private array $turkish = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['serfex.disk_paths' => []]);
        $this->agent = new FakeAgent([
            'php.versions' => ['8.3', '8.4'],
            'system.addresses' => ['ipv4' => ['203.0.113.10'], 'ipv6' => []],
            'system.info' => ['hostname' => 'srv-01', 'os' => 'Ubuntu 24.04.3 LTS', 'kernel' => '6.8.0-60-generic', 'cpus' => 8, 'memory_bytes' => 16 * 1024 ** 3,
                'timezone' => 'UTC', 'uptime_seconds' => 86400, 'addresses' => ['ipv4' => ['203.0.113.10'], 'ipv6' => []]],
            'system.timezones' => ['Europe/Istanbul', 'UTC'],
            'system.timezone.set' => fn ($p) => ['timezone' => $p['timezone']],
            'dns.status' => ['installed' => true, 'running' => true],
            'mail.status' => ['installed' => false, 'configured' => false, 'running' => false, 'services' => []],
            'site.logs' => fn ($p) => ['kind' => $p['kind'], 'exists' => true, 'size' => 0, 'content' => '', 'truncated' => false],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    public function test_the_sign_in_page_reads_the_server_default_until_a_language_is_picked(): void
    {
        // The browser's language does not decide.
        $this->get('/login', ['Accept-Language' => 'en-US,en;q=0.9'])->assertOk()->assertSee('Panele giriş yapın');

        app(PanelSettings::class)->setLocale('en');
        $this->get('/login', ['Accept-Language' => 'tr-TR,tr;q=0.9'])->assertOk()
            ->assertSee('Sign in to the panel')->assertSee('<html lang="en"', false);

        // Picked on the page, it stays for the session.
        $this->get('/login?lang=tr')->assertOk()->assertSee('Panele giriş yapın');
        $this->get('/login')->assertOk()->assertSee('Panele giriş yapın');
        $this->get('/login?lang=xx')->assertOk()->assertSee('Panele giriş yapın');
    }

    public function test_a_new_server_reads_english(): void
    {
        config(['serfex.locale' => null]);
        $this->assertSame('en', Locales::serverDefault());
        $this->get('/login', ['Accept-Language' => 'tr-TR,tr;q=0.9'])->assertOk()->assertSee('Sign in to the panel');
    }

    public function test_after_sign_in_the_person_s_own_language_wins_then_the_server_default(): void
    {
        $admin = User::factory()->create(['role' => 'full', 'locale' => null]);
        $this->actingAs($admin);

        $this->get('/', ['Accept-Language' => 'en'])->assertOk()->assertSee('Genel Bakış');

        app(PanelSettings::class)->setLocale('en');
        $this->get('/')->assertOk()->assertSee('Overview')->assertDontSee('Genel Bakış');

        $admin->forceFill(['locale' => 'tr'])->save();
        $this->get('/', ['Accept-Language' => 'en'])->assertOk()->assertSee('Genel Bakış');
    }

    public function test_error_pages_are_in_the_reader_s_language(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertSee('Sayfa bulunamadı');
        $this->get('/no-such-page?lang=en')->assertNotFound()->assertSee('Page not found');

        $this->actingAs(User::factory()->create(['role' => 'full', 'locale' => 'en']));
        $this->get('/no-such-page')->assertNotFound()->assertSee('Page not found');
    }

    public function test_the_activity_log_shows_the_panel_s_own_sentences_in_the_reader_s_language(): void
    {
        $sam = User::factory()->create(['role' => 'full', 'locale' => 'en']);
        $ayse = User::factory()->create(['role' => 'full', 'locale' => 'tr']);
        AuditLog::record('Signed in to the panel.', $sam);
        AuditLog::record('Panele giriş yaptı.', $ayse);
        AuditLog::record('acme.example sitesi oluşturuldu.', $ayse);

        $this->actingAs($ayse)->get(route('settings.audit'))->assertOk()->assertDontSee('Signed in to the panel.')->assertSee('acme.example sitesi oluşturuldu.');
        $this->actingAs($sam)->get(route('settings.audit'))->assertOk()->assertDontSee('Panele giriş yaptı.')->assertSee('Signed in to the panel.');
    }

    public function test_the_server_default_is_saved_in_general_settings_with_undo(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'full']));

        $this->get(route('settings.general'))->assertOk()->assertSee('Dil')->assertSee('English');
        $page = Livewire::test(GeneralSettings::class)
            ->set('locale', 'en')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('en', Setting::get('panel.locale'));
        $this->assertSame('en', config('serfex.locale'));

        $page->call('undoSave', ['hostname' => null, 'timezone' => 'UTC', 'locale' => 'tr', 'session' => 120]);
        $this->assertSame('tr', Setting::get('panel.locale'));

        Livewire::test(GeneralSettings::class)->set('locale', 'de')->call('save')->assertHasErrors('locale');
    }

    public function test_a_person_picks_their_language_on_their_account_page_with_undo(): void
    {
        $admin = User::factory()->create(['role' => 'full']);
        $this->actingAs($admin);

        Livewire::test(AccountSecurity::class)
            ->set('locale', 'en')
            ->call('saveLocale')
            ->assertHasNoErrors()
            ->assertRedirect(route('account.security'));
        $this->assertSame('en', $admin->fresh()->locale);
        $this->assertSame('Language saved.', session('toast')['message']);
        $this->assertSame(['undoLocale', [null]], [session('toast')['undo']['method'], session('toast')['undo']['args']]);

        Livewire::test(AccountSecurity::class)->call('undoLocale', null);
        $this->assertNull($admin->fresh()->locale);

        Livewire::test(AccountSecurity::class)->set('locale', 'xx')->call('saveLocale')->assertHasErrors('locale');
    }

    public function test_a_customer_has_their_own_language(): void
    {
        $customer = Customer::factory()->withLogin()->create(['company' => 'Acme Ltd', 'username' => 'acme']);
        Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'acme.example']);
        $customer->user->forceFill(['locale' => 'en'])->save();
        $this->actingAs($customer->user);

        $this->get('/sites')->assertOk()->assertSee('acme.example')->assertSee('Sites')->assertDontSee('Siteler');
    }

    public function test_dates_numbers_and_sizes_follow_the_language(): void
    {
        config(['serfex.timezone' => 'Europe/Istanbul']);
        $at = Carbon::parse('2026-10-20 11:05:00', 'UTC');

        Locales::apply('tr');
        $this->assertSame('20.10.2026 14:05', When::dateTime($at));
        $this->assertSame('1.234,5', Num::format(1234.5, 1));
        $this->assertSame('1,2 GB', Bytes::format((int) (1.2 * 1024 ** 3)));
        $this->assertSame('%85', Num::percent(85));

        Locales::apply('en');
        $this->assertSame('Oct 20, 2026 2:05 PM', When::dateTime($at));
        $this->assertSame('1,234.5', Num::format(1234.5, 1));
        $this->assertSame('1.2 GB', Bytes::format((int) (1.2 * 1024 ** 3)));
        $this->assertSame('85%', Num::percent(85));
    }

    public function test_plurals_labels_and_turkish_capitals(): void
    {
        Locales::apply('en');
        // The number among the values picks the form, also through __().
        $this->assertSame('1 site could not be restored.', trans_choice(':count site geri yüklenemedi.', 1));
        $this->assertSame('3 sites could not be restored.', trans_choice(':count site geri yüklenemedi.', 3));
        $this->assertSame('1 problem', __(':count sorun', ['count' => 1]));
        $this->assertSame('1,234 problems', __(':count sorun', ['count' => '1,234']));
        // Fixed labels given as values come out in the reader's language.
        $this->assertSame('Getting a Let\'s Encrypt certificate', Locales::label('Let\'s Encrypt sertifikası alınıyor'));
        $this->assertSame('Other processes', (string) Locales::html(':name', ['name' => 'Diğer süreçler']));
        // Text the panel doesn't know is shown as it is.
        $this->assertSame('kahve.com.tr', Locales::label('kahve.com.tr'));

        Locales::apply('tr');
        $errors = Validator::make(['ip' => ''], ['ip' => 'required'], [], ['ip' => 'izinli IP'])->errors();
        $this->assertStringStartsWith('İzinli IP', $errors->first('ip'));
    }

    public function test_mail_and_webhooks_go_out_in_the_reader_s_language(): void
    {
        Mail::fake();
        Http::fake(['hooks.example.com/*' => Http::response('', 204)]);
        User::factory()->create(['role' => 'full', 'email' => 'ayse@example.com', 'locale' => 'tr']);
        User::factory()->create(['role' => 'full', 'email' => 'sam@example.com', 'locale' => 'en']);
        $notifications = app(Notifications::class);
        $notifications->save(['emails' => ['ayse@example.com', 'sam@example.com'], 'webhook_url' => 'https://hooks.example.com/serfex', 'webhook_locale' => 'en']);
        Setting::put('panel.mail', ['mode' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => null, 'from_address' => '', 'from_name' => 'Serfex']);
        Certificate::factory()->create(['site_id' => null, 'kind' => 'panel', 'name' => 'panel', 'not_after' => now()->addDays(3), 'renew_error' => 'DNS']);

        $this->assertSame(['opened' => 1, 'resolved' => 0], $notifications->check());

        Mail::assertSent(PanelMail::class, 2);
        Mail::assertSent(PanelMail::class, fn ($m) => $m->hasTo('ayse@example.com') && str_contains($m->title, 'sertifikası'));
        Mail::assertSent(PanelMail::class, fn ($m) => $m->hasTo('sam@example.com') && str_contains($m->title, 'certificate') && ! preg_match('/[çğıöşüİ]/u', $m->title.$m->body));
        Http::assertSent(fn ($request) => str_contains(json_decode($request->body(), true)['problems'][0]['title'], 'certificate'));
    }

    public function test_the_translations_are_complete(): void
    {
        $this->artisan('serfex:lang-check', ['--agent' => base_path('../agent')])->assertSuccessful();
    }

    public function test_english_admin_pages_have_no_turkish_left(): void
    {
        $admin = User::factory()->create(['name' => 'Alex Morgan', 'email' => 'alex@example.com', 'role' => 'full', 'locale' => 'en']);
        $site = Site::factory()->create(['domain' => 'example.com']);
        $zone = DnsZone::factory()->create(['name' => 'example.com', 'site_id' => $site->id]);
        $mail = MailDomain::factory()->create(['name' => 'example.com', 'site_id' => $site->id]);
        $database = Database::factory()->forSite($site, 'shop')->create();
        $app = LaravelApp::factory()->for($site)->create();
        $customer = Customer::factory()->create(['company' => 'Acme Ltd', 'name' => 'Sam Lee', 'username' => 'acme']);
        $customer->package->update(['name' => 'Standard']);
        Certificate::factory()->create(['site_id' => $site->id, 'domains' => ['example.com']]);
        $this->actingAs($admin);

        $routes = ['dashboard', 'sites.index', 'dns.index', 'mail.index', 'databases.index', 'ssl.index', 'laravel.index', 'customers.index',
            'monitor.index', 'security.index', 'backups.index', 'services.index', 'components.index', 'ai.index', 'account.security',
            'settings.general', 'settings.server', 'settings.notifications', 'settings.admins', 'settings.api', 'settings.audit', 'settings.updates',
            'ai.settings', 'ai.audit', 'dns.settings', 'mail.settings', 'ssl.settings', 'databases.settings'];
        $urls = array_map(fn ($route) => route($route), $routes);
        foreach (['sites.show', 'sites.ssl', 'sites.domains', 'sites.php', 'sites.cron', 'sites.errors', 'sites.stats', 'sites.files', 'sites.ftp',
            'sites.logs', 'sites.disk', 'sites.protect', 'sites.waf', 'sites.scan', 'sites.directives'] as $route) {
            $urls[] = route($route, $site);
        }
        array_push($urls, route('dns.show', $zone), route('mail.show', $mail), route('databases.show', $database), route('laravel.show', $app),
            route('customers.show', $customer), route('customers.usage', $customer));
        foreach ($urls as $url) {
            $this->checkEnglish($url);
        }
        // Turkish has apostrophes too ("AI'ya sor"): none of it escaped twice.
        $admin->forceFill(['locale' => 'tr'])->save();
        foreach ($urls as $url) {
            $this->checkEscaping($url, (string) $this->get($url)->assertOk()->getContent());
        }
        $this->assertSame([], $this->turkish);
    }

    public function test_english_sign_in_and_customer_pages_have_no_turkish_left(): void
    {
        $this->checkEnglish('/login?lang=en');

        $customer = Customer::factory()->withLogin()->create(['company' => 'Acme Ltd', 'name' => 'Sam Lee', 'username' => 'acme']);
        $customer->package->update(['name' => 'Standard']);
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'acme.example']);
        $customer->user->forceFill(['locale' => 'en'])->save();
        $this->actingAs($customer->user);

        foreach (['/', '/sites', route('sites.show', $site), route('usage'), route('account.security')] as $url) {
            $this->checkEnglish($url);
        }
        $this->assertSame([], $this->turkish);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function checkEnglish(string $url, array $headers = []): void
    {
        $html = $this->get($url, $headers)->assertOk()->getContent();
        $text = str_replace('Türkçe', '', $this->visibleText((string) $html));
        $this->checkEscaping($url, (string) $html);
        $problems = [];
        foreach (['/[çğıöşüÇĞİÖŞÜ]/u', ...array_map(fn ($word) => '/(?<![\p{L}\d.\/-])'.preg_quote($word, '/').'(?![\p{L}\d.\/-])/u', self::TURKISH_WORDS)] as $pattern) {
            if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [, $at]) {
                    $problems[] = trim((string) preg_replace('/\s+/', ' ', mb_strcut($text, max(0, $at - 60), 120)));
                }
            }
        }
        if ($problems !== []) {
            $this->turkish[$url] = [...$this->turkish[$url] ?? [], ...array_values(array_unique($problems))];
        }
    }

    /**
     * Text escaped twice (a translated component attribute) shows its
     * entities on the page.
     */
    private function checkEscaping(string $url, string $html): void
    {
        if (preg_match('/&amp;(#0?39|quot|amp|lt|gt);/', $html, $m)) {
            $this->turkish[$url.' ('.app()->getLocale().')'][] = 'escaped twice: '.$m[0];
        }
    }

    /**
     * The page's text and its title, placeholder, aria-label and alt
     * attributes, without scripts, styles and Livewire's data.
     */
    private function visibleText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|template)\b[^>]*>.*?</\1>#si', ' ', $html);
        preg_match_all('/\s(?:title|placeholder|aria-label|alt)="([^"]*)"/i', $html, $m);
        $text = strip_tags((string) preg_replace('/<[^>]+>/', ' ', $html));

        return html_entity_decode($text.' '.implode(' ', $m[1]), ENT_QUOTES | ENT_HTML5);
    }
}
