<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Checks a filled-out form, saves it, and posts it to the form's Slack channel. */
class Forms
{
    public function __construct(private Slack $slack) {}

    /** Validation rules for the fill-out page, keyed by each field's input name. */
    public function rules(Form $form): array
    {
        $rules = [];
        foreach ($form->fields as $field) {
            $name = $field->inputName();
            $base = [$field->required ? 'required' : 'nullable'];
            $rules[$name] = match ($field->type) {
                'textarea' => [...$base, 'string', 'max:5000'],
                'number' => [...$base, 'numeric'],
                'date' => [...$base, 'date_format:Y-m-d'],
                'time' => [...$base, 'date_format:H:i'],
                'datetime' => [...$base, 'date_format:Y-m-d\TH:i'],
                'select', 'radio' => [...$base, Rule::in($field->choices())],
                'checkboxes' => [...$base, 'array'],
                'yesno' => [...$base, Rule::in(['Yes', 'No'])],
                'team_member' => [...$base, 'integer', Rule::exists('users', 'id')->where('active', true)],
                'email' => [...$base, 'email', 'max:255'],
                'phone' => [...$base, 'string', 'max:40', 'regex:/^[0-9 ()+.\-x]+$/i'],
                default => [...$base, 'string', 'max:1000'],
            };
            if ($field->type === 'checkboxes') {
                $rules[$name.'.*'] = [Rule::in($field->choices())];
            }
        }

        return $rules;
    }

    /** Friendly names for the error messages ("The Patient name field is required."). */
    public function attributes(Form $form): array
    {
        return $form->fields->mapWithKeys(fn (FormField $f) => [$f->inputName() => $f->label, $f->inputName().'.*' => $f->label])->all();
    }

    /** Saves a checked submission and tries to post it right away. */
    public function submit(Form $form, array $input, User $user): FormSubmission
    {
        $answers = $form->fields->map(fn (FormField $f) => [
            'label' => $f->label,
            'type' => $f->type,
            'value' => $this->display($f, $input[$f->inputName()] ?? null),
        ])->values()->all();

        $submission = $form->submissions()->create([
            'user_id' => $user->id,
            'form_name' => $form->name,
            'answers' => $answers,
            'slack_template' => $form->slack_template,
            'slack_status' => $form->slack_channel ? 'pending' : 'off',
            'slack_channel' => $form->slack_channel,
        ]);

        if ($form->slack_channel) {
            $this->post($submission);
        }

        return $submission;
    }

    /** Posts (or re-posts) a submission. Returns true when Slack took it. */
    public function post(FormSubmission $submission): bool
    {
        $channel = $submission->slack_channel ?: $submission->form?->slack_channel;
        if (! $channel) {
            $submission->update(['slack_status' => 'off']);

            return false;
        }

        $submission->increment('slack_attempts');
        try {
            $ts = $this->slack->post($channel, $this->fallbackText($submission), $this->blocks($submission));
            $submission->update(['slack_status' => 'sent', 'slack_channel' => $channel, 'slack_ts' => $ts, 'slack_error' => null, 'slack_sent_at' => now()]);

            return true;
        } catch (RuntimeException $e) {
            $submission->update(['slack_status' => 'failed', 'slack_channel' => $channel, 'slack_error' => Str::limit($e->getMessage(), 250)]);

            return false;
        }
    }

    /** Tries again on submissions Slack didn't take, for the scheduled retry. Returns how many went through. */
    public function retryFailed(): int
    {
        $sent = 0;
        FormSubmission::with('form', 'user')
            ->whereIn('slack_status', ['failed', 'pending'])
            ->where('slack_attempts', '<', FormSubmission::MAX_ATTEMPTS)
            ->where('created_at', '<=', now()->subMinute()) // leave ones still being posted alone
            ->where('created_at', '>=', now()->subDays(2))
            ->orderBy('id')
            ->each(function (FormSubmission $s) use (&$sent) {
                $sent += $this->post($s) ? 1 : 0;
            });

        return $sent;
    }

    /** A sample message with every question, for the form's Send test button. */
    public function sendTest(Form $form, User $user): void
    {
        if (! $form->slack_channel) {
            throw new RuntimeException('Pick a Slack channel for this form first.');
        }
        $sample = new FormSubmission([
            'form_name' => $form->name,
            'answers' => $form->fields->map(fn (FormField $f) => ['label' => $f->label, 'type' => $f->type, 'value' => "[{$f->label}]"])->all(),
            'slack_template' => $form->slack_template,
        ]);
        $sample->setRelation('user', $user);
        $sample->created_at = now();

        $this->slack->post($form->slack_channel, 'Test: '.$this->fallbackText($sample), $this->blocks($sample, true));
    }

    /** The answer as text: checkbox picks joined, team members by name, blanks as null. */
    private function display(FormField $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($field->type) {
            'checkboxes' => implode(', ', (array) $value),
            'team_member' => User::find($value)?->name,
            'date' => \Carbon\Carbon::createFromFormat('Y-m-d', $value)->format('D M j, Y'),
            'time' => \Carbon\Carbon::createFromFormat('H:i', $value)->format('g:i A'),
            'datetime' => \Carbon\Carbon::createFromFormat('Y-m-d\TH:i', $value)->format('D M j, Y g:i A'),
            default => trim((string) $value),
        };
    }

    /** The tokens a Slack message can use, besides $answer1, $answer2 ... */
    public const TOKENS = [
        '$sender' => 'who sent it (the signed-in team member)',
        '$form' => 'the form\'s name',
        '$date' => 'the date it was sent',
        '$time' => 'the time it was sent',
    ];

    /**
     * Fills in a form's own Slack message: $answer1 is the first question's
     * answer, and so on, plus the tokens above. Answers are escaped for Slack;
     * the typed message isn't, so Slack formatting like *bold* and <!here> works.
     */
    public function render(string $template, FormSubmission $s): string
    {
        $answers = array_values($s->answers ?? []);
        $when = ($s->created_at ?? now())->timezone(config('app.timezone'));
        $template = str_replace(["\r\n", "\r"], "\n", $template);

        return preg_replace_callback('/\$(?:answer(\d+)|(sender|form|date|time))\b/', function ($m) use ($answers, $s, $when) {
            if (($m[1] ?? '') !== '') {
                $a = $answers[(int) $m[1] - 1] ?? null;

                return $a === null ? $m[0] : Slack::escape((string) ($a['value'] ?? ''));
            }

            return match ($m[2]) {
                'sender' => Slack::escape($s->user?->name ?? 'someone'),
                'form' => Slack::escape($s->form_name),
                'date' => $when->format('M j, Y'),
                'time' => $when->format('g:i A'),
            };
        }, $template);
    }

    private function fallbackText(FormSubmission $s): string
    {
        return $s->form_name.' from '.($s->user?->name ?? 'someone');
    }

    /** The form's own message when it has one; otherwise the form name as a header, the answers, then who sent it and when. */
    private function blocks(FormSubmission $s, bool $test = false): array
    {
        if (trim((string) $s->slack_template) !== '') {
            return $this->customBlocks($s, $test);
        }

        $blocks = [[
            'type' => 'header',
            'text' => ['type' => 'plain_text', 'text' => Str::limit(($test ? 'TEST: ' : '').$s->form_name, 140), 'emoji' => true],
        ]];

        // Answers go in sections of up to ~2900 characters, which is under Slack's 3000 limit.
        $chunk = '';
        foreach ($s->answers as $a) {
            if ($a['value'] === null || $a['value'] === '') {
                continue;
            }
            $line = '*'.Slack::escape($a['label'])."*\n".Slack::escape(Str::limit($a['value'], 2800))."\n";
            if ($chunk !== '' && strlen($chunk) + strlen($line) > 2900) {
                $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => rtrim($chunk)]];
                $chunk = '';
            }
            $chunk .= $line;
        }
        if ($chunk !== '') {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => rtrim($chunk)]];
        }

        $when = ($s->created_at ?? now())->timezone(config('app.timezone'))->format('M j, Y g:i A');
        $blocks[] = ['type' => 'context', 'elements' => [[
            'type' => 'mrkdwn',
            'text' => Slack::escape(($test ? 'Test sent by ' : 'Sent by ').($s->user?->name ?? 'someone')." from Fast Track Pulse · {$when}"),
        ]]];

        return array_slice($blocks, 0, 50);
    }

    /** The typed message, split into sections under Slack's 3000-character limit. */
    private function customBlocks(FormSubmission $s, bool $test): array
    {
        $text = trim($this->render($s->slack_template, $s));
        if ($test) {
            $text = "*TEST*\n".$text;
        }
        $blocks = [];
        $chunk = '';
        foreach (explode("\n", $text) as $line) {
            $line = Str::limit($line, 2900);
            if ($chunk !== '' && strlen($chunk) + strlen($line) + 1 > 2900) {
                $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => rtrim($chunk)]];
                $chunk = '';
            }
            $chunk .= $line."\n";
        }
        if (trim($chunk) !== '') {
            $blocks[] = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => rtrim($chunk)]];
        }

        return array_slice($blocks, 0, 50);
    }
}
