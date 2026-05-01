<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset cached static instance — kalau test sebelumnya mengubah row,
        // dengan RefreshDatabase row dirollback tapi static $instance tidak.
        SiteSetting::clearCache();
        SiteSetting::current()->forceFill([
            'membership_enabled' => true,
            'membership_price' => 50000,
            'membership_duration_days' => 30,
            'storefront_enabled' => true,
            'public_api_enabled' => true,
        ])->save();
    }

    public function test_non_member_redirected_to_membership_when_visiting_api_key_page(): void
    {
        $user = User::factory()->create(['is_member' => false]);

        $this->actingAs($user)
            ->get('/api-key')
            ->assertRedirect(route('membership.show'));
    }

    public function test_active_member_can_view_api_key_page(): void
    {
        $user = User::factory()->create([
            'is_member' => true,
            'member_expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($user)
            ->get('/api-key')
            ->assertOk()
            ->assertSee('Kelola API Key');
    }

    public function test_active_member_can_generate_api_key(): void
    {
        $user = User::factory()->create([
            'is_member' => true,
            'member_expires_at' => now()->addDays(30),
        ]);

        $this->actingAs($user)
            ->post('/api-key/generate')
            ->assertRedirect(route('account.api-key'))
            ->assertSessionHas('api_key.raw');

        $this->assertNotNull($user->fresh()->apiClient);
        $this->assertTrue($user->fresh()->apiClient->is_active);
    }

    public function test_non_member_cannot_generate_api_key(): void
    {
        $user = User::factory()->create(['is_member' => false]);

        $this->actingAs($user)
            ->post('/api-key/generate')
            ->assertRedirect(route('membership.show'));

        $this->assertNull($user->fresh()->apiClient);
    }

    public function test_membership_activation_reactivates_existing_api_client(): void
    {
        $user = User::factory()->create([
            'is_member' => true,
            'member_expires_at' => now()->addDays(30),
        ]);
        ApiClient::issueForUser($user);
        $user->apiClient->forceFill(['is_active' => false])->save();

        // Simulasi order membership yang sudah PAID.
        $order = Order::create([
            'order_code' => 'MEMTEST-01',
            'user_id' => $user->id,
            'amount' => 50000,
            'total_payment' => 50000,
            'is_member_subscription' => true,
            'status' => Order::STATUS_PAID,
            'source' => Order::SOURCE_WEB,
        ]);

        app(MembershipService::class)->activateFromOrder($order);

        $this->assertTrue($user->fresh()->apiClient->is_active);
    }

    public function test_storefront_enabled_off_blocks_homepage_but_keeps_invoice_accessible(): void
    {
        SiteSetting::current()->forceFill(['storefront_enabled' => false])->save();
        SiteSetting::clearCache();

        $this->get('/')->assertStatus(503);

        // Halaman info (artikel index, FAQ, cara order, cek-invoice) di-block.
        $this->get('/cek-invoice')->assertStatus(503);
        $this->get('/faq')->assertStatus(503);
        $this->get('/cara-pemesanan')->assertStatus(503);

        // Invoice route TIDAK di-block (bot Telegram kasih link bayar ke
        // customer meski storefront OFF). 404 dari Order tidak ditemukan
        // berbeda dengan 503 storefront — yang penting bukan 503.
        $resp = $this->get('/invoice/UNKNOWN');
        $this->assertNotEquals(503, $resp->status());
    }

    public function test_storefront_on_keeps_homepage_accessible(): void
    {
        SiteSetting::current()->forceFill(['storefront_enabled' => true])->save();

        $this->get('/')->assertOk();
    }

    public function test_admin_user_edit_page_renders_with_api_key_section(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create([
            'telegram_username' => 'someone',
            'telegram_chat_id' => '12345',
        ]);
        ApiClient::issueForUser($target);

        $resp = $this->actingAs($admin)
            ->get(route('filament.admin.resources.users.edit', ['record' => $target]));

        $resp->assertOk();
        $resp->assertSee('API Key');
        $resp->assertSee('Telegram');
    }

    public function test_admin_settings_page_renders_storefront_toggle(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $resp = $this->actingAs($admin)->get('/admin/manage-site-settings');

        $resp->assertOk();
        $resp->assertSee('Front Store ON', false);
        $resp->assertSee('Membership API Key', false);
    }

    public function test_api_client_authenticate_with_user_scoped_key(): void
    {
        $user = User::factory()->create([
            'is_member' => true,
            'member_expires_at' => now()->addDays(30),
        ]);
        $issued = ApiClient::issueForUser($user);

        // Hit endpoint API publik dengan key yang baru di-issue.
        $resp = $this->withHeaders([
            'X-API-KEY' => $issued['raw'],
        ])->getJson('/api/v1/products');

        $resp->assertOk();
    }
}
