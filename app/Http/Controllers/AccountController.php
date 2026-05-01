<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\Audit;
use App\Support\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AccountController extends Controller
{
    /** Dashboard akun (ringkasan order). */
    public function index(): View
    {
        $user = Auth::user();

        $orders = $user->orders()
            ->with(['product:id,name', 'variant:id,name'])
            ->latest()
            ->limit(5)
            ->get();

        $stats = [
            'total' => $user->orders()->count(),
            'paid' => $user->orders()->where('status', Order::STATUS_PAID)->count(),
            'pending' => $user->orders()->where('status', Order::STATUS_PENDING)->count(),
            'total_spend' => (int) $user->orders()->where('status', Order::STATUS_PAID)->sum('total_payment'),
        ];

        return view('account.index', compact('user', 'orders', 'stats'));
    }

    /** History order lengkap user. */
    public function orders(Request $request): View
    {
        $user = Auth::user();

        $query = $user->orders()
            ->with(['product:id,name', 'variant:id,name']);

        if ($status = $request->query('status')) {
            if (in_array($status, [
                Order::STATUS_PENDING,
                Order::STATUS_PAID,
                Order::STATUS_CANCELLED,
                Order::STATUS_EXPIRED,
                Order::STATUS_REFUNDED,
                Order::STATUS_FAILED,
            ], true)) {
                $query->where('status', $status);
            }
        }

        $orders = $query->latest()->paginate(15)->withQueryString();

        return view('account.orders', [
            'orders' => $orders,
            'activeStatus' => $status,
        ]);
    }

    /** Form profil. */
    public function profile(): View
    {
        return view('account.profile', ['user' => Auth::user()]);
    }

    /** Update profil (nama / phone / password). */
    public function updateProfile(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[0-9+\- ]+$/'],
            'current_password' => ['nullable', 'required_with:password', 'string'],
            'password' => ['nullable', 'confirmed', PasswordPolicy::default()],
        ]);

        $passwordChanged = false;
        if (! empty($data['password'])) {
            if (! Hash::check($data['current_password'], $user->password)) {
                return back()->withErrors(['current_password' => 'Password lama tidak cocok.']);
            }

            $user->password = Hash::make($data['password']);
            $passwordChanged = true;
        }

        $user->name = $data['name'];
        $user->phone = $data['phone'] ?? null;
        $user->save();

        if ($passwordChanged) {
            Audit::log('auth.password.changed', $user);
        } else {
            Audit::log('account.profile.updated', $user, ['fields' => ['name', 'phone']]);
        }

        return back()->with('success', 'Profil berhasil diperbarui.');
    }
}
