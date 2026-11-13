<?php

namespace App\Training\Generate;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use App\Services\ProtocolImport\ReaderException;
use App\Training\Models\Question;

/**
 * Writes a course with the Claude API, using the same Anthropic key as the
 * protocol import. The answer is held to a JSON schema: lesson pages in the
 * course designer's HTML, plus one quiz.
 */
class ClaudeCourseWriter implements CourseWriter
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model = 'claude-opus-5-5',
    ) {}

    public function ready(): bool
    {
        return (bool) $this->apiKey;
    }

    public function write(array $brief, ?array $source): array
    {
        if (! $this->ready()) {
            throw new ReaderException('There is no Anthropic API key. An admin can add one in Admin > Settings.');
        }

        $content = [];
        if ($source) {
            $data = (string) file_get_contents($source['path']);
            $content[] = $source['mime'] === 'application/pdf'
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($data)], 'title' => $source['name']]
                : ['type' => 'document', 'source' => ['type' => 'text', 'mediaType' => 'text/plain', 'data' => mb_convert_encoding($data, 'UTF-8', 'UTF-8')], 'title' => $source['name']];
        }
        $content[] = ['type' => 'text', 'text' => self::ask($brief, (bool) $source)];

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
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
            foreach ($stream as $event) {
                switch ($event->type) {
                    case 'message_start':
                        $u = $event->message->usage;
                        $input = (int) (($u->inputTokens ?? 0) + ($u->cacheCreationInputTokens ?? 0) + ($u->cacheReadInputTokens ?? 0));
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
            throw new ReaderException('The Anthropic API key was turned down. An admin can paste a new one in Admin > Settings.', false, $e);
        } catch (BadRequestException $e) {
            throw new ReaderException('The Claude API could not take this request: '.$e->getMessage(), false, $e);
        } catch (APIStatusException|APIConnectionException $e) {
            throw new ReaderException('The Claude API was busy or unreachable ('.$e->getMessage().'). It will try again.', true, $e);
        }

        if ($stop === 'refusal') {
            throw new ReaderException('Claude declined to write this course.');
        }
        if ($stop === 'max_tokens') {
            throw new ReaderException('The course was too long for one answer. Ask for fewer lessons, or split the source file.');
        }
        $data = json_decode($text, true);
        if (! is_array($data) || ! is_array($data['lessons'] ?? null)) {
            throw new ReaderException('The answer could not be read. It will try again.', true);
        }

        return ['course' => $data, 'notes' => $data['notes'] ?? null, 'input_tokens' => $input, 'output_tokens' => $output];
    }

    public static function ask(array $brief, bool $hasSource): string
    {
        $lines = ['Write a training course for Fast Track EMS.', '', 'Topic: '.$brief['topic']];
        foreach (['audience' => 'Who takes it', 'goals' => 'What they should be able to do afterwards', 'details' => 'Details, policies and points to cover'] as $key => $label) {
            if (! empty($brief[$key])) {
                $lines[] = "{$label}: {$brief[$key]}";
            }
        }
        $lines[] = '';
        $lines[] = ! empty($brief['sections'])
            ? 'Sections, in this order (use these names exactly): '.implode(', ', $brief['sections']).'.'
            : 'Sections: your choice. Group the lessons into a few named sections if the course has 4 or more lessons; otherwise leave section empty.';
        $lines[] = 'Lessons: '.(! empty($brief['lessons']) ? "about {$brief['lessons']}" : 'as many as the material needs (usually 3 to 6)').'.';
        $lines[] = ! empty($brief['questions'])
            ? "Quiz: {$brief['questions']} questions, written so a Team Member who read the lessons can pass with {$brief['passing_score']}%."
            : 'Quiz: none. Return an empty questions list.';
        if ($hasSource) {
            $lines[] = '';
            $lines[] = 'Build the course from the attached document. It is the company\'s own material: keep its policies, numbers, names and wording exactly, and don\'t add rules it doesn\'t state. Cover all of it that fits the topic.';
        }

        return implode("\n", $lines);
    }

    private const INSTRUCTIONS = <<<'TXT'
You write online training courses for Fast Track EMS, a non-emergency ambulance service in Virginia. The people taking them are EMTs, paramedics, drivers, dispatchers and office staff, always called "Team Members" (never "employees"). Write in plain, friendly, direct English at about an 8th-grade reading level: short sentences, active voice, "you".

Accuracy matters more than polish. When a source document is attached, it is the authority: keep its policies, numbers, phone numbers, names and steps exactly. Without a source, stick to widely accepted, general practice, and never invent company-specific facts (phone numbers, names, pay, deadlines, discipline). Where the company has to fill something in, write [Fast Track EMS to confirm: what is needed] so a reviewer sees it, and list each one in notes. For clinical content, follow current national EMS guidance and tell Team Members to follow Fast Track EMS protocols and medical direction.

Each lesson is one page in the course designer, 300 to 900 words, written as HTML using only these tags: h2, h3, p, strong, em, u, ul, ol, li, blockquote, a (href only, https links), br, hr. No other tags, attributes, classes, styles, images or tables. Start each page with a short intro paragraph, use h2/h3 headings to break it up, lists for steps and checklists, and a blockquote for the one thing to remember (start it with <strong>Remember:</strong>) or a safety warning (start it with <strong>Safety:</strong>). End with a short "Key points" h3 and a bulleted list.

Courses can be split into named sections that group the lessons, such as Regulations, Policies, Procedures and Risks for a driving course. Give each lesson its section name (one to four words, Title Case), keep each section's lessons together and in order, and give every lesson in a sectioned course a section. Use an empty string for section when the course has no sections.

Quiz questions check the important points of the lessons, never trivia or trick wording. Types: single (one right answer, 3 or 4 choices), multiple (select all that apply, 4 or 5 choices, at least two right), true_false (choices exactly "True" then "False"). Spread the right answer's position around. Each explanation says in a sentence why the right answer is right and names the lesson it comes from.

category is one or two words, such as Handbook, Clinical, Safety, Driving, Dispatch, Compliance or HR. notes is for the reviewer: anything to confirm, anything in the source you left out and why, or null.
TXT;

    public static function schema(): array
    {
        $object = fn (array $properties) => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
        $string = ['type' => 'string'];

        return $object([
            'title' => $string,
            'description' => $string,
            'category' => $string,
            'lessons' => ['type' => 'array', 'items' => $object([
                'section' => $string,
                'title' => $string,
                'html' => $string,
            ])],
            'quiz_title' => $string,
            'questions' => ['type' => 'array', 'items' => $object([
                'type' => ['type' => 'string', 'enum' => array_keys(Question::TYPES)],
                'prompt' => $string,
                'choices' => ['type' => 'array', 'items' => $object(['text' => $string, 'correct' => ['type' => 'boolean']])],
                'explanation' => $string,
            ])],
            'notes' => ['anyOf' => [$string, ['type' => 'null']]],
        ]);
    }
}
