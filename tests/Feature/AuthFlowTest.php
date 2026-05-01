<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Password yang lolos PasswordPolicy::default() — dipakai semua test register. */
    private const STRONG_PASSWORD = 'Rahasi4#Aman2025';

    public function test_user_can_register_with_valid_data(): void
    {
        $response = $this->post('/register', [
            'name' => 'Budi Tester',
            'email' => 'budi@test.com',
            'phone' => '081234567890',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'budi@test.com', 'name' => 'Budi Tester']);
        $this->assertAuthenticated();
    }

    public function test_register_does_not_allow_setting_is_admin_via_mass_assignment(): void
    {
        $this->post('/register', [
            'name' => 'Hacker',
            'email' => 'hacker@test.com',
            'phone' => '081234567890',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
            'is_admin' => 1,
        ]);

        $this->assertFalse(User::where('email', 'hacker@test.com')->first()->is_admin);
    }

    public function test_register_rejects_weak_password(): void
    {
        $response = $this->post('/register', [
            'name' => 'Lemah',
            'email' => 'lemah@test.com',
            'phone' => '081234567890',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'lemah@test.com']);
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        User::create([
            'name' => 'Sari',
            'email' => 'sari@test.com',
            'password' => Hash::make('rahasia12'),
        ]);

        $response = $this->post('/login', [
            'email' => 'sari@test.com',
            'password' => 'rahasia12',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::create([
            'name' => 'Sari',
            'email' => 'sari@test.com',
            'password' => Hash::make('rahasia12'),
        ]);

        $response = $this->post('/login', [
            'email' => 'sari@test.com',
            'password' => 'salahdong',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_register_auto_links_existing_guest_orders(): void
    {
        $cat = Category::create(['name' => 'Streaming', 'slug' => 'streaming']);
        $p = Product::create([
            'name' => 'Netflix',
            'price' => 25000,
            'is_auto_send' => true,
            'category_id' => $cat->id,
        ]);
        $v = ProductVariant::create([
            'product_id' => $p->id,
            'name' => '1 Bulan',
            'price' => 25000,
            'duration_days' => 30,
        ]);

        // Guest order pakai email yg nanti dipakai untuk register.
        Order::create([
            'order_code' => 'AKH-TEST-OLD1',
            'product_id' => $p->id,
            'product_variant_id' => $v->id,
            'customer_email' => 'budi@test.com',
            'amount' => 25000,
            'fee' => 0,
            'total_payment' => 25000,
            'status' => Order::STATUS_PENDING,
        ]);

        $this->post('/register', [
            'name' => 'Budi',
            'email' => 'budi@test.com',
            'phone' => '081234567890',
            'password' => self::STRONG_PASSWORD,
            'password_confirmation' => self::STRONG_PASSWORD,
        ]);

        $user = User::where('email', 'budi@test.com')->first();
        $this->assertEquals(1, $user->orders()->count());
        $this->assertEquals('AKH-TEST-OLD1', $user->orders()->first()->order_code);
    }

    public function test_authenticated_user_can_access_account_pages(): void
    {
        $user = User::create([
            'name' => 'Sari',
            'email' => 'sari@test.com',
            'password' => Hash::make('rahasia12'),
        ]);

        $this->actingAs($user)->get('/akun')->assertOk()->assertSee('Sari');
        $this->actingAs($user)->get('/akun/orders')->assertOk();
        $this->actingAs($user)->get('/akun/profil')->assertOk();
    }

    public function test_guest_cannot_access_account_pages(): void
    {
        $this->get('/akun')->assertRedirect('/login');
        $this->get('/akun/orders')->assertRedirect('/login');
    }

    public function test_authenticated_checkout_sets_user_id_on_order(): void
    {
        $cat = Category::create(['name' => 'Streaming', 'slug' => 'streaming']);
        $p = Product::create([
            'name' => 'Netflix',
            'price' => 25000,
            'is_auto_send' => true,
            'category_id' => $cat->id,
        ]);
        $v = ProductVariant::create([
            'product_id' => $p->id,
            'name' => '1 Bulan',
            'price' => 25000,
            'duration_days' => 30,
        ]);

        $user = User::create([
            'name' => 'Sari',
            'email' => 'sari@test.com',
            'password' => Hash::make('rahasia12'),
        ]);

        $this->actingAs($user)->post('/checkout', [
            'product_id' => $p->id,
            'product_variant_id' => $v->id,
            'customer_email' => 'sari@test.com',
        ]);

        $order = Order::latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals($user->id, $order->user_id);
    }

    public function test_user_only_sees_own_orders_in_history(): void
    {
        $cat = Category::create(['name' => 'S', 'slug' => 's']);
        $p = Product::create(['name' => 'X', 'price' => 1000, 'is_auto_send' => true, 'category_id' => $cat->id]);
        $v = ProductVariant::create(['product_id' => $p->id, 'name' => '1B', 'price' => 1000, 'duration_days' => 30]);

        $u1 = User::create(['name' => 'A', 'email' => 'a@x.com', 'password' => Hash::make('rahasia12')]);
        $u2 = User::create(['name' => 'B', 'email' => 'b@x.com', 'password' => Hash::make('rahasia12')]);

        Order::create(['order_code' => 'A1', 'user_id' => $u1->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'customer_email' => 'a@x.com', 'amount' => 1000, 'fee' => 0, 'total_payment' => 1000, 'status' => 'paid']);
        Order::create(['order_code' => 'B1', 'user_id' => $u2->id, 'product_id' => $p->id, 'product_variant_id' => $v->id, 'customer_email' => 'b@x.com', 'amount' => 1000, 'fee' => 0, 'total_payment' => 1000, 'status' => 'paid']);

        $response = $this->actingAs($u1)->get('/akun/orders');
        $response->assertSee('A1');
        $response->assertDontSee('B1');
    }

    public function test_logout_revokes_session(): void
    {
        $u = User::create(['name' => 'A', 'email' => 'a@x.com', 'password' => Hash::make('rahasia12')]);
        $this->actingAs($u)->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }
}
