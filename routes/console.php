<?php

use App\Models\AuditLog;
use App\Models\PermissionProfile;
use App\Models\ProtocolImport;
use App\Models\ProtocolVersion;
use App\Models\User;
use App\Services\Credits;
use App\Services\Forms;
use App\Services\ProtocolImport\ProtocolImporter;
use App\Wellness\Rewards;
use Database\Seeders\ProtocolSampleSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('drawbox:expire', function (Credits $credits) {
    $count = $credits->expireOverdue();
    if ($count) {
        AuditLog::record('system.expire', "{$count} unused draw(s) passed their window and expired.");
    }
    $this->info("Expired {$count} draw(s).");
})->purpose('Mark draws past their window as expired');

Artisan::command('drawbox:notify', function (Credits $credits) {
    $count = $credits->sendNewDrawNotices();
    if ($count) {
        AuditLog::record('system.notify', "Emailed {$count} team member(s) about new draws.");
    }
    $this->info("Sent {$count} new-draw email(s).");
})->purpose('Email team members about draws they earned since the last run');

Artisan::command('drawbox:remind', function (Credits $credits) {
    $count = $credits->sendReminders();
    if ($count) {
        AuditLog::record('system.remind', "Emailed {$count} team member(s) whose draws expire within a day.");
    }
    $this->info("Sent {$count} reminder(s).");
})->purpose('Email team members whose draws expire within 24 hours');

Artisan::command('drawbox:make-admin {email} {name}', function (string $email, string $name) {
    // The Admin profile, recreated with every permission if it was renamed or deleted.
    $profile = PermissionProfile::firstOrCreate(['name' => 'Admin'], ['permissions' => array_keys(PermissionProfile::permissionLabels())]);
    $profile->update(['permissions' => array_keys(PermissionProfile::permissionLabels())]);
    $user = User::updateOrCreate(['email' => strtolower($email)], ['name' => $name, 'permission_profile_id' => $profile->id, 'active' => true]);
    AuditLog::record('admin.make-admin', "{$user->name} was made an admin from the server command line.", $user);
    $this->info("{$user->name} <{$user->email}> is an admin.");
})->purpose('Create or promote an admin account');

Artisan::command('drawbox:ssn-key', function () {
    // SSNs are encrypted with their own key. Making a new one when SSNs are
    // already saved would leave them unreadable, so an existing key is kept.
    $path = base_path('.env');
    $env = is_file($path) ? file_get_contents($path) : '';
    if (preg_match('/^SSN_KEY=\S+/m', $env)) {
        $this->error('SSN_KEY is already set in .env. It was left alone, because SSNs saved with it would be lost.');

        return 1;
    }
    $line = 'SSN_KEY='.\App\Services\Ssn::generateKey();
    $env = preg_match('/^SSN_KEY=\s*$/m', $env) ? preg_replace('/^SSN_KEY=\s*$/m', $line, $env, 1) : rtrim($env, "\n")."\n\n# Encrypts Social Security numbers. Back it up with the database; without it they can't be read.\n{$line}\n";
    file_put_contents($path, $env);
    AuditLog::record('system.ssn-key', 'Made the SSN encryption key from the server command line.');
    $this->info('Added SSN_KEY to .env. Run php artisan config:cache, and keep a copy of this key somewhere safe, apart from the database backups.');

    return 0;
})->purpose('Make the key that encrypts Social Security numbers');

Artisan::command('protocols:load-sample', function () {
    // Puts the arranged Cardiac Arrest sample into a draft, whatever state the protocol is in.
    // The Medical Director still has to approve it before Team Members see it.
    $existing = ProtocolVersion::where('title', 'Cardiac Arrest')->latest('id')->first()?->protocol;
    if (! $existing) {
        (new ProtocolSampleSeeder)->run();
        AuditLog::record('system.protocol-sample', 'Loaded the Cardiac Arrest sample as a new draft protocol from the server command line.');
        $this->info('Added the Cardiac Arrest protocol as a draft. Send it for approval from Protocols > Build protocols.');

        return;
    }

    $content = ProtocolSampleSeeder::content();
    $draft = $existing->openVersion();
    $from = $draft ? "version {$draft->version} ({$draft->statusLabel()})" : null;
    // Keep any links the builders set on boxes the sample also has (like Exit to ROSC).
    $links = collect(($draft ?? $existing->approved ?? $existing->latest)->boxes())->pluck('link', 'id');
    $content['chart']['nodes'] = array_map(fn ($n) => ['link' => $links[$n['id']] ?? $n['link']] + $n, $content['chart']['nodes']);

    if ($draft) {
        $draft->update($content + ['status' => 'draft', 'submitted_at' => null, 'submitted_by' => null]);
    } else {
        $base = $existing->approved ?? $existing->latest;
        $draft = $existing->versions()->create($content + [
            ...$base->only(['number', 'title', 'revised_label']),
            'version' => $existing->versions()->max('version') + 1,
            'status' => 'draft',
        ]);
    }
    $draft->update(['search_text' => $draft->fresh()->buildSearchText()]);

    $where = $from ? "replaced the flowchart and sections of {$from}" : "started version {$draft->version} as a draft";
    AuditLog::record('system.protocol-sample', "Loaded the arranged Cardiac Arrest sample from the server command line: {$where}.");
    $this->info(ucfirst($where).'. Open Protocols > Build protocols to check it and send it for approval.'
        .($existing->approved ? " Version {$existing->approved->version} stays in the book until then." : ''));
})->purpose('Load the arranged Cardiac Arrest sample protocol as a draft');

Artisan::command('pulse:slack-retry', function (Forms $forms) {
    $count = $forms->retryFailed();
    if ($count) {
        AuditLog::record('system.slack-retry', "Posted {$count} form submission(s) to Slack that didn't go through the first time.");
    }
    $this->info("Posted {$count} submission(s).");
})->purpose('Try again to post form submissions Slack did not take');

Artisan::command('wellness:close-weeks', function (Rewards $rewards) {
    $count = $rewards->closeWeeks();
    if ($count) {
        AuditLog::record('system.wellness-weeks', "{$count} Team Member(s) earned the weekly Wellness gift card and were emailed.");
    }
    $this->info("Created {$count} weekly reward(s).");
})->purpose('Give the weekly Wellness reward for weeks that have closed');

Artisan::command('wellness:sync-check', function (\App\Wellness\SyncCheck $check) {
    $count = $check->run();
    if ($count) {
        AuditLog::record('system.wellness-sync-check', "Emailed {$count} Team Member(s) whose wearable hasn't synced in 2 days.");
    }
    $this->info("Sent {$count} sync reminder(s).");
})->purpose('Email Wellness Team Members whose wearable stopped syncing');

Schedule::command('wellness:close-weeks')->dailyAt('06:00');
Schedule::command('wellness:sync-check')->dailyAt('10:00');
Schedule::command('drawbox:expire')->everyFifteenMinutes();
Schedule::command('drawbox:notify')->everyTwoHours();
Schedule::command('drawbox:remind')->hourly();
Schedule::command('pulse:slack-retry')->everyFiveMinutes();
Schedule::command('protocols:import')->everyMinute()->withoutOverlapping(30);
Schedule::command('training:generate')->everyMinute()->withoutOverlapping(30);
Schedule::command('training:update-emails')->dailyAt('08:00');

Artisan::command('training:update-emails', function (\App\Training\Versions $versions) {
    // Tells Team Members once a day about updates to courses they finished (when training emails are on in Settings).
    $counts = $versions->sendUpdateNotices();
    if ($counts['sent']) {
        AuditLog::record('system.training-update-emails', "Emailed {$counts['sent']} Team Member(s) about updates to courses they finished.");
    }
    if ($counts['skipped']) {
        AuditLog::record('system.training-update-emails', "Training emails are off in Settings, so {$counts['skipped']} course update notice(s) were not sent.");
    }
    $this->info("Sent {$counts['sent']} update email(s); {$counts['skipped']} skipped.");
})->purpose('Email Team Members about updates to training courses they finished');

Artisan::command('training:generate', function (\App\Training\Generate\CourseGenerator $generator) {
    // Writes courses asked for under Training > Courses > Write a course with AI.
    if (! $generator->ready()) {
        return;
    }
    $count = 0;
    $until = now()->addSeconds(50);
    while (now()->lessThan($until) && $generator->runNext()) {
        $count++;
    }
    $this->info($count ? "Worked on {$count} AI course request(s)." : 'No AI course requests waiting.');
})->purpose('Have Claude write the courses asked for under Training > Courses');

Artisan::command('protocols:import {--all : Keep going until every upload is read}', function (ProtocolImporter $importer) {
    // Reads the next couple of pages of uploaded protocol files (Protocols > Build protocols > Import).
    // Cron runs it every minute; --all is for running it by hand.
    if (! $importer->ready()) {
        if (ProtocolImport::whereIn('status', ['queued', 'working'])->exists()) {
            $this->warn('Uploads are waiting, but ANTHROPIC_API_KEY is not set in .env.');
        }

        return;
    }
    $steps = 0;
    $until = now()->addSeconds(50);
    while ($importer->runNext()) {
        $steps++;
        if (! $this->option('all') && now()->greaterThan($until)) {
            break;
        }
    }
    $this->info($steps ? "Read {$steps} step(s) of uploaded protocols." : 'No uploads waiting.');
})->purpose('Turn uploaded protocol PDFs and photos into draft protocols');
