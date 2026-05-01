<?php

namespace App\Http\Controllers;

use App\Models\ApiClient;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\MembershipService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Halaman self-service API Key untuk user yang sudah membayar membership.
 *
 *  - User belum member          → redirect ke /membership (paywall).
 *  - User member aktif          → tampilkan info API key, base URL, link docs,
 *                                tombol generate/regenerate, daftar setting
 *                                (whitelist + rate limit) read-only (atur admin).
 *  - Membership di-disable adm. → redirect ke home dengan info.
 */
class ApiKeyController extends Controller
{
    /**
     * GET /api-key
     */
    public function show(Request $request): View|RedirectResponse
    {
        if (! MembershipService::isEnabled()) {
            return redirect()->route('home')->with('error', 'Akses API key belum diaktifkan admin.');
        }

        /** @var User $user */
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login');
        }

        // Kalau belum member aktif, alihkan ke /membership untuk bayar dulu.
        if (! $user->isActiveMember()) {
            return redirect()
                ->route('membership.show')
                ->with('info', 'Beli akses API key dulu sebelum bisa kelola key.');
        }

        $client = $user->apiClient;

        // Raw key hanya tampil sekali — diambil dari flash session.
        $newRawKey = session('api_key.raw');

        return view('account.api-key', [
            'site' => SiteSetting::current(),
            'user' => $user,
            'client' => $client,
            'newRawKey' => $newRawKey,
            'baseUrl' => rtrim((string) config('app.url'), '/').'/api/v1',
            'docsUrl' => route('api.docs'),
            'durationDays' => MembershipService::durationDays(),
        ]);
    }

    /**
     * POST /api-key/generate — buat key baru / regenerasi (rotate).
     */
    public function generate(Request $request): RedirectResponse
    {
        if (! MembershipService::isEnabled()) {
            return redirect()->route('home')->with('error', 'Akses API key belum diaktifkan admin.');
        }

        /** @var User $user */
        $user = Auth::user();
        if (! $user || ! $user->isActiveMember()) {
            return redirect()
                ->route('membership.show')
                ->with('error', 'Beli akses API key dulu sebelum generate key.');
        }

        $issued = ApiClient::issueForUser($user);

        Audit::log('api_key.issued', $issued['client'], [
            'user_id' => $user->id,
            'prefix' => $issued['client']->api_key_prefix,
        ]);

        return redirect()
            ->route('account.api-key')
            ->with('success', 'API key baru berhasil di-generate. Salin sekarang — key tidak akan ditampilkan lagi.')
            ->with('api_key.raw', $issued['raw']);
    }
}
