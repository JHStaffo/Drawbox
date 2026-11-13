<?php

namespace App\Services\ProtocolImport;

/**
 * Reads protocol pages and returns them as structured data. The real one
 * calls the Claude API; tests swap in a fake.
 */
interface ProtocolReader
{
    /**
     * @param  list<array{path: string, mime: string}>  $files  absolute paths: one PDF, or photos in page order
     * @param  array{0: int, 1: int}|null  $pages  for a PDF, read only protocols that start on these pages (inclusive)
     * @return array{protocols: list<array>, notes: ?string, input_tokens: int, output_tokens: int}
     *
     * @throws ReaderException when the page can't be read; $retry says whether trying again later may help
     */
    public function read(array $files, ?array $pages): array;

    /** Whether the reader is set up (for Claude: an API key is in .env). */
    public function ready(): bool;
}
