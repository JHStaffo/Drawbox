<?php

namespace App\Wellness\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\LoginCodes;
use Illuminate\Http\Request;

/**
 * API sign-in for phone apps: the same email and 6-digit code as the
 * website, answered with a bearer token instead of a session.
 */
class AuthController extends Controller
{
    public const APP_ABILITIES = ['wellness', 'training'];

    public function code(Request $request, LoginCodes $codes)
    {
        $email = $request->validate(['email' => 'required|email'])['email'];
        if ($codes->send($email) === 'mail_failed') {
            return response()->json(['message' => "We couldn't send your sign-in code right now. Please try again in a few minutes."], 503);
        }

        // The same answer whether or not the email is on the roster.
        return response()->json(['message' => 'If that email belongs to a Team Member, a 6-digit code is on its way. It works for 10 minutes.']);
    }

    public function token(Request $request, LoginCodes $codes)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|max:20',
            'device_name' => 'required|string|max:100',
        ]);
        $user = $codes->verify($data['email'], $data['code']);
        if (! $user) {
            return response()->json(['message' => 'That code is wrong or has expired.', 'errors' => ['code' => ['That code is wrong or has expired.']]], 422);
        }
        $token = $user->createToken($data['device_name'], self::APP_ABILITIES);
        AuditLog::record('api.auth.token', "Signed in on the app ({$data['device_name']}).", $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => MeController::profile($user),
        ], 201);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        $name = $token?->name ?? 'app';
        $token?->delete();
        audit_note("Signed out of the app ({$name}).");

        return response()->json(['message' => 'Signed out.']);
    }
}
