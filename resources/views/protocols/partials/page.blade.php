{{-- One protocol as Team Members see it: header, flowchart, color sections, footer. --}}
@php($chart = \App\Support\ProtocolChart::class)
<article class="protocol-page" id="protocol-{{ $v->protocol_id ?? 'draft' }}">
    <header class="protocol-head">
        <img src="{{ asset('images/logo.png') }}" alt="Fast Track EMS">
        <div class="protocol-head-text">
            <div class="protocol-meta">
                @if ($v->number)<span>{{ $v->number }}</span>@endif
                @if ($v->category_name)<span>{{ $v->category_name }}</span>@endif
                <span>{{ $v->agesLabel() }}</span>
            </div>
            <h2 class="protocol-title">{{ $v->title }}</h2>
        </div>
    </header>

    @if ($v->boxes())
        <div class="protocol-chart-bar no-print"><button class="btn btn-small btn-outline" type="button" data-chart-full>Open chart full screen</button></div>
        <div class="protocol-chart">
            @if ($chart::isManual($v->chart))
                <div class="pchart" data-chart="{{ json_encode($chart::layoutData($v->chart, $linkFor)) }}"></div>
            @else
                <pre class="mermaid">{{ $chart::mermaid($v->chart, $linkFor) }}</pre>
            @endif
        </div>
        <span class="protocol-chart-hint">Swipe sideways to see the whole chart.</span>
    @endif

    @foreach ($v->sections ?? [] as $section)
        <section class="protocol-section" style="--sec: {{ \App\Models\ProtocolVersion::COLORS[$section['color']] ?? '#2c3e50' }}">
            <div class="protocol-section-label"><span>{{ $section['heading'] }}</span></div>
            <div class="protocol-section-body">
                {!! \App\Support\ProtocolText::block($section['text']) !!}
                @if ($section['badges'] ?? [])<div class="protocol-section-badges">{!! $chart::badgeHtml($section['badges']) !!}</div>@endif
            </div>
        </section>
    @endforeach

    <footer class="protocol-foot">
        <span>
            @if ($v->status === 'approved' || $v->status === 'superseded')
                Reviewed {{ $v->reviewed_at?->format('n/j/y') }} · Approved by {{ $v->signature }}
            @else
                {{ $v->statusLabel() }}: not approved yet
            @endif
        </span>
        <span>{{ $v->revised_label }}</span>
    </footer>
</article>
