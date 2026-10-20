<?php

namespace Tests\Unit;

use App\Services\Mail\MailPassword;
use Tests\TestCase;

class MailPasswordTest extends TestCase
{
    public function test_hash_is_a_dovecot_sha512_crypt_hash(): void
    {
        $hash = MailPassword::hash('Gizli-parola-42');

        // The agent's pattern (agent/internal/mail/domain.go).
        $this->assertMatchesRegularExpression('/^\{(SHA512-CRYPT|BLF-CRYPT|ARGON2ID)\}\$[^\s:]{10,200}$/', $hash);
        $this->assertStringStartsWith('{SHA512-CRYPT}$6$', $hash);
        $this->assertTrue(MailPassword::verify('Gizli-parola-42', $hash));
        $this->assertFalse(MailPassword::verify('gizli-parola-42', $hash));
        $this->assertNotSame($hash, MailPassword::hash('Gizli-parola-42'), 'each hash has its own salt');
    }

    public function test_generated_passwords_are_strong_and_readable(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $password = MailPassword::generate();
            $this->assertMatchesRegularExpression('/^[a-zA-Z2-9]{4}-[a-zA-Z2-9]{4}-[a-zA-Z2-9]{4}$/', $password);
            $this->assertNull(MailPassword::weakness($password));
            $this->assertDoesNotMatchRegularExpression('/[0O1lI]/', $password);
        }
    }

    public function test_weak_passwords_are_explained(): void
    {
        $this->assertNotNull(MailPassword::weakness('kisa1'));
        $this->assertNotNull(MailPassword::weakness('yalnizcaharf'));
        $this->assertNotNull(MailPassword::weakness('12345678'));
        $this->assertNotNull(MailPassword::weakness('ali-1234-ali', 'ali@kahve.com.tr'));
        $this->assertNotNull(MailPassword::weakness('Siparis2026', 'siparis@kahve.com.tr'));
        $this->assertNull(MailPassword::weakness('Kahve-fincan-7', 'siparis@kahve.com.tr'));
    }
}
