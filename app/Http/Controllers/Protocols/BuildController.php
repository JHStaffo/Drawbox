<?php

namespace App\Http\Controllers\Protocols;

use App\Http\Controllers\Controller;
use App\Models\Protocol;
use App\Models\ProtocolCategory;
use App\Models\ProtocolVersion;
use App\Support\ProtocolChart;
use App\Support\ProtocolLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The protocol builder. Builders write drafts and send them to the Medical
 * Director; an approved version is never edited, so a change starts a new
 * draft while the approved one stays in the book.
 */
class BuildController extends Controller
{
    public function index()
    {
        $protocols = Protocol::with(['latest.category', 'latest.editor', 'approved'])->get()
            ->sortBy(fn (Protocol $p) => [$p->latest?->category?->position ?? 999, $p->latest?->number ?? '', $p->latest?->title ?? ''])
            ->values();

        return view('protocols.manage.index', [
            'protocols' => $protocols,
            'categories' => ProtocolCategory::ordered(),
            'used' => ProtocolVersion::whereNotNull('protocol_category_id')->distinct()->pluck('protocol_category_id')->all(),
        ]);
    }

    public function create()
    {
        return view('protocols.manage.edit', $this->formData(null, new ProtocolVersion([
            'ages' => ['adult'],
            'chart' => ['nodes' => [], 'edges' => []],
            'sections' => collect(ProtocolVersion::SECTION_PRESETS)->map(fn ($color, $heading) => ['heading' => $heading, 'color' => $color, 'badges' => $heading === 'Pediatrics' ? ['peds'] : [], 'text' => ''])->values()->all(),
        ])));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $version = DB::transaction(function () use ($data, $request) {
            $protocol = Protocol::create();

            return $protocol->versions()->create($data + ['version' => 1, 'status' => 'draft', 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        });
        audit_note("Started protocol {$version->fullTitle()} (version 1, draft): ".$this->summary($version).'.');

        return redirect()->route('protocols.manage.edit', $version->protocol_id)->with('status', "Saved {$version->title} as a draft. Send it for approval when it's ready.");
    }

    public function edit(Protocol $protocol)
    {
        $open = $protocol->openVersion();

        return view('protocols.manage.edit', $this->formData($protocol, $open ?? $protocol->approved ?? $protocol->latest));
    }

    public function update(Request $request, Protocol $protocol)
    {
        $version = $protocol->openVersion();
        if (! $version?->isEditable()) {
            return back()->with('error', $version ? 'This version is waiting for approval. Take it back to change it.' : 'Start a revision before making changes.');
        }
        $data = $this->validated($request);
        $before = $version->replicate();
        $version->update($data + ['updated_by' => $request->user()->id]);
        $changes = $this->changes($before, $version);
        audit_note("Saved the draft of {$version->fullTitle()} (version {$version->version}). ".($changes ? 'Changed '.$changes.'.' : 'Nothing changed.'));

        return back()->with('status', "Saved the draft of {$version->title}.");
    }

    /** Renders a draft from the builder without saving it. */
    public function preview(Request $request)
    {
        try {
            $version = new ProtocolVersion($this->validated($request));
        } catch (ValidationException $e) {
            return response()->view('protocols.partials.preview-error', ['errors' => $e->errors()], 422);
        }
        $version->status = 'draft';

        return view('protocols.partials.page', ['v' => $version, 'linkFor' => ProtocolLinks::forBook()]);
    }

    /** Starts a new draft from the approved version. */
    public function revise(Request $request, Protocol $protocol)
    {
        if ($protocol->openVersion()) {
            return back()->with('error', 'There is already a draft of this protocol.');
        }
        $from = $protocol->approved ?? $protocol->latest;
        $version = $protocol->versions()->create([
            ...$from->only(['number', 'title', 'protocol_category_id', 'category_name', 'ages', 'chart', 'sections', 'revised_label', 'search_text']),
            'version' => $protocol->versions()->max('version') + 1,
            'status' => 'draft',
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);
        audit_note("Started version {$version->version} of {$version->fullTitle()} from version {$from->version}.");

        return back()->with('status', "Started version {$version->version}. The approved version stays in the book until this one is approved.");
    }

    public function submit(Request $request, Protocol $protocol)
    {
        $version = $protocol->openVersion();
        if (! $version?->isEditable()) {
            return back()->with('error', 'There is no draft to send.');
        }
        $from = $version->statusLabel();
        $version->update(['status' => 'submitted', 'submitted_by' => $request->user()->id, 'submitted_at' => now()]);
        audit_note("Sent {$version->fullTitle()} (version {$version->version}) for Medical Director approval. Status from {$from} to Waiting for approval.");

        return back()->with('status', "Sent {$version->title} to the Medical Director for approval.");
    }

    public function withdraw(Protocol $protocol)
    {
        $version = $protocol->openVersion();
        if ($version?->status !== 'submitted') {
            return back()->with('error', 'That version isn\'t waiting for approval.');
        }
        $version->update(['status' => 'draft', 'submitted_at' => null, 'submitted_by' => null]);
        audit_note("Took back {$version->fullTitle()} (version {$version->version}) from approval. Status from Waiting for approval to Draft.");

        return back()->with('status', "Took {$version->title} back. It's a draft again.");
    }

    public function discard(Protocol $protocol)
    {
        $version = $protocol->openVersion();
        if (! $version) {
            return back()->with('error', 'There is no draft to delete.');
        }
        $title = $version->fullTitle();
        $version->delete();
        if (! $protocol->versions()->exists()) {
            $protocol->delete();
            audit_note("Deleted the draft protocol {$title} (it was never approved).");

            return redirect()->route('protocols.manage')->with('status', "Deleted the draft of {$title}.");
        }
        audit_note("Deleted the draft (version {$version->version}) of {$title}. The approved version is unchanged.");

        return back()->with('status', "Deleted the draft of {$title}.");
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:60|unique:protocol_categories,name']);
        $category = ProtocolCategory::create(['name' => trim($data['name']), 'position' => (int) ProtocolCategory::max('position') + 1]);

        return back()->with('status', "Added the {$category->name} category.");
    }

    public function updateCategory(Request $request, ProtocolCategory $category)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('protocol_categories', 'name')->ignore($category->id)],
            'position' => 'required|integer|min:0|max:999',
        ]);
        $category->update(['name' => trim($data['name']), 'position' => $data['position']]);

        return back()->with('status', "Saved the {$category->name} category. Approved protocols keep the name they were approved with.");
    }

    public function destroyCategory(ProtocolCategory $category)
    {
        if (ProtocolVersion::where('protocol_category_id', $category->id)->exists()) {
            return back()->with('error', "A protocol is in {$category->name}, so it can't be deleted.");
        }
        $category->delete();

        return back()->with('status', "Deleted the {$category->name} category.");
    }

    private function formData(?Protocol $protocol, ProtocolVersion $version): array
    {
        return [
            'protocol' => $protocol,
            'version' => $version,
            'history' => $protocol ? $protocol->versions()->with(['editor', 'reviewer', 'submitter'])->get() : collect(),
            'categories' => ProtocolCategory::ordered(),
            'others' => ProtocolVersion::with('protocol')
                ->whereIn('id', Protocol::query()->get()->map(fn ($p) => $p->approved_version_id ?? $p->latest?->id)->filter())
                ->when($protocol, fn ($q) => $q->where('protocol_id', '!=', $protocol->id))
                ->orderBy('title')->get(),
        ];
    }

    /** The builder's fields, checked and tidied. */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'number' => 'nullable|string|max:30',
            'title' => 'required|string|max:150',
            'protocol_category_id' => 'nullable|exists:protocol_categories,id',
            'ages' => 'required|array|min:1',
            'ages.*' => Rule::in(array_keys(ProtocolVersion::AGES)),
            'revised_label' => 'nullable|string|max:60',
            'chart' => 'required|string|max:200000',
            'sections' => 'required|string|max:200000',
        ], ['ages.required' => 'Pick at least one age group.']);

        $data['chart'] = ProtocolChart::clean($data['chart']);
        $data['sections'] = $this->cleanSections($data['sections']);
        $data['ages'] = array_values(array_intersect(array_keys(ProtocolVersion::AGES), $data['ages']));
        $data['number'] = trim((string) $data['number']) ?: null;
        $data['title'] = trim($data['title']);
        $data['category_name'] = $data['protocol_category_id'] ? ProtocolCategory::find($data['protocol_category_id'])->name : null;
        $data['search_text'] = (new ProtocolVersion($data))->buildSearchText();

        return $data;
    }

    private function cleanSections(string $json): array
    {
        $sections = json_decode($json, true);
        if (! is_array($sections)) {
            throw ValidationException::withMessages(['sections' => 'The sections could not be read. Reload the page and try again.']);
        }
        $clean = [];
        foreach (array_slice(array_values($sections), 0, 20) as $s) {
            $heading = trim(mb_substr((string) ($s['heading'] ?? ''), 0, 60));
            $text = trim(mb_substr((string) ($s['text'] ?? ''), 0, 10000));
            if ($text === '') {
                continue; // An empty section is left off the page.
            }
            if ($heading === '') {
                throw ValidationException::withMessages(['sections' => 'Every section with text needs a heading.']);
            }
            $clean[] = [
                'heading' => $heading,
                'color' => array_key_exists($s['color'] ?? '', ProtocolVersion::COLORS) ? $s['color'] : 'navy',
                'badges' => ProtocolChart::badges($s['badges'] ?? []),
                'text' => $text,
            ];
        }

        return $clean;
    }

    private function summary(ProtocolVersion $v): string
    {
        return collect([
            'number '.($v->number ?? 'none'),
            'category '.($v->category_name ?? 'none'),
            'ages '.$v->agesLabel(),
            count($v->boxes()).' boxes',
            count($v->arrows()).' arrows',
            'sections ['.collect($v->sections)->pluck('heading')->implode(', ').']',
        ])->implode(', ');
    }

    /** What a save changed, from and to. */
    private function changes(ProtocolVersion $old, ProtocolVersion $new): string
    {
        $parts = [];
        foreach (['number' => 'number', 'title' => 'title', 'category_name' => 'category', 'revised_label' => 'revised'] as $field => $label) {
            if ($old->$field !== $new->$field) {
                $parts[] = "{$label} from ".($old->$field ?? 'none').' to '.($new->$field ?? 'none');
            }
        }
        if ($old->agesLabel() !== $new->agesLabel()) {
            $parts[] = "ages from {$old->agesLabel()} to {$new->agesLabel()}";
        }

        $oldBoxes = collect($old->boxes())->keyBy('id');
        $newBoxes = collect($new->boxes())->keyBy('id');
        $short = fn (array $b) => '"'.mb_strimwidth(str_replace("\n", ' ', $b['text']), 0, 60, '…').'"';
        foreach ($newBoxes as $id => $box) {
            $was = $oldBoxes->get($id);
            if (! $was) {
                $parts[] = 'added box '.$short($box);
            } elseif ($was != $box) {
                $diff = [];
                if ($was['text'] !== $box['text']) {
                    $diff[] = 'text from '.$short($was).' to '.$short($box);
                }
                if ($was['type'] !== $box['type']) {
                    $diff[] = "type from {$was['type']} to {$box['type']}";
                }
                if ($was['badges'] !== $box['badges']) {
                    $diff[] = 'badges from ['.implode(', ', $was['badges']).'] to ['.implode(', ', $box['badges']).']';
                }
                if ($was['link'] !== $box['link']) {
                    $diff[] = 'link from protocol '.($was['link'] ? '#'.$was['link'] : 'none').' to '.($box['link'] ? '#'.$box['link'] : 'none');
                }
                $parts[] = 'box '.$short($box).': '.implode(', ', $diff);
            }
        }
        foreach ($oldBoxes->diffKeys($newBoxes) as $box) {
            $parts[] = 'removed box '.$short($box);
        }
        if ($old->arrows() != $new->arrows()) {
            $parts[] = 'arrows from '.count($old->arrows()).' to '.count($new->arrows()).' (connections changed)';
        }

        $oldSections = collect($old->sections)->keyBy('heading');
        $newSections = collect($new->sections)->keyBy('heading');
        foreach ($newSections as $heading => $s) {
            $was = $oldSections->get($heading);
            if (! $was) {
                $parts[] = "added section {$heading}";
            } elseif ($was != $s) {
                $parts[] = "section {$heading} changed".($was['text'] !== $s['text'] ? ' (text from "'.mb_strimwidth($was['text'], 0, 80, '…').'" to "'.mb_strimwidth($s['text'], 0, 80, '…').'")' : '');
            }
        }
        foreach ($oldSections->diffKeys($newSections) as $heading => $s) {
            $parts[] = "removed section {$heading}";
        }
        if (! $parts && collect($old->sections)->pluck('heading')->all() !== collect($new->sections)->pluck('heading')->all()) {
            $parts[] = 'section order';
        }

        return implode('; ', $parts);
    }
}
