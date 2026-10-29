<?php

namespace Tests\Feature;

use App\Livewire\Account\AccountProfile;
use App\Livewire\Account\AccountSecurity;
use App\Mail\PanelMail;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Dns\DnsSettings;
use App\Services\Mail\ReverseDns;
use App\Services\Settings\Notifications;
use App\Services\Setup\FirstRun;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->app->instance(AgentClient::class, new FakeAgent([
            'system.addresses' => ['ipv4' => ['203.0.113.10'], 'ipv6' => []],
        ]));
        Setting::put('panel.mail', ['mode' => 'smtp', 'host' => 'smtp.serfex.test', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => null, 'from_address' => '', 'from_name' => 'Serfex']);
        Setting::put('panel.hostname', 'srv1.serfex.test');
        app(FirstRun::class)->complete();
    }

    /**
     * The confirmation link in the last mail sent to $email.
     */
    private function mailedLink(string $email): string
    {
        $mail = Mail::sent(PanelMail::class, fn (PanelMail $m) => $m->hasTo($email))->last();
        $this->assertNotNull($mail, 'no mail to '.$email);
        $this->assertSame(1, preg_match('#https://srv1\.serfex\.test:8443(/account/email/confirm/[A-Za-z0-9]+)#', $mail->body, $m), $mail->body);

        return $m[1];
    }

    public function test_every_account_reaches_its_details_from_the_sidebar(): void
    {
        $admin = User::factory()->create(['name' => 'Sercan Yılmaz', 'email' => 'sercan@example.test', 'role' => 'full']);
        $this->actingAs($admin);

        $this->get('/sites')->assertOk()->assertSee(route('account.profile'), false);
        $this->get('/account')->assertOk()
            ->assertSee('Hesap bilgileri')->assertSee('sercan@example.test')->assertSee('Tam yönetici')
            ->assertDontSee('Telefon');
        $this->get('/account/security')->assertOk()->assertSee('Parolayı değiştir')->assertSee(route('account.profile'), false);

        $customer = Customer::factory()->withLogin()->create(['company' => 'Acme Ltd', 'name' => 'Ayşe Kaya', 'phone' => '+90 555 000 00 00']);
        $this->actingAs($customer->user);
        $this->get('/account')->assertOk()->assertSee('Telefon')->assertSee('+90 555 000 00 00')->assertSee('Acme Ltd');
    }

    public function test_a_person_renames_themselves_with_undo_and_a_customer_s_record_follows(): void
    {
        $customer = Customer::factory()->withLogin()->create(['name' => 'Ayse Kaya']);
        $user = $customer->user;
        $this->actingAs($user);

        $page = Livewire::test(AccountProfile::class)
            ->set('name', '  Ayşe   Kaya ')
            ->call('saveName')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'Adınız Ayşe Kaya olarak kaydedildi.', undo: ['method' => 'undoName', 'args' => ['Ayse Kaya']]);
        $this->assertSame('Ayşe Kaya', $user->fresh()->name);
        $this->assertSame('Ayşe Kaya', $customer->fresh()->name);
        $this->assertTrue(AuditEntry::where('message', 'Adınız Ayşe Kaya olarak kaydedildi.')->where('section', 'Hesap bilgileri')->exists());

        $page->call('undoName', 'Ayse Kaya');
        $this->assertSame('Ayse Kaya', $user->fresh()->name);
        $this->assertSame('Ayse Kaya', $customer->fresh()->name);

        Livewire::test(AccountProfile::class)->set('name', ' ')->call('saveName')->assertHasErrors('name');
    }

    public function test_a_customer_edits_their_phone(): void
    {
        $customer = Customer::factory()->withLogin()->create(['phone' => null]);
        $this->actingAs($customer->user);

        Livewire::test(AccountProfile::class)->set('phone', '+90 (212) 555-01-02')->call('savePhone')->assertHasNoErrors();
        $this->assertSame('+90 (212) 555-01-02', $customer->fresh()->phone);
        Livewire::test(AccountProfile::class)->call('undoPhone', null);
        $this->assertNull($customer->fresh()->phone);
        Livewire::test(AccountProfile::class)->set('phone', 'call me')->call('savePhone')->assertHasErrors('phone');
    }

    public function test_a_new_address_counts_only_once_the_mailed_link_is_opened(): void
    {
        $admin = User::factory()->create(['name' => 'Sercan', 'email' => 'sercan@exmaple.test', 'role' => 'full', 'password' => 'correct-horse-1']);
        Setting::put(Notifications::KEY, ['emails' => ['sercan@exmaple.test', 'ops@example.test']]);
        Setting::put('ssl.email', 'sercan@exmaple.test');
        DB::table('password_reset_tokens')->insert(['email' => 'sercan@exmaple.test', 'token' => 'x', 'created_at' => now()]);
        $this->actingAs($admin);

        // The current password is asked, and the address must be free.
        User::factory()->create(['email' => 'taken@example.test']);
        Livewire::test(AccountProfile::class)->call('openEmail')
            ->set('newEmail', 'Taken@Example.test')->set('password', 'correct-horse-1')->call('changeEmail')->assertHasErrors('newEmail')
            ->set('newEmail', 'sercan@example.test')->set('password', 'wrong')->call('changeEmail')->assertHasErrors('password')
            ->set('newEmail', 'SERCAN@example.test ')->set('password', 'correct-horse-1')->call('changeEmail')->assertHasNoErrors()
            ->assertSet('changingEmail', false)
            ->assertDispatched('toast', message: 'sercan@example.test adresine doğrulama bağlantısı gönderildi.');

        $admin->refresh();
        $this->assertSame('sercan@exmaple.test', $admin->email);
        $this->assertSame('sercan@example.test', $admin->pending_email);
        Mail::assertSent(PanelMail::class, fn (PanelMail $m) => $m->hasTo('sercan@example.test') && $m->title === 'Serfex e-posta adresinizi doğrulayın' && str_contains($m->body, '24 saat'));
        $this->get('/account')->assertSeeText('sercan@example.test adresine doğrulama bağlantısı gönderildi');

        // Opening the link shows a button; only pressing it changes the address.
        $link = $this->mailedLink('sercan@example.test');
        $this->get($link)->assertOk()->assertSee('Adresi doğrula')->assertSee('sercan@example.test');
        $this->assertSame('sercan@exmaple.test', $admin->fresh()->email);
        $this->post($link)->assertRedirect(route('account.profile'))->assertSessionHas('toast', ['message' => 'E-posta adresiniz sercan@example.test olarak değişti.']);

        $admin->refresh();
        $this->assertSame('sercan@example.test', $admin->email);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNull($admin->pending_email);
        $this->assertSame(['sercan@example.test', 'ops@example.test'], Setting::get(Notifications::KEY)['emails']);
        $this->assertSame('sercan@example.test', Setting::get('ssl.email'));
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'sercan@exmaple.test')->count());
        $this->assertTrue(AuditEntry::where('user_id', $admin->id)->where('message', 'E-posta adresini sercan@exmaple.test yerine sercan@example.test yaptı; yeni adres doğrulandı.')->exists());
        Mail::assertSent(PanelMail::class, fn (PanelMail $m) => $m->hasTo('sercan@exmaple.test') && $m->title === 'Serfex e-posta adresiniz değişti');

        // A used link does nothing.
        $this->post($link)->assertOk()->assertSee('Bağlantı çalışmıyor');
        $this->get($link)->assertOk()->assertSee('Bağlantı çalışmıyor');

        // The new address signs in.
        auth()->logout();
        $this->post('/login', ['email' => 'sercan@example.test', 'password' => 'correct-horse-1'])->assertRedirect();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_the_link_works_signed_out_and_a_customer_s_record_follows(): void
    {
        $customer = Customer::factory()->withLogin()->create(['email' => 'old@example.test']);
        $user = $customer->user;
        $user->forceFill(['password' => 'customer-pass-1'])->save();
        $this->actingAs($user);
        Livewire::test(AccountProfile::class)->set('newEmail', 'new@example.test')->set('password', 'customer-pass-1')->call('changeEmail')->assertHasNoErrors();
        $link = $this->mailedLink('new@example.test');

        auth()->logout();
        $this->post($link)->assertRedirect(route('login'))->assertSessionHas('status', fn ($s) => str_contains($s, 'new@example.test'));
        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->assertSame('new@example.test', $customer->fresh()->email);
    }

    public function test_links_expire_can_be_resent_and_cancelled(): void
    {
        $user = User::factory()->create(['email' => 'a@example.test', 'password' => 'password-123456', 'role' => 'full']);
        $this->actingAs($user);
        Livewire::test(AccountProfile::class)->set('newEmail', 'b@example.test')->set('password', 'password-123456')->call('changeEmail')->assertHasNoErrors();
        $first = $this->mailedLink('b@example.test');

        // Too soon for another link.
        Livewire::test(AccountProfile::class)->call('resendEmail')->assertDispatched('toast', message: 'Az önce bir bağlantı gönderildi. Biraz sonra yeniden deneyin.');

        $this->travel(2)->minutes();
        Livewire::test(AccountProfile::class)->call('resendEmail')->assertDispatched('toast', message: 'b@example.test adresine doğrulama bağlantısı yeniden gönderildi.');
        $second = $this->mailedLink('b@example.test');
        $this->assertNotSame($first, $second);
        $this->get($first)->assertSee('Bağlantı çalışmıyor');

        $this->travel(25)->hours();
        $this->get($second)->assertSee('Bağlantı çalışmıyor');
        $this->get('/account')->assertSeeText('süresi doldu');

        Livewire::test(AccountProfile::class)->call('cancelEmail')->assertDispatched('toast', message: 'b@example.test adresine geçiş iptal edildi.');
        $this->assertNull($user->fresh()->pending_email);
        $this->assertSame('a@example.test', $user->fresh()->email);
    }

    public function test_the_address_changes_at_once_when_the_panel_can_t_send_mail(): void
    {
        Setting::put('panel.mail', ['mode' => 'smtp', 'host' => '', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => null, 'from_address' => '', 'from_name' => 'Serfex']);
        $user = User::factory()->create(['email' => 'typo@exmaple.test', 'password' => 'password-123456', 'role' => 'full']);
        Setting::put(Notifications::KEY, ['emails' => ['typo@exmaple.test']]);
        $this->actingAs($user);

        $this->get('/account')->assertSeeText('Panel şu an e-posta gönderemiyor');
        Livewire::test(AccountProfile::class)->set('newEmail', 'right@example.test')->set('password', 'password-123456')->call('changeEmail')
            ->assertHasNoErrors()
            ->assertDispatched('toast', message: 'E-posta adresiniz typo@exmaple.test yerine right@example.test oldu. Panel e-posta gönderemediği için yeni adres doğrulanmadı.');
        $this->assertSame('right@example.test', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(['right@example.test'], Setting::get(Notifications::KEY)['emails']);
        Mail::assertNothingSent();
        $this->assertTrue(AuditEntry::where('message', 'like', 'E-posta adresiniz typo@exmaple.test yerine right@example.test oldu.%')->exists());
    }

    public function test_an_administrator_working_as_a_customer_can_t_move_the_customer_s_address(): void
    {
        $admin = User::factory()->create(['role' => 'full']);
        $customer = Customer::factory()->withLogin()->create(['email' => 'c@example.test']);
        $this->actingAs($customer->user)->withSession([Impersonation::KEY => $admin->id]);
        session()->put(Impersonation::KEY, $admin->id);

        Livewire::test(AccountProfile::class)->set('newEmail', 'x@example.test')->set('password', 'password')->call('changeEmail')->assertHasErrors('newEmail');
        $this->assertSame('c@example.test', $customer->user->fresh()->email);
        $this->assertNull($customer->user->fresh()->pending_email);
    }

    public function test_a_read_only_administrator_may_change_their_own_details(): void
    {
        $user = User::factory()->create(['email' => 'ro@example.test', 'role' => 'readonly', 'password' => 'password-123456']);
        Setting::put(Notifications::KEY, ['emails' => ['ro@example.test']]);
        $this->actingAs($user);

        Livewire::test(AccountProfile::class)->set('name', 'Read Only')->call('saveName')->assertHasNoErrors();
        $this->assertSame('Read Only', $user->fresh()->name);
        Livewire::test(AccountProfile::class)->set('newEmail', 'ro2@example.test')->set('password', 'password-123456')->call('changeEmail')->assertHasNoErrors();
        $this->post($this->mailedLink('ro2@example.test'))->assertRedirect(route('account.profile'));
        $this->assertSame('ro2@example.test', $user->fresh()->email);
        $this->assertSame(['ro2@example.test'], Setting::get(Notifications::KEY)['emails']);
    }

    public function test_a_signed_in_person_changes_their_password_and_other_sessions_close(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'p@example.test', 'role' => 'full', 'password' => 'old-password-12']);
        $this->actingAs($user);
        DB::table('sessions')->insert(['id' => 'other-browser', 'user_id' => $user->id, 'ip_address' => '198.51.100.7', 'user_agent' => 'Firefox', 'payload' => '', 'last_activity' => time()]);

        Livewire::test(AccountSecurity::class)->call('openPassword')
            ->set('currentPassword', 'wrong')->set('newPassword', 'new-password-123')->set('newPassword_confirmation', 'new-password-123')
            ->call('changePassword')->assertHasErrors('currentPassword')
            ->set('currentPassword', 'old-password-12')->set('newPassword', 'short')->set('newPassword_confirmation', 'short')
            ->call('changePassword')->assertHasErrors('newPassword')
            ->set('newPassword', 'new-password-123')->set('newPassword_confirmation', 'other-password-1')
            ->call('changePassword')->assertHasErrors('newPassword')
            ->set('newPassword_confirmation', 'new-password-123')
            ->call('changePassword')->assertHasNoErrors()->assertSet('changingPassword', false);

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('id', 'other-browser')->count());
        Mail::assertSent(PanelMail::class, fn (PanelMail $m) => $m->hasTo('p@example.test') && $m->title === 'Serfex parolanız değiştirildi');
        $this->assertTrue(AuditEntry::where('user_id', $user->id)->where('message', 'like', 'Parolanız değiştirildi%')->exists());
    }

    public function test_the_server_changes_an_address_at_once_when_the_link_doesn_t_arrive(): void
    {
        $customer = Customer::factory()->withLogin()->create(['email' => 'typo@exmaple.test']);
        $user = $customer->user;
        $user->forceFill(['pending_email' => 'right@example.test', 'pending_email_token' => str_repeat('a', 64), 'pending_email_at' => now()])->save();
        Setting::put(Notifications::KEY, ['emails' => ['typo@exmaple.test']]);

        $this->artisan('serfex:change-email', ['email' => 'TYPO@exmaple.test', 'new' => 'Right@Example.test', '--json' => true])
            ->expectsOutput('{"old":"typo@exmaple.test","email":"right@example.test"}')
            ->assertSuccessful();
        $user->refresh();
        $this->assertSame('right@example.test', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertSame('right@example.test', $customer->fresh()->email);
        $this->assertSame(['right@example.test'], Setting::get(Notifications::KEY)['emails']);
        $this->assertTrue(AuditEntry::where('user_id', $user->id)->where('actor', 'root')
            ->where('message', 'Sunucuda komut satırıyla e-posta adresi typo@exmaple.test yerine right@example.test yapıldı.')->exists());

        User::factory()->create(['email' => 'taken@example.test']);
        $this->artisan('serfex:change-email', ['email' => 'right@example.test', 'new' => 'taken@example.test', '--json' => true])
            ->expectsOutput('{"error":"Bu e-posta adresiyle bir panel hesabı zaten var."}')->assertFailed();
        $this->artisan('serfex:change-email', ['email' => 'right@example.test', 'new' => 'not-an-address', '--json' => true])->assertFailed();
        $this->artisan('serfex:change-email', ['email' => 'nobody@example.test', 'new' => 'x@example.test'])->assertFailed();
        $this->assertSame('right@example.test', $user->fresh()->email);
    }

    public function test_a_missing_reverse_dns_record_is_named_where_the_panel_s_mail_goes_out(): void
    {
        config(['serfex.mail.check_dns' => true]);
        Setting::put('panel.mail', ['mode' => 'server', 'host' => '', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => null, 'from_address' => '', 'from_name' => 'Serfex']);
        Setting::put('mail.server', ['setup_at' => now()->toIso8601String()]);
        Setting::put('dns.ipv4', '203.0.113.10');
        $reverse = new class(app(DnsSettings::class)) extends ReverseDns
        {
            public ?string $name = null;

            public array $back = [];

            protected function ptr(string $ip): ?string
            {
                return $this->name;
            }

            protected function addresses(string $host): array
            {
                return $this->back;
            }
        };
        $this->app->instance(ReverseDns::class, $reverse);
        $admin = User::factory()->create(['email' => 'a@example.test', 'role' => 'full', 'pending_email' => 'b@example.test', 'pending_email_at' => now()]);
        $this->actingAs($admin);

        $this->get('/account')->assertSeeText('203.0.113.10 adresinin ters DNS (PTR) kaydı yok')
            ->assertSeeText('sudo serfex change-email a@example.test b@example.test');
        $this->get(route('settings.notifications'))->assertSeeText('ters DNS (PTR) kaydı yok');

        $reverse->forget();
        $reverse->name = 'srv1.example.test';
        $this->get('/account')->assertSeeText('srv1.example.test adını gösteriyor');

        $reverse->forget();
        $reverse->back = ['203.0.113.10'];
        $this->get('/account')->assertDontSeeText('ters DNS');

        // Through an SMTP server the PTR record of this server doesn't matter.
        $reverse->forget();
        $reverse->name = null;
        Setting::put('panel.mail', ['mode' => 'smtp', 'host' => 'smtp.serfex.test', 'port' => 587, 'encryption' => 'starttls', 'username' => '', 'password' => null, 'from_address' => '', 'from_name' => 'Serfex']);
        $this->get('/account')->assertDontSeeText('ters DNS');

        // A customer is told to ask, not shown the server's command.
        $customer = Customer::factory()->withLogin()->create();
        $customer->user->forceFill(['pending_email' => 'new@example.test', 'pending_email_at' => now()])->save();
        $this->actingAs($customer->user);
        $this->get('/account')->assertSeeText('sunucu yöneticinizden adresinizi değiştirmesini isteyin')->assertDontSeeText('serfex change-email');
    }
}
