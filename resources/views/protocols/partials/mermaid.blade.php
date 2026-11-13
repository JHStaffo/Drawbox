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
</script>
@endpush
@endonce
