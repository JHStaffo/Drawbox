<?php

namespace App\Wellness\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Wellness\Models\Participant;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['user' => self::profile($request->user())]);
    }

    /** What an app needs to know about the signed-in Team Member. */
    public static function profile(User $user): array
    {
        $participant = Participant::where('user_id', $user->id)->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'permissions' => $user->permissions(),
            'wellness' => [
                'can_join' => $user->hasPermission('wellness'),
                'joined' => (bool) $participant?->isActive(),
                'joined_at' => $participant?->joined_at?->toIso8601String(),
                'consent_version' => $participant?->consent_version,
                'device_exception_until' => $participant?->manual_device_until?->toDateString(),
            ],
        ];
    }
}
