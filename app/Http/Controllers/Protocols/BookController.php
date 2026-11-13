<?php

namespace App\Http\Controllers\Protocols;

use App\Http\Controllers\Controller;
use App\Models\Protocol;
use App\Models\ProtocolCategory;
use App\Models\ProtocolVersion;
use App\Support\ProtocolLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** The protocol book Team Members read: approved versions only. */
class BookController extends Controller
{
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $versions = $this->search($filters);

        return view('protocols.index', [
            'filters' => $filters,
            'versions' => $versions,
            'groups' => $versions->groupBy(fn ($v) => $v->category_name ?? 'Other'),
            'categories' => ProtocolCategory::ordered(),
            'snippets' => $filters['q'] ? $versions->mapWithKeys(fn ($v) => [$v->id => $this->snippet($v->search_text, $filters['q'])]) : collect(),
        ]);
    }

    /** Every matching protocol in full, one after another (the whole book, a category or an age group). */
    public function book(Request $request)
    {
        $filters = $this->filters($request);

        return view('protocols.book', [
            'filters' => $filters,
            'versions' => $this->search($filters),
            'categoryName' => $filters['category'] ? ProtocolCategory::find($filters['category'])?->name : null,
            'linkFor' => ProtocolLinks::forBook(),
        ]);
    }

    public function show(Request $request, Protocol $protocol)
    {
        abort_unless($protocol->isLive(), 404);

        return view('protocols.show', ['v' => $protocol->approved, 'protocol' => $protocol, 'linkFor' => ProtocolLinks::forBook()]);
    }

    /** Any version, for builders and the Medical Director. */
    public function version(Request $request, ProtocolVersion $version)
    {
        abort_unless($request->user()->hasAnyPermission('protocols_build', 'protocols_approve'), 403);

        return view('protocols.show', ['v' => $version, 'protocol' => $version->protocol, 'linkFor' => ProtocolLinks::forBook()]);
    }

    private function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'category' => $request->integer('category') ?: null,
            'age' => array_key_exists((string) $request->query('age'), ProtocolVersion::AGES) ? $request->query('age') : null,
        ];
    }

    private function search(array $filters)
    {
        $versions = ProtocolVersion::with('category')
            ->whereIn('id', Protocol::inBook()->select('approved_version_id'))
            ->when($filters['category'], fn ($q, $c) => $q->where('protocol_category_id', $c))
            ->when($filters['age'], fn ($q, $a) => $q->whereJsonContains('ages', $a));
        foreach (array_slice(preg_split('/\s+/', $filters['q'], -1, PREG_SPLIT_NO_EMPTY), 0, 8) as $word) {
            $versions->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
        }

        return $versions->get()->sortBy(fn ($v) => [$v->category?->position ?? 999, $v->category_name ?? '~', $v->number ?? '', $v->title])->values();
    }

    /** A bit of text around the first search word. */
    private function snippet(?string $text, string $q): ?string
    {
        $word = preg_split('/\s+/', $q)[0] ?? '';
        $at = $text && $word !== '' ? mb_stripos($text, $word) : false;
        if ($at === false) {
            return null;
        }
        $start = max(0, $at - 70);

        return ($start > 0 ? '…' : '').Str::limit(mb_substr($text, $start), 180);
    }
}
