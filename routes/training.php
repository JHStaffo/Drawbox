<?php

use App\Training\Http\Controllers\AssignController;
use App\Training\Http\Controllers\BuildController;
use App\Training\Http\Controllers\LearnController;
use App\Training\Http\Controllers\ProfileController;
use App\Training\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Training module pages, under /training and the Training menu.
Route::middleware('web')->prefix('training')->name('training')->group(function () {
    // Without signing in: a two-hour signed link to a lesson file (the PowerPoint viewer
    // and phone apps fetch it), and course designer pictures, whose names can't be guessed.
    Route::get('/f/{lesson}', [LearnController::class, 'publicFile'])->middleware('signed')->name('.file.public');
    Route::get('/images/{name}', [BuildController::class, 'image'])->where('name', '[A-Za-z0-9]{40}\.(jpg|png|gif|webp)')->name('.image');

    Route::middleware('auth')->group(function () {
        Route::get('/lessons/{lesson}/file', [LearnController::class, 'file'])->name('.file');

        Route::middleware('permission:training')->group(function () {
            Route::get('/', [LearnController::class, 'index'])->name('');
            Route::post('/a/{assignment}/lessons/{lesson}/time', [LearnController::class, 'time'])->middleware('throttle:30,1')->name('.time');
            Route::post('/a/{assignment}/lessons/{lesson}/done', [LearnController::class, 'done'])->name('.done');
            Route::post('/a/{assignment}/lessons/{lesson}/attempts', [LearnController::class, 'startAttempt'])->name('.attempts.start');
            Route::post('/attempts/{attempt}', [LearnController::class, 'submit'])->name('.attempts.submit');
        });
        // Their own, or anyone who assigns training or reads its reports.
        Route::get('/a/{assignment}', [LearnController::class, 'course'])->name('.course');
        Route::get('/a/{assignment}/lessons/{lesson}', [LearnController::class, 'lesson'])->name('.lesson');
        Route::get('/attempts/{attempt}', [LearnController::class, 'attempt'])->name('.attempt');

        Route::middleware('permission:training_build')->group(function () {
            Route::get('/courses', [BuildController::class, 'index'])->name('.courses');
            Route::post('/courses', [BuildController::class, 'store'])->name('.courses.store');
            Route::get('/courses/{course}/edit', [BuildController::class, 'edit'])->name('.courses.edit');
            Route::put('/courses/{course}', [BuildController::class, 'update'])->name('.courses.update');
            Route::delete('/courses/{course}', [BuildController::class, 'destroy'])->name('.courses.destroy');
            Route::post('/courses/{course}/lessons', [BuildController::class, 'storeLesson'])->name('.lessons.store');
            Route::get('/lessons/{lesson}/edit', [BuildController::class, 'editLesson'])->name('.lessons.edit');
            Route::put('/lessons/{lesson}', [BuildController::class, 'updateLesson'])->name('.lessons.update');
            Route::delete('/lessons/{lesson}', [BuildController::class, 'destroyLesson'])->name('.lessons.destroy');
            Route::post('/lessons/{lesson}/move', [BuildController::class, 'moveLesson'])->name('.lessons.move');
            Route::get('/lessons/{lesson}/preview', [BuildController::class, 'previewLesson'])->name('.lessons.preview');
            Route::post('/lessons/{lesson}/questions', [BuildController::class, 'storeQuestion'])->name('.questions.store');
            Route::put('/questions/{question}', [BuildController::class, 'updateQuestion'])->name('.questions.update');
            Route::delete('/questions/{question}', [BuildController::class, 'destroyQuestion'])->name('.questions.destroy');
            Route::post('/questions/{question}/move', [BuildController::class, 'moveQuestion'])->name('.questions.move');
            Route::post('/images', [BuildController::class, 'storeImage'])->middleware('throttle:60,1')->name('.images.store');
        });

        // Course progress and a Team Member's history: assigners and report readers (checked in the controller).
        Route::get('/courses/{course}/progress', [AssignController::class, 'progress'])->name('.progress');
        Route::get('/people/{user}', [AssignController::class, 'person'])->name('.person');

        Route::middleware('permission:training_assign')->group(function () {
            Route::get('/assign', [AssignController::class, 'create'])->name('.assign');
            Route::post('/assign', [AssignController::class, 'store'])->name('.assign.store');
            Route::post('/assignments/reassign', [AssignController::class, 'reassign'])->name('.assignments.reassign');
            Route::put('/assignments/{assignment}', [AssignController::class, 'update'])->name('.assignments.update');
            Route::post('/assignments/{assignment}/extra-attempt', [AssignController::class, 'extraAttempt'])->name('.assignments.extra');
            Route::delete('/assignments/{assignment}', [AssignController::class, 'destroy'])->name('.assignments.destroy');
            Route::get('/profiles', [ProfileController::class, 'index'])->name('.profiles');
            Route::get('/profiles/new', [ProfileController::class, 'create'])->name('.profiles.create');
            Route::post('/profiles', [ProfileController::class, 'store'])->name('.profiles.store');
            Route::get('/profiles/{profile}/edit', [ProfileController::class, 'edit'])->name('.profiles.edit');
            Route::put('/profiles/{profile}', [ProfileController::class, 'update'])->name('.profiles.update');
            Route::delete('/profiles/{profile}', [ProfileController::class, 'destroy'])->name('.profiles.destroy');
            Route::post('/profiles/{profile}/apply', [ProfileController::class, 'apply'])->name('.profiles.apply');
        });

        Route::middleware('permission:training_reports')->group(function () {
            Route::get('/reports', [ReportController::class, 'index'])->name('.reports');
            Route::get('/reports.csv', [ReportController::class, 'csv'])->name('.reports.csv');
            Route::get('/reports/attempts.csv', [ReportController::class, 'attemptsCsv'])->name('.reports.attempts');
        });
    });
});
