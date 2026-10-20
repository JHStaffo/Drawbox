<?php

if (! function_exists('money')) {
    function money(int $cents): string
    {
        return '$'.number_format($cents / 100, $cents % 100 ? 2 : 0);
    }
}
