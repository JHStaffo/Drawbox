<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\LoginCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showEmail()
    {
        return view('auth.email');
    }

    public function sendCode(Request $request, LoginCodes $codes)
    {
        $email = strtolower(trim($request->validate(['email' => 'required|email'])['email']));

        // Answer the same way whether or not the email is on the roster.
        if ($codes->send($email) === 'mail_failed') {
            return back()->withInput()->withErrors([
                'email' => "We couldn't send your sign-in code right now. Please try again in a few minutes, or let a supervisor know.",
            ]);
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

    public function verifyCode(Request $request, LoginCodes $codes)
    {
        $code = $request->validate(['code' => 'required|string'])['code'];
        $user = $codes->verify($request->session()->get('login_email'), $code);

        if (! $user) {
            return back()->withErrors(['code' => 'That code is wrong or has expired. Check your email or request a new one.']);
        }

        Auth::login($user);
        AuditLog::record('login', 'Signed in.', $user);
        $request->session()->forget('login_email');
        $request->session()->regenerate();
        $request->session()->put('signed_in_at', now()->timestamp);

        return redirect()->intended(route('home'));
    }

    public function logout(Request $request)
    {
        if ($request->user()) {
            AuditLog::record('logout', 'Signed out.', $request->user());
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
