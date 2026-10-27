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
    $user = User::updateOrCreate(['email' => strtolower($email)], ['name' => $name, 'role' => 'admin', 'active' => true]);
    AuditLog::record('admin.make-admin', "{$user->name} was made an admin from the server command line.", $user);
    $this->info("{$user->name} <{$user->email}> is an admin.");
})->purpose('Create or promote an admin account');

Schedule::command('drawbox:expire')->everyFifteenMinutes();
Schedule::command('drawbox:notify')->everyTwoHours();
Schedule::command('drawbox:remind')->hourly();
