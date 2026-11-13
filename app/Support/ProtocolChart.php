<?php

namespace App\Support;

use App\Models\Protocol;
use App\Models\ProtocolVersion;
use Illuminate\Validation\ValidationException;

/**
 * A protocol flowchart: boxes and arrows, drawn in the browser by Mermaid.
 * The layout is automatic, so the data is just what's in each box and what
 * connects to what. That keeps it easy to build, import and send to apps.
 */
class ProtocolChart
{
    public const MAX_BOXES = 80;

    /** one: an arrow; both: arrowheads at both ends; line: a dotted line with no arrowhead (for a note beside a box). */
    public const ARROW_STYLES = ['one', 'both', 'line'];

    /** Box colors: fill, border, text. */
    private const STYLES = [
        'action' => ['#ed1c24', '#b5121a', '#ffffff'],
        'step' => ['#ffffff', '#333333', '#111111'],
        'decision' => ['#ffffff', '#111111', '#111111'],
        'reference' => ['#66d9ff', '#2a9fc9', '#111111'],
        'note' => ['#ffe699', '#e0b84a', '#111111'],
        'exit' => ['#92d050', '#5f9a2a', '#111111'],
        'terminal' => ['#111111', '#111111', '#ffffff'],
    ];

    /**
     * Checks and tidies chart data from the builder.
     *
     * @return array{nodes: list<array>, edges: list<array>}
     */
    public static function clean(mixed $chart): array
    {
        $chart = is_string($chart) ? json_decode($chart, true) : $chart;
        if (! is_array($chart)) {
            throw ValidationException::withMessages(['chart' => 'The flowchart could not be read. Reload the page and try again.']);
        }

        $nodes = [];
        foreach (array_slice(array_values($chart['nodes'] ?? []), 0, self::MAX_BOXES + 1) as $i => $node) {
            $id = (string) ($node['id'] ?? '');
            $text = trim(mb_substr((string) ($node['text'] ?? ''), 0, 600));
            if (! preg_match('/^[a-z][a-z0-9]{0,15}$/', $id) || isset($nodes[$id])) {
                throw ValidationException::withMessages(['chart' => 'Box '.($i + 1).' has a bad ID. Reload the page and try again.']);
            }
            if ($text === '') {
                throw ValidationException::withMessages(['chart' => 'Box '.($i + 1).' is empty. Type its text or remove it.']);
            }
            $type = array_key_exists($node['type'] ?? '', ProtocolVersion::BOX_TYPES) ? $node['type'] : 'step';
            $link = isset($node['link']) && $node['link'] !== '' ? (int) $node['link'] : null;
            $nodes[$id] = [
                'id' => $id,
                'type' => $type,
                'text' => $text,
                'badges' => self::badges($node['badges'] ?? []),
                'link' => $link && Protocol::whereKey($link)->exists() ? $link : null,
            ];
        }
        if (count($nodes) > self::MAX_BOXES) {
            throw ValidationException::withMessages(['chart' => 'A flowchart can have up to '.self::MAX_BOXES.' boxes.']);
        }

        $edges = [];
        foreach (array_values($chart['edges'] ?? []) as $i => $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            if (! isset($nodes[$from], $nodes[$to])) {
                throw ValidationException::withMessages(['chart' => 'Arrow '.($i + 1).' needs a box at both ends.']);
            }
            $label = trim(mb_substr((string) ($edge['label'] ?? ''), 0, 30));
            $style = in_array($edge['style'] ?? '', self::ARROW_STYLES, true) ? $edge['style'] : 'one';
            $edges[] = ['from' => $from, 'to' => $to, 'label' => $label === '' ? null : $label, 'style' => $style];
        }

        return ['nodes' => array_values($nodes), 'edges' => $edges];
    }

    /** @return list<string> known badges, in their usual order */
    public static function badges(mixed $badges): array
    {
        $badges = is_array($badges) ? $badges : [];

        return array_values(array_filter(array_keys(ProtocolVersion::BADGES), fn ($b) => in_array($b, $badges, true)));
    }

    /** The badge column for a box or section. */
    public static function badgeHtml(array $badges): string
    {
        if (! $badges) {
            return '';
        }
        // Inline colors: Mermaid's box text color would otherwise win over the badge's.
        $html = '';
        foreach ($badges as $b) {
            $name = e(ProtocolVersion::BADGES[$b] ?? $b);
            $html .= $b === 'peds'
                ? "<span class='pb pb-peds' title='{$name}'><img src='".e(asset('images/stroller.svg'))."' alt='{$name}'></span>"
                : "<span class='pb pb-{$b}' title='{$name}' style='color:".($b === 'M' ? '#fff' : '#111')." !important'>".e($b).'</span>';
        }

        return "<span class='pbs'>{$html}</span>";
    }

    /**
     * The Mermaid source for the chart.
     *
     * @param  callable(int): ?string  $linkFor  the address of a linked protocol, or null when it can't be opened
     */
    public static function mermaid(array $chart, callable $linkFor): string
    {
        $lines = ['flowchart TD'];
        foreach ($chart['nodes'] ?? [] as $node) {
            $id = 'b_'.$node['id'];
            $inner = "<span class='pt'>".ProtocolText::label($node['text']).'</span>';
            $url = $node['link'] ? $linkFor($node['link']) : null;
            if ($url) {
                $inner = "<a class='plink' href='".e($url)."'>{$inner}</a>";
            }
            $label = "<span class='pn'>".self::badgeHtml($node['badges'] ?? []).$inner.'</span>';
            $label = str_replace('"', '#quot;', $label);
            $lines[] = $node['type'] === 'decision' ? "  {$id}{\"{$label}\"}" : "  {$id}[\"{$label}\"]";
            $lines[] = "  class {$id} t_{$node['type']}";
        }
        foreach ($chart['edges'] ?? [] as $edge) {
            $arrow = ['both' => '<-->', 'line' => '-.-'][$edge['style'] ?? 'one'] ?? '-->';
            $label = $edge['label'] ? '|"'.str_replace('"', '#quot;', e($edge['label'])).'"|' : '';
            $lines[] = "  b_{$edge['from']} {$arrow}{$label} b_{$edge['to']}";
        }
        foreach (self::STYLES as $type => [$fill, $stroke, $color]) {
            $lines[] = "  classDef t_{$type} fill:{$fill},stroke:{$stroke},stroke-width:2px,color:{$color}";
        }

        return implode("\n", $lines);
    }
}
