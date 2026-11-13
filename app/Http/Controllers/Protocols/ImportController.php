<?php

namespace App\Http\Controllers\Protocols;

use App\Http\Controllers\Controller;
use App\Models\ProtocolImport;
use App\Services\ProtocolImport\ProtocolImporter;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Upload a protocol PDF or photos; the cron job turns them into drafts.
 */
class ImportController extends Controller
{
    /** The Claude API takes images up to 5 MB; bigger photos are shrunk first. */
    private const IMAGE_LIMIT = 3_500_000;

    public function index(ProtocolImporter $importer)
    {
        return view('protocols.manage.imports', [
            'imports' => ProtocolImport::with('uploader')->latest('id')->limit(50)->get(),
            'ready' => $importer->ready(),
        ]);
    }

    public function store(Request $request, ProtocolImporter $importer)
    {
        if (! $importer->ready()) {
            return back()->with('error', 'Importing needs an Anthropic API key on the server first.');
        }
        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => 'file|mimetypes:application/pdf,image/jpeg,image/png,image/webp|max:20480',
        ], [
            'files.required' => 'Pick a PDF or one or more photos.',
            'files.max' => 'Upload up to 10 photos at a time.',
            'files.*.mimetypes' => 'Upload a PDF, JPG, PNG or WebP. iPhone HEIC photos need to be saved as JPG first.',
            'files.*.max' => 'Each file can be up to 20 MB.',
            'files.*.uploaded' => 'That file is bigger than the server\'s upload limit ('.self::serverLimitMb().' MB).',
        ]);
        /** @var list<UploadedFile> $uploads */
        $uploads = $request->file('files');
        $pdfs = array_filter($uploads, fn ($f) => $f->getMimeType() === 'application/pdf');
        if ($pdfs && count($uploads) > 1) {
            throw ValidationException::withMessages(['files' => 'Upload one PDF at a time, or photos on their own.']);
        }

        $kind = $pdfs ? 'pdf' : 'images';
        $import = ProtocolImport::create([
            'uploaded_by' => $request->user()->id,
            'name' => mb_substr($uploads[0]->getClientOriginalName().(count($uploads) > 1 ? ' and '.(count($uploads) - 1).' more' : ''), 0, 255),
            'kind' => $kind,
            'files' => [],
        ]);
        $files = [];
        try {
            foreach ($uploads as $i => $upload) {
                $mime = $upload->getMimeType();
                $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
                $path = $upload->storeAs('protocol-imports/'.$import->id, ($i + 1).'.'.$ext, 'local');
                if ($kind === 'images' && $upload->getSize() > self::IMAGE_LIMIT) {
                    [$path, $mime] = $this->shrink($path, $mime);
                }
                $files[] = ['path' => $path, 'mime' => $mime, 'name' => $upload->getClientOriginalName()];
            }
        } catch (ValidationException $e) {
            ProtocolImporter::deleteFiles($import);
            $import->delete();
            throw $e;
        }
        $import->files = $files;
        $import->page_count = $kind === 'pdf'
            ? ProtocolImporter::pdfPageCount(Storage::disk('local')->path($files[0]['path']))
            : count($files);
        $import->save();
        audit_note("Uploaded {$import->name} to import as draft protocols (".($kind === 'pdf' ? ($import->page_count ? "{$import->page_count}-page PDF" : 'PDF') : count($files).' photo(s)').').');

        return redirect()->route('protocols.imports')->with('status', "Uploaded {$import->name}. It's read a couple of pages every minute, and each protocol found lands in Build protocols as a draft.");
    }

    public function retry(ProtocolImport $import)
    {
        if ($import->status !== 'failed') {
            return back()->with('error', 'Only a stopped import can be tried again.');
        }
        $import->update(['status' => 'working', 'attempts' => 0, 'error' => null, 'finished_at' => null]);
        audit_note("Restarted the import of {$import->name} from page {$import->next_page}.");

        return back()->with('status', "Trying {$import->name} again from where it stopped.");
    }

    public function destroy(ProtocolImport $import)
    {
        $wasPending = $import->isPending();
        ProtocolImporter::deleteFiles($import);
        $import->delete();
        audit_note(($wasPending ? 'Cancelled' : 'Removed').' the import of '.$import->name.'. Drafts it already made are kept.');

        return back()->with('status', ($wasPending ? 'Cancelled' : 'Removed')." {$import->name}. Any drafts it made are still in Build protocols.");
    }

    /** Scales a large photo down to 2000 pixels on its long side, as a JPG. */
    private function shrink(string $path, string $mime): array
    {
        $full = Storage::disk('local')->path($path);
        $image = function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) file_get_contents($full)) : false;
        if (! $image) {
            throw ValidationException::withMessages(['files' => 'A photo is over 3.5 MB and the server can\'t shrink it. Take it at a lower resolution and try again.']);
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, 2000 / max($w, $h));
        $small = imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        $newPath = preg_replace('/\.\w+$/', '.jpg', $path);
        imagejpeg($small, Storage::disk('local')->path($newPath), 85);
        if ($newPath !== $path) {
            Storage::disk('local')->delete($path);
        }

        return [$newPath, 'image/jpeg'];
    }

    /** The smaller of PHP's upload and post limits, in MB. */
    public static function serverLimitMb(): float
    {
        $bytes = function (string $v): float {
            $n = (float) $v;

            return match (strtolower(substr(trim($v), -1))) {
                'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
            };
        };
        $limits = array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]);

        return $limits ? round(min($limits) / 1024 ** 2, 1) : 0;
    }
}
