<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Credits;
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
    $profile = \App\Models\PermissionProfile::firstOrCreate(['name' => 'Admin'], ['permissions' => array_keys(\App\Models\PermissionProfile::permissionLabels())]);
    $profile->update(['permissions' => array_keys(\App\Models\PermissionProfile::permissionLabels())]);
    $user = User::updateOrCreate(['email' => strtolower($email)], ['name' => $name, 'permission_profile_id' => $profile->id, 'active' => true]);
    AuditLog::record('admin.make-admin', "{$user->name} was made an admin from the server command line.", $user);
    $this->info("{$user->name} <{$user->email}> is an admin.");
})->purpose('Create or promote an admin account');

Artisan::command('protocols:load-sample', function () {
    // Puts the arranged Cardiac Arrest sample into a draft, whatever state the protocol is in.
    // The Medical Director still has to approve it before Team Members see it.
    $existing = \App\Models\ProtocolVersion::where('title', 'Cardiac Arrest')->latest('id')->first()?->protocol;
    if (! $existing) {
        (new \Database\Seeders\ProtocolSampleSeeder)->run();
        AuditLog::record('system.protocol-sample', 'Loaded the Cardiac Arrest sample as a new draft protocol from the server command line.');
        $this->info('Added the Cardiac Arrest protocol as a draft. Send it for approval from Protocols > Build protocols.');

        return;
    }

    $content = \Database\Seeders\ProtocolSampleSeeder::content();
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

Artisan::command('pulse:slack-retry', function (\App\Services\Forms $forms) {
    $count = $forms->retryFailed();
    if ($count) {
        AuditLog::record('system.slack-retry', "Posted {$count} form submission(s) to Slack that didn't go through the first time.");
    }
    $this->info("Posted {$count} submission(s).");
})->purpose('Try again to post form submissions Slack did not take');

Artisan::command('wellness:close-weeks', function (\App\Wellness\Rewards $rewards) {
    $count = $rewards->closeWeeks();
    if ($count) {
        AuditLog::record('system.wellness-weeks', "{$count} Team Member(s) earned the weekly Wellness gift card and were emailed.");
    }
    $this->info("Created {$count} weekly reward(s).");
})->purpose('Give the weekly Wellness reward for weeks that have closed');

Schedule::command('wellness:close-weeks')->dailyAt('06:00');
Schedule::command('drawbox:expire')->everyFifteenMinutes();
Schedule::command('drawbox:notify')->everyTwoHours();
Schedule::command('drawbox:remind')->hourly();
Schedule::command('pulse:slack-retry')->everyFiveMinutes();
