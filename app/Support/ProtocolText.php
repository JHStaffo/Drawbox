<?php

namespace App\Support;

/**
 * The light formatting protocol text uses: **bold**, __underline__, and
 * lines starting with "- " for bullets (indent for a sub-bullet). Everything
 * else is escaped, so no one can put real HTML on a page.
 */
class ProtocolText
{
    /** Bold and underline on one escaped line. */
    public static function inline(string $text): string
    {
        $html = e($text);
        $html = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $html);

        return preg_replace('/__(.+?)__/su', '<u>$1</u>', $html);
    }

    /** A box label: formatting plus line breaks. */
    public static function label(string $text): string
    {
        return implode('<br>', array_map(self::inline(...), preg_split('/\r\n|\r|\n/', trim($text))));
    }

    /** A section's text as paragraphs and (nested) bullet lists. */
    public static function block(string $text): string
    {
        $out = '';
        $depth = 0;
        foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^(\s*)[-•*]\s+(.*)$/u', $line, $m)) {
                $level = strlen(str_replace("\t", '  ', $m[1])) >= 2 ? 2 : 1;
                while ($depth < $level) {
                    $out .= '<ul>';
                    $depth++;
                }
                while ($depth > $level) {
                    $out .= '</ul>';
                    $depth--;
                }
                $out .= '<li>'.self::inline($m[2]).'</li>';

                continue;
            }
            $out .= str_repeat('</ul>', $depth);
            $depth = 0;
            $out .= '<p>'.self::inline(trim($line)).'</p>';
        }

        return $out.str_repeat('</ul>', $depth);
    }

    /** Without any formatting marks, for search. */
    public static function plain(string $text): string
    {
        $text = preg_replace('/^\s*[-•*]\s+/mu', '', $text);

        return trim(preg_replace('/\s+/u', ' ', str_replace(['**', '__'], '', $text)));
    }
}
