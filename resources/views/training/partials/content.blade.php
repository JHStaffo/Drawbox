{{-- What a lesson shows: its page text, then its file, video, link or quiz. $fileUrl is where its file loads from. --}}
@if ($lesson->body)
    <div class="rich">{!! $lesson->body !!}</div>
@endif
@if ($lesson->hasFile() && ! $lesson->file_path)
    <p class="empty">No file has been uploaded for this lesson yet.</p>
@elseif ($lesson->type === 'pdf')
    <iframe class="lesson-frame mt" src="{{ $fileUrl }}#view=FitH" title="{{ $lesson->title }}"></iframe>
    <p class="hint mt"><a href="{{ $fileUrl }}" target="_blank" rel="noopener">Open the PDF full screen</a> · <a href="{{ $fileUrl }}?download=1">Download</a></p>
@elseif ($lesson->type === 'slides')
    @if (in_array(strtolower(pathinfo($lesson->file_name, PATHINFO_EXTENSION)), ['pptx', 'ppsx', 'ppt', 'pps'], true))
        <iframe class="lesson-frame mt" src="https://view.officeapps.live.com/op/embed.aspx?src={{ urlencode($lesson->signedFileUrl()) }}" title="{{ $lesson->title }}" allowfullscreen></iframe>
    @endif
    <p class="hint mt">Slides not showing? <a href="{{ $fileUrl }}?download=1">Download {{ $lesson->file_name }}</a> ({{ $lesson->fileSizeLabel() }}).</p>
@elseif ($lesson->type === 'video')
    <video class="lesson-video mt" src="{{ $fileUrl }}" controls playsinline preload="metadata" data-media></video>
@elseif ($lesson->type === 'audio')
    <audio class="mt" style="width:100%" src="{{ $fileUrl }}" controls preload="metadata" data-media></audio>
@elseif ($lesson->type === 'file')
    <p class="mt"><a class="btn btn-teal" href="{{ $fileUrl }}?download=1">Download {{ $lesson->file_name }}</a> <span class="hint">{{ $lesson->fileSizeLabel() }}</span></p>
@elseif ($lesson->type === 'link')
    @if ($lesson->embedUrl())
        <div class="lesson-embed mt"><iframe src="{{ $lesson->embedUrl() }}" title="{{ $lesson->title }}" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen></iframe></div>
    @elseif ($lesson->url)
        <p class="mt"><a class="btn btn-teal" href="{{ $lesson->url }}" target="_blank" rel="noopener noreferrer">Open {{ parse_url($lesson->url, PHP_URL_HOST) }}</a></p>
    @endif
@endif
