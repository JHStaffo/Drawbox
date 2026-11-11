<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public const DEFAULTS = [
        'draw_window_days' => '7',
        'draw_delay_hours' => '2',
        'expiry_reminder_enabled' => '1',
        'locker_pickup_days' => '7',
        'news_hide_dispatcher' => '0',
        'news_hide_supervisor' => '0',
        'news_hide_admin' => '0',
        'news_min_cents' => '0',
        'scope_base_crew' => '2',
        // Encrypted Slack bot token (see App\Services\Slack). Empty means not connected.
        'slack_bot_token' => '',
        'scope_scene_types' => "House\nApartment with elevator\nApartment without elevator\nNursing facility\nAssisted living\nHospital\nDialysis center\nDoctor's office",
        'scope_destination_types' => "Hospital\nNursing facility\nAssisted living\nHouse\nApartment with elevator\nApartment without elevator\nDialysis center\nDoctor's office",
    ];

    public static function get(string $key): string
    {
        return static::find($key)?->value ?? self::DEFAULTS[$key];
    }

    public static function put(string $key, string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
