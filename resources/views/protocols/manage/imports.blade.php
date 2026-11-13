@extends('layouts.app', ['title' => 'Import protocols'])

@section('band')
    <h1>Import protocols</h1>
    <p>Upload a protocol PDF or photos of printed protocols. Claude reads them a couple of pages at a time and each protocol it finds lands in Build protocols as a draft, laid out like the page. Check every draft against the original before sending it to the Medical Director.</p>
@endsection

@section('content')
<div class="stack">
    <div class="card" style="max-width:720px">
        <h2>Upload</h2>
        @unless ($ready)
            <p class="flash flash-error">Importing needs an Anthropic API key. An admin adds it in
                @if (auth()->user()->hasPermission('settings'))
                    <a href="{{ route('supervisor.settings') }}#anthropic">Admin &gt; Settings</a>.
                @else
                    Admin &gt; Settings.
                @endif</p>
        @endunless
        <form method="post" action="{{ route('protocols.imports.store') }}" enctype="multipart/form-data">@csrf
            <label for="import-files">One PDF, or up to 10 photos (JPG, PNG or WebP) in page order</label>
            <input id="import-files" type="file" name="files[]" multiple required accept="application/pdf,image/jpeg,image/png,image/webp" @disabled(! $ready)>
            @if ($serverLimit && $serverLimit < 20)
                <p class="flash flash-error">This server only accepts uploads up to {{ $serverLimit }} MB. An admin can raise it in cPanel &gt; MultiPHP INI Editor: set upload_max_filesize to 25M and post_max_size to 30M.</p>
            @endif
            <p class="hint">Up to 20 MB per file. A whole protocol book works too: it reads two pages a minute. Each two pages costs roughly 10 to 30 cents of API use.</p>
            <button class="btn mt" type="submit" @disabled(! $ready)>Upload and import</button>
        </form>
    </div>

    <div class="card">
        <h2>Uploads</h2>
        @if ($imports->isEmpty())
            <p class="empty">Nothing uploaded yet.</p>
        @else
            <div class="table-wrap"><table>
                <tr><th>File</th><th>Status</th><th>Drafts made</th><th></th></tr>
                @foreach ($imports as $import)
                    <tr>
                        <td>
                            {{ $import->name }}
                            <div class="hint">{{ $import->created_at->format('M j, Y g:i A') }} by {{ $import->uploader?->name ?? 'someone' }}</div>
                        </td>
                        <td>
                            <span @class(['pill', 'pill-teal' => $import->isPending(), 'pill-green' => $import->status === 'done', 'pill-red' => $import->status === 'failed'])>{{ $import->statusLabel() }}</span>
                            @if ($import->progress())<div class="hint">{{ $import->progress() }}</div>@endif
                            @if ($import->error)<div class="hint" style="color:#a3121a">{{ $import->error }}</div>@endif
                            @if ($import->input_tokens || $import->output_tokens)<div class="hint">About ${{ number_format($import->cost(), 2) }} of API use</div>@endif
                        </td>
                        <td>
                            @forelse ($import->protocols() as $p)
                                <div><a href="{{ route('protocols.manage.edit', $p) }}">{{ $p->latest?->fullTitle() }}</a></div>
                            @empty
                                <span class="hint">{{ $import->isPending() ? 'None yet' : 'None' }}</span>
                            @endforelse
                            @if ($import->notes)
                                <details class="mt"><summary class="hint">{{ count($import->notes) }} {{ \Illuminate\Support\Str::plural('note', count($import->notes)) }} to check</summary>
                                    <ul class="hint">@foreach ($import->notes as $note)<li>{{ $note }}</li>@endforeach</ul>
                                </details>
                            @endif
                        </td>
                        <td>
                            <div class="row-actions" style="margin:0">
                                @if ($import->status === 'failed')
                                    <form method="post" action="{{ route('protocols.imports.retry', $import) }}">@csrf<button class="btn btn-small" type="submit">Try again</button></form>
                                @endif
                                <form method="post" action="{{ route('protocols.imports.destroy', $import) }}" onsubmit="return confirm('{{ $import->isPending() ? 'Cancel' : 'Remove' }} this upload? Drafts it already made are kept.')">@csrf @method('delete')
                                    <button class="btn btn-small btn-outline" type="submit">{{ $import->isPending() ? 'Cancel' : 'Remove' }}</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </table></div>
            @if ($imports->contains(fn ($i) => $i->isPending()))
                <p class="hint mt">This page refreshes itself every 30 seconds while something is being read.</p>
                <script>setTimeout(function () { location.reload(); }, 30000);</script>
            @endif
        @endif
    </div>
</div>
@endsection
