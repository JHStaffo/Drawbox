@once
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/mermaid@11.4.1/dist/mermaid.min.js"></script>
<script src="{{ asset('js/protocol-chart.js') }}?v={{ @filemtime(public_path('js/protocol-chart.js')) }}"></script>
@endpush
@endonce
