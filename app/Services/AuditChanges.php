<?php

namespace App\Services;

use App\Models\Draw;
use App\Models\GiftCardStock;
use App\Models\LiftRule;
use App\Models\PermissionProfile;
use App\Models\Prize;
use App\Models\ScopeItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Collects what each request changed (from and to) so the audit log can
 * say more than "Saved". Only the fields a person would care about are
 * listed; anything else a save touches is left out.
 */
class AuditChanges
{
    private const FIELDS = [
        Prize::class => [
            'name' => 'name', 'type' => 'type', 'value_cents' => 'value', 'pto_hours' => 'PTO hours',
            'available' => 'draws left', 'monthly_quantity' => 'monthly', 'quantity' => 'on hand',
            'diminishing' => 'diminishing', 'cash_bonus_allowed' => 'cash bonus OK', 'taxable' => 'taxable', 'active' => 'shown',
        ],
        GiftCardStock::class => ['brand' => 'name', 'quantity' => 'on hand', 'active' => 'shown'],
        User::class => ['name' => 'name', 'email' => 'email', 'phone' => 'phone', 'employee_number' => 'team member #', 'permission_profile_id' => 'profile', 'active' => 'active'],
        PermissionProfile::class => ['name' => 'name', 'parent_id' => 'sub-profile of', 'permissions' => 'permissions', 'is_default' => 'new team members get it', 'hide_winners' => 'hidden from Recent Winners'],
        Draw::class => [
            'prize_name' => 'prize', 'brand' => 'brand', 'value_cents' => 'value', 'pto_hours' => 'PTO hours',
            'taxable' => 'taxable', 'cash_bonus' => 'cash bonus',
        ],
        \App\Models\Form::class => ['name' => 'name', 'description' => 'description', 'slack_channel' => 'Slack channel', 'slack_template' => 'Slack message', 'active' => 'on'],
        \App\Models\FormField::class => ['label' => 'question', 'type' => 'type', 'options' => 'choices', 'option_list_id' => 'shared list', 'help' => 'help text', 'required' => 'required', 'allow_other' => 'Other choice'],
        \App\Models\OptionList::class => ['name' => 'name', 'allow_add' => 'people can add'],
        \App\Models\OptionListItem::class => ['name' => 'name', 'active' => 'offered'],
        ScopeItem::class => ['name' => 'name', 'level' => 'level of care', 'note' => 'message'],
        LiftRule::class => [
            'scene_type' => 'scene', 'destination_type' => 'destination', 'min_weight_lbs' => 'min weight',
            'min_steps' => 'min steps', 'crew' => 'crew', 'note' => 'note',
        ],
    ];

    private const SETTINGS = [
        'draw_window_days' => 'Draw window (days)',
        'draw_delay_hours' => 'Wait before drawing (hours)',
        'locker_pickup_days' => 'Locker pickup window (days)',
        'expiry_reminder_enabled' => 'Expiry reminder emails',
        'news_hide_dispatcher' => 'Hide dispatcher winners',
        'news_hide_supervisor' => 'Hide supervisor winners',
        'news_hide_admin' => 'Hide admin winners',
        'news_min_cents' => 'Hide prizes under',
        'scope_base_crew' => 'Scope check: usual ideal crew',
        'scope_scene_types' => 'Scope check: scene types',
        'scope_destination_types' => 'Scope check: destination types',
    ];

    private bool $recording = false;

    /** @var array<string, array<string, array{0: string, 1: string}>> subject => field => [from, to] */
    private array $changes = [];

    public function start(): void
    {
        $this->recording = true;
        $this->changes = [];
    }

    /** The changes so far as one sentence, or null if nothing listed changed. */
    public function stop(): ?string
    {
        $this->recording = false;
        $parts = [];
        foreach ($this->changes as $subject => $fields) {
            $list = collect($fields)->reject(fn ($c) => $c[0] === $c[1])->map(fn ($c, $field) => "{$field} from {$c[0]} to {$c[1]}");
            if ($list->isNotEmpty()) {
                $parts[] = $subject.': '.$list->implode(', ');
            }
        }

        return $parts ? implode('; ', $parts).'.' : null;
    }

    public function updated(Model $model): void
    {
        if (! $this->recording) {
            return;
        }

        if ($model instanceof Setting) {
            $this->setting($model, $model->getOriginal('value'));

            return;
        }

        $fields = self::FIELDS[$model::class] ?? null;
        if (! $fields) {
            return;
        }
        foreach ($model->getChanges() as $field => $new) {
            if (isset($fields[$field])) {
                $old = $model->getOriginal($field);
                if ($this->show($field, $old) !== $this->show($field, $new)) {
                    $this->add($this->subject($model), $fields[$field], $this->show($field, $old), $this->show($field, $new));
                }
            }
        }
    }

    public function created(Model $model): void
    {
        // A setting saved for the first time changes it from its default.
        if ($this->recording && $model instanceof Setting) {
            $this->setting($model, Setting::DEFAULTS[$model->key] ?? null);
        }
    }

    private function setting(Setting $setting, ?string $old): void
    {
        // The Slack token is a secret: say it changed, never what it was.
        if ($setting->key === 'slack_bot_token') {
            $this->add('Settings', 'Slack bot token', $old ? 'set' : 'none', $setting->value ? ($old ? 'replaced' : 'set') : 'none');

            return;
        }
        $label = self::SETTINGS[$setting->key] ?? $setting->key;
        $show = fn ($v) => match (true) {
            $setting->key === 'expiry_reminder_enabled', str_starts_with($setting->key, 'news_hide_') => $v ? 'on' : 'off',
            $setting->key === 'news_min_cents' => money((int) $v),
            default => (string) $v,
        };
        if ($show($old) !== $show($setting->value)) {
            $this->add('Settings', $label, $show($old), $show($setting->value));
        }
    }

    private function add(string $subject, string $field, string $old, string $new): void
    {
        // When a request saves a field twice, keep the first "from" and the last "to".
        $this->changes[$subject][$field] = [$this->changes[$subject][$field][0] ?? $old, $new];
    }

    private function subject(Model $model): string
    {
        return match (true) {
            $model instanceof Prize => $model->getOriginal('name') ?? $model->name,
            $model instanceof GiftCardStock => trim(($model->prize?->name ?? '').' '.($model->getOriginal('brand') ?? $model->brand)),
            $model instanceof User => $model->getOriginal('name') ?? $model->name,
            $model instanceof Draw => ($model->user?->name ?? 'Team Member')."'s prize #{$model->id}",
            $model instanceof ScopeItem => $model->getOriginal('name') ?? $model->name,
            $model instanceof LiftRule => "Lift rule #{$model->id}",
            $model instanceof PermissionProfile => ($model->getOriginal('name') ?? $model->name).' profile',
            $model instanceof \App\Models\OptionList => ($model->getOriginal('name') ?? $model->name).' list',
            $model instanceof \App\Models\OptionListItem => '"'.($model->getOriginal('name') ?? $model->name).'" on '.($model->list?->name ?? 'a list'),
            $model instanceof \App\Models\Form => ($model->getOriginal('name') ?? $model->name).' form',
            $model instanceof \App\Models\FormField => '"'.($model->getOriginal('label') ?? $model->label).'" on the '.($model->form?->name ?? 'form').' form',
        };
    }

    /** A profile's permissions as their labels, or "none". */
    private function permissions(mixed $value): string
    {
        $keys = is_string($value) ? (json_decode($value, true) ?? []) : ($value ?? []);
        $labels = PermissionProfile::permissionLabels();
        $names = collect($keys)->map(fn ($k) => preg_replace('/ \(.*\)$/', '', $labels[$k] ?? $k));

        return $names->isEmpty() ? 'none' : '['.$names->implode(', ').']';
    }

    private function show(string $field, mixed $value): string
    {
        return match (true) {
            $field === 'permissions' => $this->permissions($value),
            $value === null || $value === '' => 'none',
            $field === 'permission_profile_id' => PermissionProfile::find($value)?->label() ?? "#{$value}",
            $field === 'parent_id' => PermissionProfile::find($value)?->name ?? "#{$value}",
            $field === 'value_cents' => money((int) $value),
            $field === 'phone' => User::formatPhone($value),
            is_bool($value) || in_array($field, ['active', 'taxable', 'diminishing', 'cash_bonus_allowed', 'cash_bonus', 'is_default', 'hide_winners', 'required', 'allow_other', 'allow_add'], true) => $value ? 'yes' : 'no',
            $field === 'pto_hours' => (string) ($value + 0),
            $field === 'level' => ScopeItem::LEVELS[$value] ?? (string) $value,
            default => (string) $value,
        };
    }
}
