/*
 * Protocol flowcharts.
 *
 * Charts with the automatic layout are drawn by Mermaid. Charts arranged by
 * hand are drawn here: each box sits where it was dragged, and arrows are
 * square-cornered lines between the sides of the boxes, like a printed
 * protocol. Box text arrives already escaped from the server.
 */
(function () {
    var SNAP = 10, STUB = 18, PAD = 24;
    var DIRS = { top: [0, -1], bottom: [0, 1], left: [-1, 0], right: [1, 0] };

    function svgEl(tag, attrs) {
        var e = document.createElementNS('http://www.w3.org/2000/svg', tag);
        Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
        return e;
    }

    function anchor(b, side) {
        return {
            top: { x: b.x, y: b.y - b.h / 2 },
            bottom: { x: b.x, y: b.y + b.h / 2 },
            left: { x: b.x - b.w / 2, y: b.y },
            right: { x: b.x + b.w / 2, y: b.y },
        }[side];
    }

    /** The sides an arrow uses when it's left on "auto": the ones facing each other. */
    function autoSides(a, b) {
        var dx = b.x - a.x, dy = b.y - a.y;
        var gapY = Math.abs(dy) - (a.h + b.h) / 2, gapX = Math.abs(dx) - (a.w + b.w) / 2;
        if (gapY >= 0 && (gapX < 0 || gapY >= gapX * 0.6)) return dy > 0 ? ['bottom', 'top'] : ['top', 'bottom'];
        return dx > 0 ? ['right', 'left'] : ['left', 'right'];
    }

    /** Square-cornered route from one box side to another. */
    function route(a, fromSide, b, toSide) {
        var s0 = anchor(a, fromSide), e0 = anchor(b, toSide);
        var d1 = DIRS[fromSide], d2 = DIRS[toSide];
        var s1 = { x: s0.x + d1[0] * STUB, y: s0.y + d1[1] * STUB };
        var e1 = { x: e0.x + d2[0] * STUB, y: e0.y + d2[1] * STUB };
        var v1 = d1[0] === 0, v2 = d2[0] === 0, mid = [];
        if (v1 && v2) {
            var y = fromSide === toSide ? (fromSide === 'bottom' ? Math.max(s1.y, e1.y) : Math.min(s1.y, e1.y)) : (s1.y + e1.y) / 2;
            if (s0.x === e0.x && fromSide !== toSide) y = s1.y;
            mid = [{ x: s1.x, y: y }, { x: e1.x, y: y }];
        } else if (!v1 && !v2) {
            var x = fromSide === toSide ? (fromSide === 'right' ? Math.max(s1.x, e1.x) : Math.min(s1.x, e1.x)) : (s1.x + e1.x) / 2;
            if (s0.y === e0.y && fromSide !== toSide) x = s1.x;
            mid = [{ x: x, y: s1.y }, { x: x, y: e1.y }];
        } else if (v1) {
            mid = [{ x: s1.x, y: e1.y }];
        } else {
            mid = [{ x: e1.x, y: s1.y }];
        }
        var pts = [s0, s1].concat(mid, [e1, e0]), clean = [];
        pts.forEach(function (p) {
            var last = clean[clean.length - 1];
            if (!last || Math.abs(last.x - p.x) > 0.5 || Math.abs(last.y - p.y) > 0.5) clean.push(p);
        });
        // Drop middle points on a straight run.
        return clean.filter(function (p, i) {
            if (i === 0 || i === clean.length - 1) return true;
            var a1 = clean[i - 1], c = clean[i + 1];
            return !((Math.abs(a1.x - p.x) < 0.5 && Math.abs(p.x - c.x) < 0.5) || (Math.abs(a1.y - p.y) < 0.5 && Math.abs(p.y - c.y) < 0.5));
        });
    }

    /**
     * Draws a hand-arranged chart into el. options.editable lets boxes be
     * dragged; options.onMove(id, x, y) is called when one is dropped.
     */
    function render(el, data, options) {
        options = options || {};
        el.innerHTML = '';
        var canvas = document.createElement('div');
        canvas.className = 'pchart-canvas' + (options.editable ? ' pchart-editable' : '');
        var lines = svgEl('svg', { class: 'pchart-lines' });
        var defs = svgEl('defs');
        var marker = svgEl('marker', { id: 'pchart-arrow-' + Math.random().toString(36).slice(2), viewBox: '0 0 10 10', refX: '9', refY: '5', markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse' });
        marker.appendChild(svgEl('path', { d: 'M0,0 L10,5 L0,10 z', fill: '#111' }));
        defs.appendChild(marker);
        lines.appendChild(defs);
        canvas.appendChild(lines);
        var sizer = document.createElement('div');
        sizer.className = 'pchart-sizer';
        sizer.appendChild(canvas);
        el.appendChild(sizer);

        var boxes = {};
        data.nodes.forEach(function (n) {
            var d = document.createElement('div');
            d.className = 'pbox t_' + n.type;
            d.dataset.id = n.id;
            if (n.type === 'decision') {
                d.appendChild(svgEl('svg', { class: 'pdiamond', viewBox: '0 0 100 100', preserveAspectRatio: 'none' }))
                    .appendChild(svgEl('polygon', { points: '50,1 99,50 50,99 1,50' }));
            }
            var label = document.createElement('div');
            label.className = 'pbox-label';
            label.innerHTML = n.html;
            d.appendChild(label);
            canvas.appendChild(d);
            boxes[n.id] = { el: d, x: n.x, y: n.y, w: 0, h: 0 };
        });
        // Measure every box (on a wide canvas so nothing wraps early), then shift the drawing so it starts near the top left.
        canvas.style.width = '8000px';
        var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        Object.keys(boxes).forEach(function (id) {
            var b = boxes[id];
            b.w = b.el.offsetWidth; b.h = b.el.offsetHeight;
            minX = Math.min(minX, b.x - b.w / 2); minY = Math.min(minY, b.y - b.h / 2);
            maxX = Math.max(maxX, b.x + b.w / 2); maxY = Math.max(maxY, b.y + b.h / 2);
        });
        if (!isFinite(minX)) { minX = minY = 0; maxX = maxY = 100; }
        var ox = minX - PAD, oy = minY - PAD;
        var W = Math.ceil(maxX - minX + PAD * 2), H = Math.ceil(maxY - minY + PAD * 2);
        Object.keys(boxes).forEach(function (id) { boxes[id].x -= ox; boxes[id].y -= oy; });

        var labels = [];
        function draw() {
            Array.prototype.slice.call(lines.querySelectorAll('polyline')).forEach(function (p) { p.remove(); });
            labels.forEach(function (l) { l.remove(); });
            labels = [];
            var w = W, h = H;
            Object.keys(boxes).forEach(function (id) {
                var b = boxes[id];
                b.el.style.left = (b.x - b.w / 2) + 'px';
                b.el.style.top = (b.y - b.h / 2) + 'px';
                w = Math.max(w, b.x + b.w / 2 + PAD); h = Math.max(h, b.y + b.h / 2 + PAD);
            });
            canvas.style.width = w + 'px'; canvas.style.height = h + 'px';
            lines.setAttribute('width', w); lines.setAttribute('height', h);
            data.edges.forEach(function (e) {
                var a = boxes[e.from], b = boxes[e.to];
                if (!a || !b) return;
                var sides = autoSides(a, b);
                var pts = route(a, e.from_side && e.from_side !== 'auto' ? e.from_side : sides[0], b, e.to_side && e.to_side !== 'auto' ? e.to_side : sides[1]);
                var line = svgEl('polyline', { points: pts.map(function (p) { return p.x + ',' + p.y; }).join(' '), fill: 'none', stroke: '#111', 'stroke-width': '1.6' });
                if (e.style === 'line') line.setAttribute('stroke-dasharray', '4 4');
                else {
                    line.setAttribute('marker-end', 'url(#' + marker.id + ')');
                    if (e.style === 'both') line.setAttribute('marker-start', 'url(#' + marker.id + ')');
                }
                lines.appendChild(line);
                if (e.label) {
                    // On the longest straight piece of the line.
                    var best = 0, at = null;
                    for (var i = 1; i < pts.length; i++) {
                        var len = Math.abs(pts[i].x - pts[i - 1].x) + Math.abs(pts[i].y - pts[i - 1].y);
                        if (len > best) { best = len; at = { x: (pts[i].x + pts[i - 1].x) / 2, y: (pts[i].y + pts[i - 1].y) / 2 }; }
                    }
                    var l = document.createElement('span');
                    l.className = 'pchart-label';
                    l.textContent = e.label;
                    canvas.appendChild(l);
                    l.style.left = (at.x - l.offsetWidth / 2) + 'px';
                    l.style.top = (at.y - l.offsetHeight / 2) + 'px';
                    labels.push(l);
                }
            });
            canvas.dataset.w = w; canvas.dataset.h = h;
            fit();
        }

        var scale = 1;
        function fit() {
            var w = +canvas.dataset.w, h = +canvas.dataset.h, avail = el.clientWidth || w;
            // Shrink to fit, but on phones keep it readable and let it scroll sideways.
            scale = Math.min(1, (avail < 600 ? Math.max(avail, 820) : avail) / w);
            canvas.style.transform = scale < 1 ? 'scale(' + scale + ')' : '';
            sizer.style.width = (w * scale) + 'px';
            sizer.style.height = (h * scale) + 'px';
        }
        draw();

        if (options.editable) {
            var drag = null;
            canvas.addEventListener('click', function (ev) { if (ev.target.closest('a')) ev.preventDefault(); }, true);
            canvas.addEventListener('pointerdown', function (ev) {
                var box = ev.target.closest('.pbox');
                if (!box) return;
                ev.preventDefault();
                var b = boxes[box.dataset.id];
                drag = { b: b, id: box.dataset.id, sx: ev.clientX, sy: ev.clientY, x: b.x, y: b.y };
                box.classList.add('pbox-dragging');
                canvas.setPointerCapture(ev.pointerId);
            });
            canvas.addEventListener('pointermove', function (ev) {
                if (!drag) return;
                drag.b.x = Math.round((drag.x + (ev.clientX - drag.sx) / scale) / SNAP) * SNAP;
                drag.b.y = Math.round((drag.y + (ev.clientY - drag.sy) / scale) / SNAP) * SNAP;
                drag.b.x = Math.max(drag.b.w / 2, drag.b.x); drag.b.y = Math.max(drag.b.h / 2, drag.b.y);
                draw();
            });
            function drop() {
                if (!drag) return;
                drag.b.el.classList.remove('pbox-dragging');
                if (options.onMove && (drag.b.x !== drag.x || drag.b.y !== drag.y)) options.onMove(drag.id, Math.round((drag.b.x + ox) / SNAP) * SNAP, Math.round((drag.b.y + oy) / SNAP) * SNAP);
                drag = null;
            }
            canvas.addEventListener('pointerup', drop);
            canvas.addEventListener('pointercancel', drop);
        }
        el.protocolChart = { canvas: canvas, refit: fit };
        return el.protocolChart;
    }

    /** Box centers from a chart Mermaid drew, to start arranging by hand from. */
    function mermaidPositions(svg) {
        var out = {};
        Array.prototype.slice.call(svg.querySelectorAll('g.node')).forEach(function (g) {
            var m = /b_([a-z0-9]+)/.exec(g.id || ''), t = /translate\(\s*([-\d.]+)[ ,]+([-\d.]+)/.exec(g.getAttribute('transform') || '');
            if (m && t) out[m[1]] = { x: Math.round(+t[1] / SNAP) * SNAP + 40, y: Math.round(+t[2] / SNAP) * SNAP + 40 };
        });
        return out;
    }

    window.ProtocolChart = { render: render, mermaidPositions: mermaidPositions };

    // Draws every chart on the page (or inside root).
    window.drawProtocolCharts = function (root) {
        root = root || document;
        Array.prototype.slice.call(root.querySelectorAll('.pchart')).forEach(function (el) {
            if (el.dataset.drawn) return;
            el.dataset.drawn = '1';
            render(el, JSON.parse(el.dataset.chart));
        });
        if (!window.mermaid || !root.querySelector('pre.mermaid:not([data-processed])')) return Promise.resolve();
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
        return mermaid.run({ nodes: root.querySelectorAll('pre.mermaid:not([data-processed])') });
    };
    document.addEventListener('DOMContentLoaded', function () { window.drawProtocolCharts(); });
    window.addEventListener('resize', function () {
        Array.prototype.slice.call(document.querySelectorAll('.pchart')).forEach(function (el) { if (el.protocolChart) el.protocolChart.refit(); });
    });

    // Full-screen chart: pinch or wheel to zoom, drag to move, tap a link box to open it.
    document.addEventListener('click', function (e) {
        var button = e.target.closest('[data-chart-full]');
        if (!button) return;
        var page = button.closest('.protocol-page');
        var source = page.querySelector('.protocol-chart .pchart-canvas') || page.querySelector('.protocol-chart svg');
        if (!source) return;
        var title = page.querySelector('.protocol-title');
        var overlay = document.createElement('div');
        overlay.className = 'chart-full';
        overlay.innerHTML = '<div class="chart-full-bar"><strong></strong><span>'
            + '<button type="button" class="btn btn-small btn-outline" data-z="out" aria-label="Zoom out">−</button>'
            + '<button type="button" class="btn btn-small btn-outline" data-z="in" aria-label="Zoom in">+</button>'
            + '<button type="button" class="btn btn-small btn-outline" data-z="fit">Fit</button>'
            + '<button type="button" class="btn btn-small" data-z="close">Close</button></span></div>'
            + '<div class="chart-full-stage protocol-chart"><div class="chart-full-inner"></div></div>';
        overlay.querySelector('strong').textContent = title ? title.textContent : '';
        var copy = source.cloneNode(true), w, h;
        if (source.classList.contains('pchart-canvas')) {
            w = +source.dataset.w; h = +source.dataset.h;
            copy.style.transform = '';
        } else {
            var box = source.viewBox && source.viewBox.baseVal;
            w = box && box.width ? box.width : source.getBoundingClientRect().width;
            h = box && box.height ? box.height : source.getBoundingClientRect().height;
            copy.removeAttribute('style');
            copy.setAttribute('width', w); copy.setAttribute('height', h);
            copy.style.cssText = 'width:' + w + 'px !important;height:' + h + 'px;max-width:none !important';
        }
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
            var p = Object.keys(pointers).map(function (k) { return pointers[k]; }), r = stage.getBoundingClientRect();
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
})();
