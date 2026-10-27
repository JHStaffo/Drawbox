<?php

if (! function_exists('money')) {
    function money(int $cents): string
    {
        return '$'.number_format($cents / 100, $cents % 100 ? 2 : 0);
    }
}

if (! function_exists('audit_note')) {
    /** Describe the current action for the audit log, instead of the flashed message. */
    function audit_note(string $description): void
    {
        request()->attributes->set('audit', $description);
    }
}
