<?php

namespace App\Training\Generate;

/**
 * Writes a course from a brief. The real one calls the Claude API; tests swap in a fake.
 */
interface CourseWriter
{
    /**
     * @param  array  $brief  topic, audience, goals, details, lessons, questions, passing_score
     * @param  array{path: string, mime: string, name: string}|null  $source  a PDF or text file to build from
     * @return array{course: array, notes: ?string, input_tokens: int, output_tokens: int}
     *
     * @throws \App\Services\ProtocolImport\ReaderException when it can't; $retry says whether trying again later may help
     */
    public function write(array $brief, ?array $source): array;

    public function ready(): bool;
}
