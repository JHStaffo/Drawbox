@once
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/mermaid@11.4.1/dist/mermaid.min.js"></script>
<script>
    // Draws every protocol flowchart on the page. Box text is escaped on the server.
    window.drawProtocolCharts = function (root) {
        if (!window.mermaid) return;
        if (!window.mermaidReady) {
            mermaid.initialize({
                startOnLoad: false,
                securityLevel: 'loose',
                theme: 'base',
                themeVariables: { fontFamily: 'Inter, system-ui, sans-serif', fontSize: '14px', lineColor: '#111111', primaryTextColor: '#111111', edgeLabelBackground: '#ffffff' },
                flowchart: { htmlLabels: true, useMaxWidth: true, curve: 'linear', nodeSpacing: 40, rankSpacing: 45, padding: 10 },
            });
            window.mermaidReady = true;
        }
        return mermaid.run({ nodes: (root || document).querySelectorAll('pre.mermaid:not([data-processed])') });
    };
    document.addEventListener('DOMContentLoaded', function () { window.drawProtocolCharts(); });

    // Full-screen chart: pinch or wheel to zoom, drag to move, tap a link box to open it.
    document.addEventListener('click', function (e) {
        var button = e.target.closest('[data-chart-full]');
        if (!button) return;
        var svg = button.closest('.protocol-page').querySelector('.protocol-chart svg');
        if (!svg) return;
        var title = button.closest('.protocol-page').querySelector('.protocol-title');
        var overlay = document.createElement('div');
        overlay.className = 'chart-full';
        overlay.innerHTML = '<div class="chart-full-bar"><strong></strong><span>'
            + '<button type="button" class="btn btn-small btn-outline" data-z="out" aria-label="Zoom out">−</button>'
            + '<button type="button" class="btn btn-small btn-outline" data-z="in" aria-label="Zoom in">+</button>'
            + '<button type="button" class="btn btn-small btn-outline" data-z="fit">Fit</button>'
            + '<button type="button" class="btn btn-small" data-z="close">Close</button></span></div>'
            + '<div class="chart-full-stage protocol-chart"><div class="chart-full-inner"></div></div>';
        overlay.querySelector('strong').textContent = title ? title.textContent : '';
        var copy = svg.cloneNode(true);
        var box = svg.viewBox && svg.viewBox.baseVal;
        var w = box && box.width ? box.width : svg.getBoundingClientRect().width;
        var h = box && box.height ? box.height : svg.getBoundingClientRect().height;
        copy.removeAttribute('style');
        copy.setAttribute('width', w); copy.setAttribute('height', h);
        copy.style.cssText = 'width:' + w + 'px !important;height:' + h + 'px;max-width:none !important';
        var inner = overlay.querySelector('.chart-full-inner');
        inner.appendChild(copy);
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        var stage = overlay.querySelector('.chart-full-stage');
        var scale = 1, x = 0, y = 0;
        function apply() { inner.style.transform = 'translate(' + x + 'px,' + y + 'px) scale(' + scale + ')'; }
        function fit() {
            var r = stage.getBoundingClientRect();
            scale = Math.min(r.width / w, r.height / h, 1.5) * 0.96;
            x = (r.width - w * scale) / 2; y = Math.max(8, (r.height - h * scale) / 2);
            apply();
        }
        function zoomAt(factor, cx, cy) {
            var next = Math.min(6, Math.max(0.15, scale * factor));
            x = cx - (cx - x) * (next / scale); y = cy - (cy - y) * (next / scale); scale = next; apply();
        }
        function close() { overlay.remove(); document.body.style.overflow = ''; document.removeEventListener('keydown', onKey); }
        function onKey(ev) { if (ev.key === 'Escape') close(); }
        document.addEventListener('keydown', onKey);
        overlay.querySelector('.chart-full-bar').addEventListener('click', function (ev) {
            var z = ev.target.getAttribute('data-z'), r = stage.getBoundingClientRect();
            if (z === 'close') close();
            if (z === 'fit') fit();
            if (z === 'in') zoomAt(1.4, r.width / 2, r.height / 2);
            if (z === 'out') zoomAt(1 / 1.4, r.width / 2, r.height / 2);
        });
        stage.addEventListener('wheel', function (ev) {
            ev.preventDefault();
            var r = stage.getBoundingClientRect();
            zoomAt(ev.deltaY < 0 ? 1.15 : 1 / 1.15, ev.clientX - r.left, ev.clientY - r.top);
        }, { passive: false });

        // Pointers: one drags, two pinch. A tap that barely moved still follows a link.
        var pointers = {}, last = null, moved = 0;
        function centre() {
            var p = Object.values(pointers), r = stage.getBoundingClientRect();
            return { x: (p[0].x + p[1].x) / 2 - r.left, y: (p[0].y + p[1].y) / 2 - r.top, d: Math.hypot(p[0].x - p[1].x, p[0].y - p[1].y) };
        }
        stage.addEventListener('pointerdown', function (ev) {
            pointers[ev.pointerId] = { x: ev.clientX, y: ev.clientY };
            if (Object.keys(pointers).length === 1) moved = 0;
            last = Object.keys(pointers).length === 2 ? centre() : null;
        });
        stage.addEventListener('pointermove', function (ev) {
            var p = pointers[ev.pointerId];
            if (!p) return;
            var n = Object.keys(pointers).length;
            if (n === 1) {
                x += ev.clientX - p.x; y += ev.clientY - p.y; moved += Math.abs(ev.clientX - p.x) + Math.abs(ev.clientY - p.y);
                if (moved > 6) stage.setPointerCapture(ev.pointerId);
                apply();
            }
            p.x = ev.clientX; p.y = ev.clientY;
            if (n === 2) {
                var c = centre();
                if (last && last.d) { x += c.x - last.x; y += c.y - last.y; zoomAt(c.d / last.d, c.x, c.y); }
                last = c; moved = 99;
            }
        });
        function up(ev) { delete pointers[ev.pointerId]; last = Object.keys(pointers).length === 2 ? centre() : null; }
        stage.addEventListener('pointerup', up);
        stage.addEventListener('pointercancel', up);
        stage.addEventListener('click', function (ev) { if (moved > 6) { ev.preventDefault(); ev.stopPropagation(); } }, true);
        fit();
    });
</script>
@endpush
@endonce
