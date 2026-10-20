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
        'expiry_reminder_enabled' => '1',
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
