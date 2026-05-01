<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ApiClient sekarang per-user (1 key per user) — link ke users.
        // Tetap nullable supaya admin masih bisa bikin key generic / mitra
        // tanpa user (legacy), tapi defaultnya per-user.
        Schema::table('api_clients', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->index('user_id');
        });

        Schema::table('site_settings', function (Blueprint $table) {
            // Toggle tampilan front store (homepage + browse produk).
            // Saat OFF, hanya invoice/checkout yang tetap accessible —
            // bot Telegram auto-order tetap berjalan normal.
            $table->boolean('storefront_enabled')
                ->default(true)
                ->after('store_name');
        });
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('storefront_enabled');
        });
    }
};
