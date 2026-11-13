<?php

namespace App\Services\ProtocolImport;

use App\Models\AuditLog;
use App\Models\Protocol;
use App\Models\ProtocolCategory;
use App\Models\ProtocolImport;
use App\Models\ProtocolVersion;
use App\Support\ProtocolChart;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Works through uploaded protocol files, a couple of PDF pages per step, and
 * saves what the reader finds as draft protocols. Nothing is approved here:
 * every draft still goes through the builder and the Medical Director.
 */
class ProtocolImporter
{
    /** PDF pages per request. Small enough that the answer always fits. */
    public const PAGES_PER_STEP = 2;

    /** Tries for a step that failed for a reason that may pass (API busy, network). */
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly ProtocolReader $reader) {}

    public function ready(): bool
    {
        return $this->reader->ready();
    }

    /** Reads the next step of the oldest unfinished upload. Returns false when there is nothing to do. */
    public function runNext(): bool
    {
        // After a failed try, wait a little longer each time (2, 4, 6... minutes) before the next one.
        $import = ProtocolImport::whereIn('status', ['queued', 'working'])->orderBy('id')->get()
            ->first(fn (ProtocolImport $i) => ! $i->attempts || $i->updated_at->lte(now()->subMinutes(2 * $i->attempts)));
        if (! $import) {
            return false;
        }
        $this->step($import);

        return true;
    }

    public function step(ProtocolImport $import): void
    {
        if ($import->status === 'queued') {
            $import->fill(['status' => 'working', 'started_at' => now()])->save();
        }

        $pages = null;
        if ($import->kind === 'pdf' && $import->page_count) {
            $pages = [$import->next_page, min($import->page_count, $import->next_page + self::PAGES_PER_STEP - 1)];
        }
        $where = $pages ? ($pages[0] === $pages[1] ? "page {$pages[0]}" : "pages {$pages[0]}-{$pages[1]}") : 'the upload';

        try {
            $files = array_map(fn ($f) => ['path' => Storage::disk('local')->path($f['path']), 'mime' => $f['mime']], $import->files);
            $result = $this->reader->read($files, $pages);
        } catch (ReaderException $e) {
            $import->attempts++;
            $import->error = $e->getMessage();
            if (! $e->retry || $import->attempts >= self::MAX_ATTEMPTS) {
                $this->stop($import, "Stopped reading {$import->name} at {$where}: {$e->getMessage()}");
            } else {
                $import->save();
            }

            return;
        }

        $import->input_tokens += $result['input_tokens'];
        $import->output_tokens += $result['output_tokens'];
        $import->attempts = 0;
        $import->error = null;
        if ($result['notes']) {
            $import->addNote(($pages ? ucfirst($where).': ' : '').$result['notes']);
        }
        foreach ($result['protocols'] as $data) {
            try {
                $this->saveDraft($import, $data, $where);
            } catch (ValidationException $e) {
                $import->addNote('Skipped "'.($data['title'] ?? 'untitled').'" from '.$where.': '.collect($e->errors())->flatten()->first());
            }
        }

        if ($pages && $pages[1] < $import->page_count) {
            $import->next_page = $pages[1] + 1;
            $import->save();

            return;
        }
        $import->next_page = ($import->page_count ?? 0) + 1;
        $this->finish($import);
    }

    /** Saves one protocol the reader found as a new draft. */
    private function saveDraft(ProtocolImport $import, array $data, string $where): void
    {
        $title = trim(mb_substr((string) ($data['title'] ?? ''), 0, 150));
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'it had no title.']);
        }

        // Fresh ids, so whatever the reader called the boxes, they fit the builder's rules.
        $ids = [];
        $nodes = [];
        $links = [];
        foreach (array_slice($data['boxes'] ?? [], 0, ProtocolChart::MAX_BOXES) as $box) {
            $text = trim((string) ($box['text'] ?? ''));
            $key = (string) ($box['id'] ?? '');
            if ($text === '' || $key === '' || isset($ids[$key])) {
                continue;
            }
            $id = $ids[$key] = 'n'.(count($ids) + 1);
            $nodes[] = ['id' => $id, 'type' => $box['type'] ?? 'step', 'text' => $text, 'badges' => $box['badges'] ?? [], 'link' => null,
                'x' => (int) ($box['x'] ?? 0), 'y' => (int) ($box['y'] ?? 0)];
            if (in_array($box['type'] ?? '', ['exit', 'reference'], true) && trim((string) ($box['exit_to'] ?? '')) !== '') {
                $links[$id] = trim($box['exit_to']);
            }
        }
        // Keep the whole chart on the canvas.
        if ($nodes) {
            $dx = max(0, 150 - min(array_column($nodes, 'x')));
            $dy = max(0, 80 - min(array_column($nodes, 'y')));
            $nodes = array_map(fn ($n) => ['x' => $n['x'] + $dx, 'y' => $n['y'] + $dy] + $n, $nodes);
        }
        $edges = [];
        foreach ($data['arrows'] ?? [] as $arrow) {
            $from = $ids[$arrow['from'] ?? ''] ?? null;
            $to = $ids[$arrow['to'] ?? ''] ?? null;
            if ($from && $to && $from !== $to) {
                $edges[] = ['from' => $from, 'to' => $to] + array_intersect_key($arrow, array_flip(['label', 'style', 'from_side', 'to_side']));
            }
        }
        $chart = ProtocolChart::clean(['layout' => 'manual', 'nodes' => $nodes, 'edges' => $edges]);

        $sections = [];
        foreach (array_slice($data['sections'] ?? [], 0, 20) as $s) {
            $heading = trim(mb_substr((string) ($s['heading'] ?? ''), 0, 60));
            $text = trim(mb_substr((string) ($s['text'] ?? ''), 0, 10000));
            if ($heading !== '' && $text !== '') {
                $sections[] = [
                    'heading' => $heading,
                    'color' => array_key_exists($s['color'] ?? '', ProtocolVersion::COLORS) ? $s['color'] : 'navy',
                    'badges' => ProtocolChart::badges($s['badges'] ?? []),
                    'text' => $text,
                ];
            }
        }
        if (! $chart['nodes'] && ! $sections) {
            throw ValidationException::withMessages(['chart' => 'no flowchart or sections were found.']);
        }

        $category = $this->category($data['category'] ?? null);
        $ages = array_values(array_intersect(array_keys(ProtocolVersion::AGES), $data['ages'] ?? [])) ?: ['adult'];
        $attributes = [
            'number' => trim(mb_substr((string) ($data['number'] ?? ''), 0, 30)) ?: null,
            'title' => $title,
            'protocol_category_id' => $category?->id,
            'category_name' => $category?->name,
            'ages' => $ages,
            'revised_label' => trim(mb_substr((string) ($data['revised'] ?? ''), 0, 60)) ?: null,
            'chart' => $chart,
            'sections' => $sections,
        ];
        $attributes['search_text'] = (new ProtocolVersion($attributes))->buildSearchText();

        $version = DB::transaction(function () use ($attributes, $import) {
            $protocol = Protocol::create();

            return $protocol->versions()->create($attributes + [
                'version' => 1, 'status' => 'draft', 'created_by' => $import->uploaded_by, 'updated_by' => $import->uploaded_by,
            ]);
        });

        $import->protocol_ids = [...($import->protocol_ids ?? []), $version->protocol_id];
        if ($links) {
            $import->pending_links = [...($import->pending_links ?? []), ['protocol' => $version->protocol_id, 'boxes' => $links]];
        }
        $same = ProtocolVersion::where('title', $title)->where('protocol_id', '!=', $version->protocol_id)->exists();
        if ($same) {
            $import->addNote("{$version->fullTitle()} has the same title as a protocol that was already here. Delete whichever draft you don't need.");
        }
        AuditLog::record('system.protocol-import', "Imported {$version->fullTitle()} from {$import->name} ({$where}) as a draft: "
            .count($chart['nodes']).' boxes, '.count($chart['edges']).' arrows, sections ['.collect($sections)->pluck('heading')->implode(', ').'], category '
            .($category?->name ?? 'none').', ages '.$version->agesLabel().'. It needs Medical Director approval before Team Members see it.');
        $import->save();
    }

    /** The category with this name, added if it's new. */
    private function category(?string $name): ?ProtocolCategory
    {
        $name = trim(mb_substr((string) $name, 0, 60));
        if ($name === '') {
            return null;
        }
        $found = ProtocolCategory::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
        if ($found) {
            return $found;
        }
        $category = ProtocolCategory::create(['name' => $name, 'position' => (int) ProtocolCategory::max('position') + 1]);
        AuditLog::record('system.protocol-import', "Added the {$category->name} protocol category, found on an imported protocol.");

        return $category;
    }

    /** After the last page: points Exit boxes at the protocols they name, then marks the upload done. */
    private function finish(ProtocolImport $import): void
    {
        $byName = [];
        foreach (Protocol::with('latest')->get() as $p) {
            if ($p->latest && ! $p->retired_at) {
                $byName[self::key($p->latest->title)] ??= $p->id;
            }
        }
        $linked = 0;
        foreach ($import->pending_links ?? [] as $pending) {
            $version = Protocol::find($pending['protocol'])?->openVersion();
            if (! $version?->isEditable()) {
                continue;
            }
            $chart = $version->chart;
            foreach ($chart['nodes'] as &$node) {
                $name = $pending['boxes'][$node['id']] ?? null;
                if (! $name) {
                    continue;
                }
                $target = $byName[self::key($name)] ?? null;
                if ($target && $target !== $version->protocol_id) {
                    $node['link'] = $target;
                    $linked++;
                } elseif (! $target && $node['type'] === 'exit') {
                    $import->addNote("In {$version->fullTitle()}, the box \"".str_replace("\n", ' ', $node['text'])."\" names the {$name} protocol, which isn't in the system yet. Link it in the builder once it is.");
                }
            }
            unset($node);
            $version->update(['chart' => $chart]);
        }

        $count = count($import->protocol_ids ?? []);
        $import->fill(['status' => 'done', 'finished_at' => now(), 'pending_links' => null, 'error' => null])->save();
        AuditLog::record('system.protocol-import', "Finished reading {$import->name}: {$count} draft protocol(s), {$linked} box link(s) set, about \${$import->cost()} of API use.");
    }

    private function stop(ProtocolImport $import, string $message): void
    {
        $import->fill(['status' => 'failed', 'finished_at' => now()])->save();
        AuditLog::record('system.protocol-import', $message);
    }

    /** "Exit to ROSC Protocol" and "ROSC" match. */
    private static function key(string $title): string
    {
        $t = mb_strtolower($title);
        $t = preg_replace('/\b(exit to|treatment per|protocol)\b/u', ' ', $t);

        return preg_replace('/[^a-z0-9]+/u', '', $t);
    }

    /** Page count of a PDF, or null if it can't be told without a PDF library. */
    public static function pdfPageCount(string $path): ?int
    {
        $pdf = (string) @file_get_contents($path);
        $pages = preg_match_all('/\/Type\s*\/Page(?![s\w])/', $pdf);
        if ($pages) {
            return $pages;
        }
        // Compressed PDFs hide their pages; the page tree's /Count is the next best guess.
        if (preg_match_all('/\/Type\s*\/Pages\b[^>]*?\/Count\s+(\d+)|\/Count\s+(\d+)[^>]*?\/Type\s*\/Pages\b/s', $pdf, $m)) {
            $counts = array_filter(array_map('intval', array_merge($m[1], $m[2])));

            return $counts ? max($counts) : null;
        }

        return null;
    }

    /** Deletes an upload's files. */
    public static function deleteFiles(ProtocolImport $import): void
    {
        try {
            Storage::disk('local')->deleteDirectory('protocol-imports/'.$import->id);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
