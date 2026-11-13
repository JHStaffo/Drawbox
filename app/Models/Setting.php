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
        // Encrypted Anthropic API key for reading uploaded protocols (see App\Services\ProtocolImport\AnthropicKey).
        'anthropic_api_key' => '',
        // Wellness module (App\Wellness). Turning it off hides the menu, pages and API.
        'wellness_enabled' => '1',
        'wellness_daily_max' => '8',
        'wellness_grace_days' => '3',
        // Points before this day are the soft-launch test period and don't count toward prizes.
        'wellness_official_start' => '2027-01-01',
        // While on, everyone may type steps, sleep and workout in by hand (testing, before wearables sync).
        'wellness_manual_device' => '1',
        // How far back a visit, InBody scan or weigh-in can be submitted, in days.
        'wellness_submit_days' => '60',
        'wellness_weekly_reward_cents' => '1000',
        'wellness_gift_brands' => "Amazon\nTarget\nWalmart\nStarbucks\nChick-fil-A\nVisa",
        // An InBody scan qualifies when, compared with the last approved scan at least this many days earlier,
        // body fat drops by at least the first number (percentage points) or skeletal muscle rises by the second (lb).
        'wellness_inbody_min_days' => '28',
        'wellness_inbody_fat_drop' => '1.0',
        'wellness_inbody_muscle_gain' => '1.0',
        // The iCloud link to the shared Apple Health Shortcut, shown on the Devices page.
        'wellness_shortcut_url' => '',
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
