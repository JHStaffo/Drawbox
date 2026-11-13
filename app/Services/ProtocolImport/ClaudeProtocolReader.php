<?php

namespace App\Services\ProtocolImport;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use App\Models\ProtocolVersion;
use App\Support\ProtocolChart;

/**
 * Turns protocol pages (a PDF or photos) into the builder's data with the
 * Claude API. The answer is held to a JSON schema, so it always parses.
 *
 * A PDF is sent whole every time, with a cache mark on it, and each request
 * asks only for the protocols that start on a couple of pages. The repeat
 * reads of the same PDF then cost a tenth of the first one.
 */
class ClaudeProtocolReader implements ProtocolReader
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model = 'claude-opus-5-5',
    ) {}

    public function ready(): bool
    {
        return (bool) $this->apiKey;
    }

    public function read(array $files, ?array $pages): array
    {
        if (! $this->ready()) {
            throw new ReaderException('There is no Anthropic API key. Add ANTHROPIC_API_KEY to the .env file on the server.');
        }

        $content = [];
        foreach ($files as $i => $file) {
            $data = base64_encode((string) file_get_contents($file['path']));
            $content[] = $file['mime'] === 'application/pdf'
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => $data]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $file['mime'], 'data' => $data]];
        }
        // Everything up to here is the same on every request for this upload, so it is cached.
        $content[array_key_last($content)]['cacheControl'] = ['type' => 'ephemeral'];
        $content[] = ['type' => 'text', 'text' => $this->ask($files, $pages)];

        $client = new Client(apiKey: $this->apiKey, requestOptions: ['timeout' => 900, 'maxRetries' => 2]);
        $text = '';
        $stop = null;
        $input = $output = 0;
        try {
            $stream = $client->beta->messages->createStream(
                model: $this->model,
                maxTokens: 64000,
                system: [['type' => 'text', 'text' => self::INSTRUCTIONS]],
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: ['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
                // If the main model declines (it shouldn't for protocols), the API retries on its standard fallback.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
            foreach ($stream as $event) {
                switch ($event->type) {
                    case 'message_start':
                        $u = $event->message->usage;
                        // Cache writes bill at 1.25x and cache reads at 0.1x of normal input.
                        $input = (int) round(($u->inputTokens ?? 0) + ($u->cacheCreationInputTokens ?? 0) * 1.25 + ($u->cacheReadInputTokens ?? 0) * 0.1);
                        break;
                    case 'content_block_delta':
                        if ($event->delta->type === 'text_delta') {
                            $text .= $event->delta->text;
                        }
                        break;
                    case 'message_delta':
                        $stop = $event->delta->stopReason ?? $stop;
                        $output = max($output, (int) $event->usage->outputTokens);
                        break;
                }
            }
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new ReaderException('The Anthropic API key was turned down. Check ANTHROPIC_API_KEY in the .env file.', false, $e);
        } catch (BadRequestException $e) {
            throw new ReaderException('The Claude API could not take this file: '.$e->getMessage(), false, $e);
        } catch (APIStatusException|APIConnectionException $e) {
            // Rate limits, overload and network trouble: the next cron run tries again.
            throw new ReaderException('The Claude API was busy or unreachable ('.$e->getMessage().'). It will try again.', true, $e);
        }

        if ($stop === 'refusal') {
            throw new ReaderException('Claude declined to read these pages.');
        }
        if ($stop === 'max_tokens') {
            throw new ReaderException('These pages hold more than fits in one answer. Split the PDF into smaller files and upload them separately.');
        }
        $data = json_decode($text, true);
        if (! is_array($data) || ! is_array($data['protocols'] ?? null)) {
            throw new ReaderException('The answer could not be read. It will try again.', true);
        }

        return [
            'protocols' => $data['protocols'],
            'notes' => $data['notes'] ?? null,
            'input_tokens' => $input,
            'output_tokens' => $output,
        ];
    }

    private function ask(array $files, ?array $pages): string
    {
        if ($pages) {
            [$from, $to] = $pages;
            $range = $from === $to ? "page {$from}" : "pages {$from} to {$to}";

            return "Convert only the protocols whose flowchart or first page is on {$range} of this PDF. "
                .'A protocol\'s supplementary page (Goals, Treatment, Special Considerations, Pediatrics and so on) may be on the page before or after; read it from there and include it. '
                ."Leave out protocols that start on any other page; they are read separately. If no protocol starts on {$range}, return an empty list.";
        }

        return count($files) > 1
            ? 'These photos are pages in order. Convert every protocol on them.'
            : 'Convert every protocol in this file.';
    }

    private const INSTRUCTIONS = <<<'TXT'
You convert printed EMS protocols into data for Fast Track Pulse, an EMS company's protocol book. Copy the text exactly as printed: drug names, doses, routes, ages and weights must match the page character for character. Never add, correct or complete clinical content. If something can't be read, write [unreadable] in its place and say so in notes.

A protocol is a flowchart plus supplementary sections, often on two pages.

Flowchart boxes, with a short id (n1, n2, ...) and a type:
- action: red box (urgent actions like CPR, defibrillate)
- step: white rectangle (an ordinary step, drug, or procedure)
- decision: diamond with a yes/no question
- reference: blue box "Treatment per ... Protocol"
- note: yellow box with a note
- exit: green box "EXIT to ... Protocol"
- terminal: black box (Transport, end)
For exit and reference boxes, put the name of the protocol it points to in exit_to (for example "ROSC" or "Airway/Oxygenation/Ventilation"); otherwise null.

Level badges are the small colored tags beside a box or section: E (EMT), A (Advanced EMT), I (Intermediate), P (Paramedic), peds (a baby stroller icon, for pediatrics), M (Medical Control). Give only the badges printed beside that box.

Box text keeps the printed line breaks (\n). Mark bold text **like this** and underlined text __like this__. No other formatting.

x and y are the center of each box in pixels, measured as if the flowchart were drawn about 1200 pixels wide, so the chart can be laid out like the page. Keep boxes from overlapping: leave at least 40 pixels between neighbors.

Arrows go from one box to another. label is the text on the arrow (YES, NO, or whatever is printed), or null. style: one (normal arrow), both (arrowheads at both ends), line (a dotted line with no arrowhead, like a note attached to a box). from_side and to_side are the sides of the boxes the arrow leaves and enters (top, bottom, left, right), as printed.

Sections are the colored blocks with a label down the left side. heading is that label (Goals, Treatment, Special Considerations, Pediatrics, ...). color is the nearest of purple, green, black, pink, blue, red, orange, navy. text uses one line per paragraph, "- " at the start of a bullet line and "  - " (two spaces) for a sub-bullet, with **bold** and __underline__ as printed.

number is the protocol number if one is printed (like "C-1"), else null. category is the chapter or category printed on the page (like Cardiac, Cardiac Arrest, Respiratory, Pregnancy), else null. ages lists who the protocol covers: infant, pediatric, adult (adult only unless the page says otherwise). revised is the revision date or label printed, else null.

Put anything a person should check (unclear text, a box you weren't sure how to connect) in notes, or null.
TXT;

    /** The JSON schema the answer must follow. */
    public static function schema(): array
    {
        $nullable = fn (array $type) => ['anyOf' => [$type, ['type' => 'null']]];
        $string = ['type' => 'string'];
        $badges = ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(ProtocolVersion::BADGES)]];
        $side = ['type' => 'string', 'enum' => ['top', 'bottom', 'left', 'right']];
        $object = fn (array $properties) => [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];

        return $object([
            'protocols' => ['type' => 'array', 'items' => $object([
                'number' => $nullable($string),
                'title' => $string,
                'category' => $nullable($string),
                'ages' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(ProtocolVersion::AGES)]],
                'revised' => $nullable($string),
                'boxes' => ['type' => 'array', 'items' => $object([
                    'id' => $string,
                    'type' => ['type' => 'string', 'enum' => array_keys(ProtocolVersion::BOX_TYPES)],
                    'text' => $string,
                    'badges' => $badges,
                    'exit_to' => $nullable($string),
                    'x' => ['type' => 'integer'],
                    'y' => ['type' => 'integer'],
                ])],
                'arrows' => ['type' => 'array', 'items' => $object([
                    'from' => $string,
                    'to' => $string,
                    'label' => $nullable($string),
                    'style' => ['type' => 'string', 'enum' => ProtocolChart::ARROW_STYLES],
                    'from_side' => $side,
                    'to_side' => $side,
                ])],
                'sections' => ['type' => 'array', 'items' => $object([
                    'heading' => $string,
                    'color' => ['type' => 'string', 'enum' => array_keys(ProtocolVersion::COLORS)],
                    'badges' => $badges,
                    'text' => $string,
                ])],
            ])],
            'notes' => $nullable($string),
        ]);
    }
}
