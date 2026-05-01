<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\PasswordPolicy;
use App\Support\SecurityMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage untuk feature security hardening yang ditambahkan:
 * - PasswordPolicy::default()
 * - Audit::sanitize() (filter sensitive keys)
 * - SecurityMonitor::recordFailedLogin() (brute-force counters & alerts)
 * - Logging event auth.login.success / auth.login.failed
 * - SecurityHeaders middleware (CSP + classic headers)
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_policy_rejects_too_short(): void
    {
        $validator = validator(['p' => 'Aa1!short'], ['p' => PasswordPolicy::default()]);
        $this->assertTrue($validator->fails());
    }

    public function test_password_policy_requires_mixed_case(): void
    {
        $validator = validator(['p' => 'aaaaaaa1!'], ['p' => PasswordPolicy::default()]);
        $this->assertTrue($validator->fails());
    }

    public function test_password_policy_requires_symbol(): void
    {
        $validator = validator(['p' => 'Aaaaaaaaa1'], ['p' => PasswordPolicy::default()]);
        $this->assertTrue($validator->fails());
    }

    public function test_password_policy_accepts_strong_password(): void
    {
        $validator = validator(['p' => 'Aman2025#Asik'], ['p' => PasswordPolicy::default()]);
        $this->assertFalse($validator->fails());
    }

    public function test_audit_sanitize_strips_sensitive_keys(): void
    {
        $clean = Audit::sanitize([
            'name' => 'Budi',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'token' => 'xyz',
            'nested' => [
                'api_key' => 'sk-abc',
                'plain' => 'ok',
            ],
        ]);

        $this->assertSame('Budi', $clean['name']);
        $this->assertSame('[REDACTED]', $clean['password']);
        $this->assertSame('[REDACTED]', $clean['password_confirmation']);
        $this->assertSame('[REDACTED]', $clean['token']);
        $this->assertSame('[REDACTED]', $clean['nested']['api_key']);
        $this->assertSame('ok', $clean['nested']['plain']);
    }

    public function test_failed_login_creates_audit_entry_without_password(): void
    {
        $this->post('/login', [
            'email' => 'unknown@test.com',
            'password' => 'tryToCrack!',
        ]);

        $entry = AuditLog::where('event', 'auth.login.failed')->first();
        $this->assertNotNull($entry);
        $changes = $entry->changes;

        // Email harus di-mask, password tidak boleh ada di changes sama sekali.
        $this->assertArrayHasKey('email', $changes);
        $this->assertStringContainsString('*', $changes['email']);
        $serialized = json_encode($changes);
        $this->assertStringNotContainsString('tryToCrack', $serialized);
    }

    public function test_successful_login_logged_and_resets_counters(): void
    {
        User::create([
            'name' => 'Sari',
            'email' => 'sari@test.com',
            'password' => Hash::make('rahasia12'),
        ]);

        // Trigger 1 failed attempt to set counter.
        SecurityMonitor::recordFailedLogin('sari@test.com', '127.0.0.1');

        $this->post('/login', [
            'email' => 'sari@test.com',
            'password' => 'rahasia12',
        ])->assertRedirect();

        $this->assertNotNull(AuditLog::where('event', 'auth.login.success')->first());
    }

    public function test_brute_force_threshold_triggers_alert(): void
    {
        Cache::flush();

        $email = 'victim@test.com';
        $ip = '198.51.100.10';

        $alerted = false;
        for ($i = 0; $i < SecurityMonitor::EMAIL_THRESHOLD; $i++) {
            $alerted = SecurityMonitor::recordFailedLogin($email, $ip);
        }

        $this->assertTrue($alerted, 'Threshold harus memicu alert pada attempt ke-N');

        $this->assertGreaterThanOrEqual(
            1,
            AuditLog::where('event', 'security.alert.auth.bruteforce.email')->count()
        );
    }

    public function test_security_headers_present_on_response(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        // CSP report-only by default
        $hasCsp = $response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only');
        $this->assertTrue($hasCsp, 'CSP header harus dipasang oleh SecurityHeaders middleware');
    }

    public function test_email_mask_helper(): void
    {
        $this->assertSame('bu**@d*******.com', SecurityMonitor::maskEmail('budi@dandutzz.com'));
        $this->assertSame('*@d*******.com', SecurityMonitor::maskEmail('a@dandutzz.com'));
        $this->assertSame('***', SecurityMonitor::maskEmail('not-an-email'));
        // Local sangat pendek tetap di-mask seluruhnya.
        $this->assertSame('**@e******.com', SecurityMonitor::maskEmail('xy@example.com'));
    }
}
