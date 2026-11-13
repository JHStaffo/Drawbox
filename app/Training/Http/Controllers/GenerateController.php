<?php

namespace App\Training\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Training\Generate\CourseGenerator;
use App\Training\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Training > Courses > Write a course with AI: a few details in, a draft course out (the cron job does the writing). */
class GenerateController extends Controller
{
    public function index(CourseGenerator $generator)
    {
        return view('training.build.generate', [
            'generations' => Generation::with('requester', 'course')->latest('id')->limit(30)->get(),
            'ready' => $generator->ready(),
            'serverLimitMb' => BuildController::serverLimitMb(),
        ]);
    }

    public function store(Request $request, CourseGenerator $generator)
    {
        if (! $generator->ready()) {
            return back()->withInput()->with('error', 'Writing courses needs an Anthropic API key. An admin can add it in Admin > Settings.');
        }
        $data = $request->validate([
            'topic' => 'required|string|max:255',
            'audience' => 'nullable|string|max:500',
            'goals' => 'nullable|string|max:3000',
            'details' => 'nullable|string|max:60000',
            'sections' => 'nullable|string|max:1000',
            'lessons' => 'nullable|integer|min:1|max:12',
            'questions' => 'nullable|integer|min:0|max:40',
            'passing_score' => 'required|integer|min:1|max:100',
            'source' => 'nullable|file|max:30720|mimetypes:application/pdf,text/plain,text/markdown,text/csv',
        ], [
            'source.mimetypes' => 'The source file can be a PDF or a text file. Save Word documents and PowerPoints as PDF first.',
            'source.max' => 'The source file can be up to 30 MB.',
            'source.uploaded' => 'That file is bigger than the server\'s upload limit ('.BuildController::serverLimitMb().' MB).',
        ]);

        $generation = Generation::create([
            'requested_by' => $request->user()->id,
            'topic' => $data['topic'],
            'brief' => [
                'audience' => $data['audience'] ?? null,
                'goals' => $data['goals'] ?? null,
                'details' => $data['details'] ?? null,
                'sections' => collect(explode(',', $data['sections'] ?? ''))->map(fn ($x) => trim($x))->filter()->take(20)->values()->all(),
                'lessons' => $data['lessons'] ?? null,
                'questions' => (int) ($data['questions'] ?? 0),
                'passing_score' => (int) $data['passing_score'],
                'add_source_lesson' => $request->boolean('add_source_lesson'),
            ],
        ]);
        if ($file = $request->file('source')) {
            $pdf = $file->getMimeType() === 'application/pdf';
            $generation->update([
                'source_path' => $file->storeAs('training/generate', $generation->id.'-'.Str::random(12).($pdf ? '.pdf' : '.txt'), 'local'),
                'source_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'source_mime' => $pdf ? 'application/pdf' : 'text/plain',
            ]);
        }
        audit_note("Asked Claude to write a course: \"{$generation->topic}\"".($generation->source_name ? " from {$generation->source_name}" : '')
            .', '.($data['lessons'] ?? 'any number of').' lesson(s), '.$generation->brief['questions'].' quiz question(s).');

        return redirect()->route('training.generate.show', $generation)->with('status', 'Claude is writing it. This usually takes a few minutes; this page updates by itself.');
    }

    public function show(Generation $generation)
    {
        return view('training.build.generation', ['generation' => $generation->load('requester', 'course.lessons')]);
    }

    public function retry(Generation $generation)
    {
        if ($generation->status !== 'failed') {
            return back()->with('error', 'Only a stopped request can be tried again.');
        }
        $generation->update(['status' => 'queued', 'attempts' => 0, 'error' => null, 'finished_at' => null]);
        audit_note("Tried writing the AI course \"{$generation->topic}\" again.");

        return back()->with('status', 'Trying again. This page updates by itself.');
    }

    /** Remove a request (and its uploaded source). A course it already made stays. */
    public function destroy(Generation $generation)
    {
        CourseGenerator::deleteSource($generation);
        $generation->delete();
        audit_note("Removed the AI course request \"{$generation->topic}\"".($generation->course_id ? '. The draft course it made stays under Courses.' : '.'));

        return redirect()->route('training.generate')->with('status', 'Removed.');
    }
}
