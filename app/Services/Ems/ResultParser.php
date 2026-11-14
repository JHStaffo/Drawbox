<?php

namespace App\Services\Ems;

use DOMElement;
use DOMNode;

/**
 * Reads the results table of the Virginia EMS provider search. The columns
 * are found by their headings (name or last/first name, certification #,
 * level, expiration, agency), so a reordered or renamed column still works.
 * A provider listed with several agencies may be on several rows, or have
 * them in one cell on separate lines; either way they're grouped by
 * certification # (or name, when there's no certification column).
 */
class ResultParser
{
    /**
     * @return array{table: bool, columns: list<?string>, headings: list<string>, rows: list<array{cells: list<string>, lines: list<list<string>>, link: ?string}>}
     */
    public static function parse(string $html): array
    {
        $xpath = ProviderSearch::xpath($html);
        $best = null;
        foreach ($xpath->query('//table') as $table) {
            /** @var DOMElement $table */
            $headerCells = [];
            foreach ($xpath->query('.//tr', $table) as $tr) {
                $ths = $xpath->query('./th', $tr);
                if ($ths->length > 1) {
                    $headerCells = iterator_to_array($ths);
                    break;
                }
            }
            if (! $headerCells) {
                continue;
            }
            $headings = array_map(fn (DOMNode $n) => self::text($n), $headerCells);
            $columns = array_map([self::class, 'column'], $headings);
            if (! array_intersect($columns, ['name', 'last', 'cert'])) {
                continue; // a layout table, not the results
            }
            $rows = [];
            foreach ($xpath->query('.//tr', $table) as $tr) {
                $tds = $xpath->query('./td', $tr);
                if ($tds->length < 2) {
                    continue;
                }
                $cells = [];
                $lines = [];
                foreach ($tds as $td) {
                    $cells[] = self::text($td);
                    $lines[] = self::lines($td);
                }
                $link = $xpath->query('.//a[@href]', $tr)->item(0);
                $rows[] = ['cells' => $cells, 'lines' => $lines, 'link' => $link instanceof DOMElement ? $link->getAttribute('href') : null];
            }
            if (! $best || count($rows) > count($best['rows'])) {
                $best = ['table' => true, 'columns' => $columns, 'headings' => $headings, 'rows' => $rows];
            }
        }

        return $best ?? ['table' => false, 'columns' => [], 'headings' => [], 'rows' => []];
    }

    /** The search ran but matched nobody. */
    public static function saysNoData(string $html): bool
    {
        return (bool) preg_match('/no (data|records|providers?|results?|matches) (were )?found|no matching/i', strip_tags($html));
    }

    /** What a heading is: name, last, first, middle, cert, level, expires, agency, or null. */
    public static function column(string $heading): ?string
    {
        $h = strtolower($heading);

        return match (true) {
            str_contains($h, 'agenc') || str_contains($h, 'affiliat') => 'agency',
            str_contains($h, 'exp') => 'expires',
            str_contains($h, 'level') => 'level',
            str_contains($h, 'cert') || str_contains($h, 'license') || preg_match('/\b(id|number|no\.?)\b|#/', $h) === 1 => 'cert',
            str_contains($h, 'last') => 'last',
            str_contains($h, 'first') => 'first',
            str_contains($h, 'middle') => 'middle',
            str_contains($h, 'name') || str_contains($h, 'provider') => 'name',
            default => null,
        };
    }

    /**
     * Everyone in the results, with all their agencies.
     *
     * @return list<array{name: string, first: ?string, last: ?string, cert: ?string, level: ?string, expires: ?string, agencies: list<string>, link: ?string}>
     */
    public static function providers(array $parsed): array
    {
        $col = [];
        foreach ($parsed['columns'] as $i => $c) {
            if ($c !== null && ! isset($col[$c])) {
                $col[$c] = $i;
            }
        }
        $providers = [];
        $previous = null;
        foreach ($parsed['rows'] as $row) {
            $cell = fn (string $c) => isset($col[$c]) ? trim($row['cells'][$col[$c]] ?? '') : '';
            $first = $cell('first');
            $last = $cell('last');
            $name = $cell('name') ?: trim($first.' '.$cell('middle').' '.$last);
            $name = preg_replace('/\s+/', ' ', $name);
            $cert = $cell('cert');
            $agencies = isset($col['agency']) ? $row['lines'][$col['agency']] ?? [] : [];

            // A row with only an agency continues the provider above it.
            if ($name === '' && $cert === '' && $previous !== null) {
                $providers[$previous]['agencies'] = array_merge($providers[$previous]['agencies'], $agencies);

                continue;
            }
            if ($name === '' && $cert === '') {
                continue;
            }
            $key = $cert !== '' ? 'C'.strtoupper($cert) : 'N'.strtoupper(preg_replace('/[^A-Za-z]/', '', $name));
            $providers[$key] ??= [
                'name' => $name,
                'first' => $first ?: null,
                'last' => $last ?: null,
                'cert' => $cert ?: null,
                'level' => $cell('level') ?: null,
                'expires' => preg_replace('#\s*/\s*#', '/', $cell('expires')) ?: null, // "09 /30 /2028" on the site
                'agencies' => [],
                'link' => $row['link'],
            ];
            $providers[$key]['agencies'] = array_merge($providers[$key]['agencies'], $agencies);
            $previous = $key;
        }

        foreach ($providers as &$p) {
            $p['agencies'] = self::uniqueAgencies($p['agencies']);
        }

        return array_values($providers);
    }

    /** Agencies on a provider's own page: a table with an agency column, or labeled lines. */
    public static function agenciesOnDetailPage(string $html): array
    {
        $parsed = self::parseAnyTable($html);
        if ($parsed !== null) {
            return $parsed;
        }
        // "Agency: NAME" lines.
        preg_match_all('/(?:agency|affiliation)[^:<]{0,20}:\s*([^<\n]+)/i', $html, $m);

        return self::uniqueAgencies(array_map('trim', array_map('html_entity_decode', $m[1] ?? [])));
    }

    private static function parseAnyTable(string $html): ?array
    {
        $xpath = ProviderSearch::xpath($html);
        foreach ($xpath->query('//table') as $table) {
            $ths = $xpath->query('.//tr/th', $table);
            $index = null;
            foreach ($ths as $i => $th) {
                if (self::column(self::text($th)) === 'agency') {
                    $index = $i;
                    break;
                }
            }
            if ($index === null) {
                continue;
            }
            $agencies = [];
            foreach ($xpath->query('.//tr', $table) as $tr) {
                $td = $xpath->query('./td', $tr)->item($index);
                if ($td) {
                    $agencies = array_merge($agencies, self::lines($td));
                }
            }

            return self::uniqueAgencies($agencies);
        }

        return null;
    }

    /** @param list<string> $agencies */
    private static function uniqueAgencies(array $agencies): array
    {
        $out = [];
        foreach ($agencies as $agency) {
            $agency = trim(preg_replace('/\s+/', ' ', $agency), " \t\n\r\0\x0B-–;");
            $key = Agency::key($agency);
            if ($key !== '' && ! isset($out[$key])) {
                $out[$key] = $agency;
            }
        }

        return array_values($out);
    }

    private static function text(DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5)));
    }

    /** The cell's text split on line breaks, list items and semicolons (never commas: "FAST TRACK EMS, LLC"). */
    private static function lines(DOMNode $node): array
    {
        $html = $node->ownerDocument->saveHTML($node);
        $html = preg_replace('#<br\s*/?>|</(li|p|div)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return array_values(array_filter(array_map(fn ($l) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), preg_split('/[\n;]+/', $text)), fn ($l) => $l !== ''));
    }
}
