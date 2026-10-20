<?php

namespace App\Http\Controllers;

use App\Models\LoginCode;
use App\Models\User;
use App\Notifications\LoginCode as LoginCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function showEmail()
    {
        return view('auth.email');
    }

    public function sendCode(Request $request)
    {
        $email = strtolower(trim($request->validate(['email' => 'required|email'])['email']));
        $user = User::where('email', $email)->where('active', true)->first();

        // Answer the same way whether or not the email is on the roster.
        if ($user) {
            $user->loginCodes()->whereNull('used_at')->delete();
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            LoginCode::create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(10),
            ]);
            try {
                $user->notify(new LoginCodeNotification($code));
            } catch (Throwable $e) {
                report($e);

                return back()->withInput()->withErrors([
                    'email' => "We couldn't send your sign-in code right now. Please try again in a few minutes, or let a supervisor know.",
                ]);
            }
        }

        $request->session()->put('login_email', $email);

        return redirect()->route('login.code');
    }

    public function showCode(Request $request)
    {
        if (! $request->session()->has('login_email')) {
            return redirect()->route('login');
        }

        return view('auth.code', ['email' => $request->session()->get('login_email')]);
    }

    public function verifyCode(Request $request)
    {
        $code = preg_replace('/\D/', '', $request->validate(['code' => 'required|string'])['code']);
        $email = $request->session()->get('login_email');
        $user = $email ? User::where('email', $email)->where('active', true)->first() : null;

        $login = $user?->loginCodes()
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->latest('id')->first();

        if (! $login || ! Hash::check($code, $login->code_hash)) {
            $login?->increment('attempts');

            return back()->withErrors(['code' => 'That code is wrong or has expired. Check your email or request a new one.']);
        }

        $login->update(['used_at' => now()]);
        Auth::login($user, remember: true);
        $request->session()->forget('login_email');
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
