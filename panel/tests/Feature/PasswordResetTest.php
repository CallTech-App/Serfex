<?php

namespace Tests\Feature;

use App\Mail\PanelMail;
use App\Models\AuditEntry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Setup\FirstRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class PasswordResetTest extends TestCase
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
        // An administrator exists, so the sign-in page is not the setup notice.
        app(FirstRun::class)->complete();
    }

    /**
     * The link in the last reset mail sent to $email.
     */
    private function mailedLink(string $email): string
    {
        $mail = Mail::sent(PanelMail::class, fn (PanelMail $m) => $m->hasTo($email))->last();
        $this->assertNotNull($mail, 'no mail to '.$email);
        $this->assertSame(1, preg_match('#https://srv1\.serfex\.test:8443(/reset-password/\S+)#', $mail->body, $m), $mail->body);

        return $m[1];
    }

    public function test_the_sign_in_page_links_to_the_reset_page(): void
    {
        User::factory()->create();
        $this->get('/login')->assertOk()->assertSee('Parolamı unuttum')->assertSee('/forgot-password');
        $this->get('/forgot-password')->assertOk()->assertSee('Parolanızı sıfırlayın')->assertSee('Sıfırlama bağlantısı gönder');
    }

    public function test_a_customer_resets_the_password_from_the_mailed_link(): void
    {
        $user = User::factory()->create(['email' => 'ayse@example.test', 'name' => 'Ayşe', 'is_admin' => false, 'password' => 'old-password-1']);

        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'AYSE@example.test '])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Bu adrese ait bir hesap varsa'));
        Mail::assertSent(PanelMail::class, fn (PanelMail $m) => $m->hasTo('ayse@example.test') && $m->title === 'Serfex parolanızı sıfırlayın' && str_contains($m->body, '60 dakika'));
        $link = $this->mailedLink('ayse@example.test');
        $this->assertTrue(AuditEntry::where('user_id', $user->id)->where('message', 'Parola sıfırlama bağlantısı e-postayla gönderildi.')->exists());

        $this->get($link)->assertOk()->assertSee('Yeni parola belirleyin')->assertSee('En az 10 karakter.');
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $token = basename((string) parse_url($link, PHP_URL_PATH));

        // Too short, and not the same twice.
        $this->post('/reset-password', ['token' => $token, 'email' => $query['email'], 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $token, 'email' => $query['email'], 'password' => 'new-password-22', 'password_confirmation' => 'other-password-2'])
            ->assertSessionHasErrors('password');

        $this->post('/reset-password', ['token' => $token, 'email' => $query['email'], 'password' => 'new-password-22', 'password_confirmation' => 'new-password-22'])
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'Parolanız değiştirildi. Yeni parolanızla giriş yapın.');
        $this->assertGuest();
        $this->assertTrue(Hash::check('new-password-22', $user->fresh()->password));
        $this->assertTrue(AuditEntry::where('user_id', $user->id)->where('message', 'Parolasını sıfırlama bağlantısıyla değiştirdi.')->exists());
        Mail::assertSent(PanelMail::class, fn (PanelMail $m) => $m->hasTo('ayse@example.test') && $m->title === 'Serfex parolanız değiştirildi');

        // The link works once.
        $this->get($link)->assertOk()->assertSee('Bu sıfırlama bağlantısı geçersiz ya da süresi dolmuş.');
        $this->post('/reset-password', ['token' => $token, 'email' => $query['email'], 'password' => 'third-password-3', 'password_confirmation' => 'third-password-3'])
            ->assertRedirect('/forgot-password');
        $this->assertTrue(Hash::check('new-password-22', $user->fresh()->password));
    }

    public function test_two_step_login_is_still_asked_after_a_reset(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
        $this->post('/forgot-password', ['email' => 'admin@example.test']);
        $link = $this->mailedLink('admin@example.test');
        $this->get($link)->assertSee('En az 12 karakter.');
        $token = basename((string) parse_url($link, PHP_URL_PATH));

        // Administrators need 12 characters, as at setup.
        $this->post('/reset-password', ['token' => $token, 'email' => 'admin@example.test', 'password' => 'eleven-char', 'password_confirmation' => 'eleven-char'])
            ->assertSessionHasErrors('password');
        $this->post('/reset-password', ['token' => $token, 'email' => 'admin@example.test', 'password' => 'a-new-admin-password', 'password_confirmation' => 'a-new-admin-password'])
            ->assertRedirect('/login');

        $this->post('/login', ['email' => 'admin@example.test', 'password' => 'a-new-admin-password'])
            ->assertRedirect('/login/two-factor');
        $this->assertGuest();
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_unknown_removed_and_invited_addresses_get_the_same_answer_and_no_mail(): void
    {
        User::factory()->create(['email' => 'gone@example.test', 'removed_at' => now()]);
        User::factory()->create(['email' => 'invited@example.test', 'invite_token' => hash('sha256', 'x'), 'invited_at' => now()]);

        foreach (['nobody@example.test', 'gone@example.test', 'invited@example.test'] as $email) {
            $this->post('/forgot-password', ['email' => $email])
                ->assertSessionHas('status', fn ($s) => str_contains($s, 'Bu adrese ait bir hesap varsa'));
        }
        Mail::assertNothingSent();
    }

    public function test_links_expire_after_an_hour_and_only_the_newest_works(): void
    {
        User::factory()->create(['email' => 'ayse@example.test', 'is_admin' => false]);
        $this->post('/forgot-password', ['email' => 'ayse@example.test']);
        $first = $this->mailedLink('ayse@example.test');

        // A second request within a minute sends nothing.
        $this->post('/forgot-password', ['email' => 'ayse@example.test']);
        Mail::assertSent(PanelMail::class, 1);

        $this->travel(2)->minutes();
        $this->post('/forgot-password', ['email' => 'ayse@example.test']);
        Mail::assertSent(PanelMail::class, 2);
        $second = $this->mailedLink('ayse@example.test');
        $this->assertNotSame($first, $second);
        $this->get($first)->assertSee('Bu sıfırlama bağlantısı geçersiz');
        $this->get($second)->assertSee('Yeni parola belirleyin');

        $this->travel(61)->minutes();
        $this->get($second)->assertSee('Bu sıfırlama bağlantısı geçersiz');
    }

    public function test_the_mail_is_in_the_persons_language(): void
    {
        User::factory()->create(['email' => 'sam@example.test', 'name' => 'Sam', 'locale' => 'en']);
        $this->post('/forgot-password', ['email' => 'sam@example.test']);
        Mail::assertSent(PanelMail::class, fn (PanelMail $m) => $m->title === 'Reset your Serfex password'
            && str_contains($m->body, 'Hello Sam,') && str_contains($m->body, '60 minutes') && ! preg_match('/[çğıöşüİ]/u', $m->title.$m->body));
    }

    public function test_without_mail_the_page_points_to_the_command_line(): void
    {
        User::factory()->create(['email' => 'ayse@example.test']);
        Setting::put('panel.mail', ['mode' => 'smtp', 'host' => '']);

        $this->get('/forgot-password')->assertOk()
            ->assertSee('Bu sunucu şu an e-posta gönderemiyor')
            ->assertSee('sudo serfex reset-password');
        $this->post('/forgot-password', ['email' => 'ayse@example.test'])->assertSessionMissing('status');
        Mail::assertNothingSent();
    }

    public function test_the_command_prints_a_link_for_the_account(): void
    {
        $user = User::factory()->create(['email' => 'ayse@example.test', 'is_admin' => false]);

        $this->artisan('serfex:reset-link', ['email' => 'ayse@example.test', '--json' => true])
            ->expectsOutputToContain('"urls":["https://203.0.113.10:8443/reset-password/')
            ->assertSuccessful();
        $this->artisan('serfex:reset-link', ['email' => 'Ayse@Example.test'])
            ->expectsOutputToContain('https://srv1.serfex.test:8443/reset-password/')
            ->assertSuccessful();
        $this->assertSame(2, AuditEntry::where('user_id', $user->id)->where('via', 'system')->where('actor', 'root')->count());
        Mail::assertNothingSent();

        $this->artisan('serfex:reset-link', ['email' => 'nobody@example.test', '--json' => true])
            ->expectsOutputToContain('"error"')
            ->assertFailed();
    }
}
