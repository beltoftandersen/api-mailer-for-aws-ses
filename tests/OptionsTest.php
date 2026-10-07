<?php
use PHPUnit\Framework\TestCase;
use SesMailer\Support\Options;

class OptionsTest extends TestCase {

    public function test_encrypt_round_trip_uses_random_iv() {
        $a = Options::encrypt_secret('my-secret');
        $b = Options::encrypt_secret('my-secret');
        $this->assertStringStartsWith('enc2:', $a);
        $this->assertNotSame($a, $b, 'Each encryption should use a fresh IV');
        $this->assertSame('my-secret', Options::decrypt_secret($a));
        $this->assertSame('my-secret', Options::decrypt_secret($b));
    }

    public function test_tampered_ciphertext_is_rejected() {
        $enc = Options::encrypt_secret('my-secret');
        $last = substr($enc, -1);
        $tampered = substr($enc, 0, -1) . ($last === '0' ? '1' : '0');
        $this->assertSame('', Options::decrypt_secret($tampered));
    }

    public function test_legacy_format_still_decrypts() {
        $iv = substr(md5(wp_salt('secure_auth')), 0, 16);
        $legacy = 'enc:' . openssl_encrypt('old-secret', 'aes-256-cbc', wp_salt('auth'), 0, $iv);
        $this->assertSame('old-secret', Options::decrypt_secret($legacy));
    }

    public function test_plain_values_pass_through() {
        $this->assertSame('', Options::decrypt_secret(''));
        $this->assertSame('plain', Options::decrypt_secret('plain'));
    }
}
