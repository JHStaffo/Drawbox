@extends('layouts.app', ['title' => $protocol ? $version->title : 'New protocol'])

@php
    use App\Models\ProtocolVersion as PV;
    $editable = ! $protocol || $version->isEditable();
    $open = $protocol ? in_array($version->status, PV::OPEN, true) : true;
@endphp

@section('band')
    <h1>{{ $protocol ? $version->fullTitle() : 'New protocol' }}</h1>
    <p>
        @if (! $protocol) Build the flowchart and sections, save it as a draft, then send it to the Medical Director.
        @else Version {{ $version->version }} · {{ $version->statusLabel() }}
            @if ($protocol->approved && $protocol->approved->id !== $version->id) · Version {{ $protocol->approved->version }} is in the book @endif
            @if ($protocol->retired_at) · Retired @endif
        @endif
    </p>
@endsection

@section('content')
<div class="stack">
    <p><a href="{{ route('protocols.manage') }}">← All protocols</a></p>

    @if ($protocol)
        <div class="card">
            @if ($version->status === 'returned' && $version->review_note)
                <div class="flash flash-error" style="margin-top:0"><strong>Sent back by {{ $version->reviewer?->name }} on {{ $version->reviewed_at?->format('M j, Y') }}:</strong> {{ $version->review_note }}</div>
            @endif
            <div class="row-actions" style="margin:0">
                @if ($version->isEditable())
                    <form method="post" action="{{ route('protocols.manage.submit', $protocol) }}" onsubmit="return confirm('Send version {{ $version->version }} to the Medical Director? Save your changes first.')">@csrf
                        <button class="btn btn-teal" type="submit">Send for approval</button>
                    </form>
                @elseif ($version->status === 'submitted')
                    <span class="pill pill-teal">Waiting for the Medical Director since {{ $version->submitted_at?->format('M j, g:i A') }}</span>
                    <form method="post" action="{{ route('protocols.manage.withdraw', $protocol) }}">@csrf
                        <button class="btn btn-small btn-outline" type="submit">Take it back to edit</button>
                    </form>
                @else
                    <form method="post" action="{{ route('protocols.manage.revise', $protocol) }}">@csrf
                        <button class="btn" type="submit">Start a revision</button>
                    </form>
                    <span class="hint">Changes go in a new version. Version {{ $version->version }} stays in the book until the new one is approved.</span>
                @endif
                @if ($open)
                    <form method="post" action="{{ route('protocols.manage.discard', $protocol) }}" onsubmit="return confirm('Delete this draft? This can\'t be undone.')">@csrf @method('delete')
                        <button class="btn btn-small btn-outline" type="submit">Delete draft</button>
                    </form>
                @endif
                @if ($protocol->isLive())<a class="btn btn-small btn-outline" href="{{ route('protocols.show', $protocol) }}">Open in the book</a>@endif
            </div>
        </div>
    @endif

    @if ($editable)
        <form id="builder" method="post" action="{{ $protocol ? route('protocols.manage.update', $protocol) : route('protocols.manage.store') }}">
            @csrf @if ($protocol) @method('put') @endif
            <input type="hidden" name="chart" id="chart-json">
            <input type="hidden" name="sections" id="sections-json">

            <div class="card">
                <h2>Details</h2>
                <div class="field-row">
                    <div class="field">
                        <label for="number">Number</label>
                        <input id="number" type="text" name="number" value="{{ old('number', $version->number) }}" maxlength="30" placeholder="e.g. 4.1">
                    </div>
                    <div class="field" style="grid-column: span 2">
                        <label for="title">Title</label>
                        <input id="title" type="text" name="title" value="{{ old('title', $version->title) }}" required maxlength="150" placeholder="e.g. Cardiac Arrest">
                    </div>
                    <div class="field">
                        <label for="protocol_category_id">Category</label>
                        <select id="protocol_category_id" name="protocol_category_id">
                            <option value="">None</option>
                            @foreach ($categories as $c)<option value="{{ $c->id }}" @selected((int) old('protocol_category_id', $version->protocol_category_id) === $c->id)>{{ $c->name }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>Ages</label>
                        <div class="row-actions" style="margin:0">
                            @foreach (PV::AGES as $key => $label)
                                <label class="check"><input type="checkbox" name="ages[]" value="{{ $key }}" @checked(in_array($key, old('ages', $version->ages ?? []), true))> {{ $label }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div class="field">
                        <label for="revised_label">Revised (footer)</label>
                        <input id="revised_label" type="text" name="revised_label" value="{{ old('revised_label', $version->revised_label) }}" maxlength="60" placeholder="e.g. Revised 2020">
                        <div class="hint">"Reviewed" fills in from the Medical Director's approval.</div>
                    </div>
                </div>
            </div>

            <div class="builder-grid mt">
                <div class="stack">
                    <div class="card">
                        <h2>Flowchart boxes</h2>
                        <p class="hint">Type <code>**bold**</code> or <code>__underline__</code>; a new line starts a new line in the box. Badges are optional. Exit and "Treatment per" boxes can open another protocol.</p>
                        <div id="boxes"></div>
                        <button class="btn btn-small btn-outline mt" type="button" id="add-box">Add box</button>
                    </div>
                    <div class="card">
                        <h2>Arrows</h2>
                        <p class="hint">→ one way, ↔ both ways, ┄ a dotted line with no arrowhead (to attach a note to a box). The label is optional: pick YES or NO, or type your own.</p>
                        <div id="arrows"></div>
                        <button class="btn btn-small btn-outline mt" type="button" id="add-arrow">Add arrow</button>
                        <datalist id="arrow-labels"><option value="YES"><option value="NO"></datalist>
                    </div>
                    <div class="card">
                        <h2>Sections</h2>
                        <p class="hint">Goals, Treatment and so on, with a colored label down the side. Start a line with <code>- </code> for a bullet (indent it for a sub-bullet). Empty sections are left off.</p>
                        <div id="sections"></div>
                        <button class="btn btn-small btn-outline mt" type="button" id="add-section">Add section</button>
                        <datalist id="section-headings">@foreach (PV::SECTION_PRESETS as $h => $c)<option value="{{ $h }}">@endforeach</datalist>
                    </div>
                    <div class="card builder-save">
                        <button class="btn" type="submit">{{ $protocol ? 'Save draft' : 'Save as draft' }}</button>
                        <span class="hint">Saving never changes the book. Send it for approval when it's ready.</span>
                    </div>
                </div>
                <div class="builder-preview">
                    <div class="stat-label">Preview</div>
                    <div id="preview"><p class="empty">Add a box to see the flowchart.</p></div>
                </div>
            </div>
        </form>
    @else
        @include('protocols.partials.page', ['v' => $version, 'linkFor' => \App\Support\ProtocolLinks::forBook()])
    @endif

    @if ($history->isNotEmpty())
        <div class="card">
            <h2>Versions</h2>
            <div class="table-wrap"><table>
                <tr><th>Version</th><th>Status</th><th>Last edited</th><th>Approval</th><th></th></tr>
                @foreach ($history as $h)
                    <tr>
                        <td>{{ $h->version }}</td>
                        <td>{{ $h->statusLabel() }}</td>
                        <td>{{ $h->updated_at?->format('M j, Y g:i A') }}<div class="hint">{{ $h->editor?->name }}</div></td>
                        <td>
                            @if ($h->reviewed_at){{ $h->status === 'returned' ? 'Sent back' : 'Approved' }} {{ $h->reviewed_at->format('M j, Y') }}<div class="hint">{{ $h->status === 'returned' ? $h->reviewer?->name : 'Signed '.$h->signature }}</div>
                            @elseif ($h->submitted_at)Sent {{ $h->submitted_at->format('M j, Y') }}<div class="hint">{{ $h->submitter?->name }}</div>@else — @endif
                        </td>
                        <td><a href="{{ route('protocols.version', $h) }}">View</a></td>
                    </tr>
                @endforeach
            </table></div>
        </div>
    @endif
</div>

@if ($editable)
@push('scripts')
<script>
(function () {
    var TYPES = @json(PV::BOX_TYPES);
    var BADGES = @json(PV::BADGES);
    var COLORS = @json(PV::COLORS);
    var PRESETS = @json(PV::SECTION_PRESETS);
    var PROTOCOLS = @json($others->map(fn ($o) => ['id' => $o->protocol_id, 'title' => $o->fullTitle()])->values());
    var chart = @json(old('chart') ? json_decode(old('chart'), true) : $version->chart) || { nodes: [], edges: [] };
    var sections = @json(old('sections') ? json_decode(old('sections'), true) : $version->sections) || [];
    chart.nodes = chart.nodes || []; chart.edges = chart.edges || [];

    var form = document.getElementById('builder');
    var boxesEl = document.getElementById('boxes'), arrowsEl = document.getElementById('arrows'), sectionsEl = document.getElementById('sections');

    function el(tag, attrs, children) {
        var e = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') e.textContent = attrs[k];
            else if (k.indexOf('on') === 0) e.addEventListener(k.slice(2), attrs[k]);
            else if (attrs[k] !== false && attrs[k] !== null && attrs[k] !== undefined) e.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
        });
        (children || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return e;
    }
    function options(map, selected, blank) {
        var list = blank ? [el('option', { value: '', text: blank })] : [];
        Object.keys(map).forEach(function (k) { list.push(el('option', { value: k, text: map[k], selected: String(k) === String(selected) })); });
        return list;
    }
    function boxName(id) {
        var i = chart.nodes.findIndex(function (n) { return n.id === id; });
        if (i < 0) return '?';
        var t = (chart.nodes[i].text || '').replace(/\*\*|__/g, '').replace(/\s+/g, ' ').trim();
        return (i + 1) + ': ' + (t.length > 40 ? t.slice(0, 40) + '…' : t || '(empty)');
    }
    function move(list, i, by) {
        var j = i + by; if (j < 0 || j >= list.length) return;
        var t = list[i]; list[i] = list[j]; list[j] = t;
    }
    function badgeChecks(item, onchange) {
        return el('div', { class: 'badge-picks' }, Object.keys(BADGES).map(function (b) {
            return el('label', { class: 'badge-pick', title: BADGES[b] }, [
                el('input', { type: 'checkbox', checked: (item.badges || []).indexOf(b) >= 0, onchange: function (e) {
                    item.badges = (item.badges || []).filter(function (x) { return x !== b; });
                    if (e.target.checked) item.badges.push(b);
                    onchange();
                } }),
                el('span', { class: 'pb pb-' + b }, [b === 'peds' ? el('img', { src: @json(asset('images/stroller.svg')), alt: '' }) : b]),
            ]);
        }));
    }

    function renderBoxes() {
        boxesEl.innerHTML = '';
        chart.nodes.forEach(function (n, i) {
            var linkMap = {}; PROTOCOLS.forEach(function (p) { linkMap[p.id] = p.title; });
            boxesEl.appendChild(el('div', { class: 'builder-row' }, [
                el('div', { class: 'builder-row-head' }, [
                    el('strong', { text: 'Box ' + (i + 1) }),
                    el('select', { 'aria-label': 'Box type', onchange: function (e) { n.type = e.target.value; changed(); } }, options(TYPES, n.type)),
                    el('span', { class: 'builder-row-tools' }, [
                        el('button', { type: 'button', class: 'btn btn-small btn-outline', title: 'Move up', text: '↑', onclick: function () { move(chart.nodes, i, -1); renderAll(); } }),
                        el('button', { type: 'button', class: 'btn btn-small btn-outline', title: 'Move down', text: '↓', onclick: function () { move(chart.nodes, i, 1); renderAll(); } }),
                        el('button', { type: 'button', class: 'btn btn-small btn-outline', title: 'Remove box', text: '✕', onclick: function () {
                            if (!confirm('Remove box ' + (i + 1) + ' and its arrows?')) return;
                            chart.nodes.splice(i, 1);
                            chart.edges = chart.edges.filter(function (e) { return e.from !== n.id && e.to !== n.id; });
                            renderAll();
                        } }),
                    ]),
                ]),
                el('textarea', { rows: 2, 'aria-label': 'Box text', placeholder: 'What the box says', oninput: function (e) { n.text = e.target.value; changed(true); } }, [n.text || '']),
                el('div', { class: 'builder-row-foot' }, [
                    badgeChecks(n, changed),
                    el('select', { 'aria-label': 'Opens protocol', class: 'builder-link', onchange: function (e) { n.link = e.target.value ? parseInt(e.target.value, 10) : null; changed(); } }, options(linkMap, n.link || '', 'Doesn\'t open another protocol')),
                ]),
            ]));
        });
    }

    function renderArrows() {
        arrowsEl.innerHTML = '';
        var names = {}; chart.nodes.forEach(function (n) { names[n.id] = boxName(n.id); });
        chart.edges.forEach(function (a, i) {
            arrowsEl.appendChild(el('div', { class: 'builder-arrow' }, [
                el('select', { 'aria-label': 'From', onchange: function (e) { a.from = e.target.value; changed(); } }, options(names, a.from, 'From…')),
                el('select', { 'aria-label': 'Arrow style', class: 'builder-arrow-style', onchange: function (e) { a.style = e.target.value; changed(); } }, options({ one: '→', both: '↔', line: '┄' }, a.style || 'one')),
                el('select', { 'aria-label': 'To', onchange: function (e) { a.to = e.target.value; changed(); } }, options(names, a.to, 'To…')),
                el('input', { type: 'text', list: 'arrow-labels', maxlength: 30, placeholder: 'Label', value: a.label || '', 'aria-label': 'Label', oninput: function (e) { a.label = e.target.value; changed(true); } }),
                el('button', { type: 'button', class: 'btn btn-small btn-outline', title: 'Remove arrow', text: '✕', onclick: function () { chart.edges.splice(i, 1); renderArrows(); changed(); } }),
            ]));
        });
    }

    function renderSections() {
        sectionsEl.innerHTML = '';
        var colorNames = {}; Object.keys(COLORS).forEach(function (c) { colorNames[c] = c.charAt(0).toUpperCase() + c.slice(1); });
        sections.forEach(function (s, i) {
            var swatch = el('span', { class: 'builder-swatch', style: 'background:' + (COLORS[s.color] || '#2c3e50') });
            sectionsEl.appendChild(el('div', { class: 'builder-row' }, [
                el('div', { class: 'builder-row-head' }, [
                    swatch,
                    el('input', { type: 'text', list: 'section-headings', maxlength: 60, value: s.heading || '', placeholder: 'Heading', 'aria-label': 'Heading', oninput: function (e) {
                        s.heading = e.target.value;
                        if (PRESETS[s.heading]) { s.color = PRESETS[s.heading]; renderSections(); }
                        changed(true);
                    } }),
                    el('select', { 'aria-label': 'Color', onchange: function (e) { s.color = e.target.value; swatch.style.background = COLORS[s.color]; changed(); } }, options(colorNames, s.color)),
                    el('span', { class: 'builder-row-tools' }, [
                        el('button', { type: 'button', class: 'btn btn-small btn-outline', title: 'Move up', text: '↑', onclick: function () { move(sections, i, -1); renderSections(); changed(); } }),
                        el('button', { type: 'button', class: 'btn btn-small btn-outline', title: 'Move down', text: '↓', onclick: function () { move(sections, i, 1); renderSections(); changed(); } }),
                        el('button', { type: 'button', class: 'btn btn-small btn-outline', title: 'Remove section', text: '✕', onclick: function () { if (confirm('Remove the ' + (s.heading || 'untitled') + ' section?')) { sections.splice(i, 1); renderSections(); changed(); } } }),
                    ]),
                ]),
                el('textarea', { rows: 6, 'aria-label': 'Section text', oninput: function (e) { s.text = e.target.value; changed(true); } }, [s.text || '']),
                badgeChecks(s, changed),
            ]));
        });
    }

    function renderAll() { renderBoxes(); renderArrows(); renderSections(); changed(); }

    var timer = null, seq = 0;
    function changed(typing) {
        clearTimeout(timer);
        timer = setTimeout(function () { renderArrowsNames(); preview(); }, typing ? 700 : 150);
    }
    function renderArrowsNames() {
        // Box numbers and text show in the arrow pickers, so refresh them.
        if (document.activeElement && arrowsEl.contains(document.activeElement)) return;
        renderArrows();
    }
    function sync() {
        document.getElementById('chart-json').value = JSON.stringify(chart);
        document.getElementById('sections-json').value = JSON.stringify(sections);
    }
    function preview() {
        sync();
        var mine = ++seq, data = new FormData(form);
        data.delete('_method');
        fetch(@json(route('protocols.manage.preview')), { method: 'POST', body: data, headers: { 'X-Requested-With': 'fetch', 'Accept': 'text/html' } })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                if (mine !== seq) return;
                var box = document.getElementById('preview');
                box.innerHTML = html;
                if (window.drawProtocolCharts) window.drawProtocolCharts(box);
            });
    }

    document.getElementById('add-box').addEventListener('click', function () {
        var max = chart.nodes.reduce(function (m, n) { return Math.max(m, parseInt(n.id.slice(1), 10) || 0); }, 0);
        var last = chart.nodes[chart.nodes.length - 1];
        var node = { id: 'n' + (max + 1), type: 'step', text: '', badges: [], link: null };
        chart.nodes.push(node);
        if (last) chart.edges.push({ from: last.id, to: node.id, label: null, style: 'one' });
        renderAll();
        var areas = boxesEl.querySelectorAll('textarea'); areas[areas.length - 1].focus();
    });
    document.getElementById('add-arrow').addEventListener('click', function () {
        chart.edges.push({ from: '', to: '', label: null, style: 'one' }); renderArrows();
    });
    document.getElementById('add-section').addEventListener('click', function () {
        sections.push({ heading: '', color: 'navy', badges: [], text: '' }); renderSections();
    });
    form.addEventListener('submit', function () {
        chart.edges = chart.edges.filter(function (a) { return a.from && a.to; });
        sync();
    });

    renderAll();
})();
</script>
@endpush
@endif
@include('protocols.partials.mermaid')
@endsection
