<?php

namespace App\Training\Models;

use App\Models\PermissionProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A bundle of courses for a group, like New Hire Orientation. Not the same as
 * a permission profile, but it can be given automatically to everyone on chosen
 * permission profiles (a main profile includes its sub-profiles).
 */
class TrainingProfile extends Model
{
    protected $table = 'training_profiles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_days' => 'integer'];
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'training_profile_courses', 'training_profile_id', 'course_id')
            ->withPivot('position')->orderByPivot('position');
    }

    public function autoProfiles(): BelongsToMany
    {
        return $this->belongsToMany(PermissionProfile::class, 'training_profile_auto', 'training_profile_id', 'permission_profile_id');
    }

    /** True when a Team Member on this permission profile gets this automatically (directly or through its main profile). */
    public function appliesTo(?PermissionProfile $profile): bool
    {
        $ids = $this->autoProfiles->pluck('id');

        return $profile && ($ids->contains($profile->id) || ($profile->parent_id && $ids->contains($profile->parent_id)));
    }

    /** Active Team Members it goes to automatically. */
    public function autoUsers()
    {
        $ids = $this->autoProfiles->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }
        $profileIds = PermissionProfile::whereIn('id', $ids)->orWhereIn('parent_id', $ids)->pluck('id');

        return User::where('active', true)->whereIn('permission_profile_id', $profileIds)->get();
    }
}
