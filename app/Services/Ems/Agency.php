<?php

namespace App\Services\Ems;

/** Agency names as compared: "Fast Track EMS, LLC" and "FAST TRACK EMS LLC" are the same agency. */
class Agency
{
    private const ENDINGS = ['LLC', 'INC', 'INCORPORATED', 'CORP', 'CORPORATION', 'CO', 'LTD', 'PLLC', 'LC', 'PC'];

    public static function key(string $name): string
    {
        $words = preg_split('/\s+/', trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper(str_replace('&', ' AND ', $name)))));
        while (count($words) > 1 && in_array(end($words), self::ENDINGS, true)) {
            array_pop($words);
        }

        return implode('', $words);
    }
}
