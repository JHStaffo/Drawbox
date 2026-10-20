<?php

use App\Models\User;
use App\Services\Credits;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('drawbox:expire', function (Credits $credits) {
    $this->info('Expired '.$credits->expireOverdue().' draw(s).');
})->purpose('Mark draws past their window as expired');

Artisan::command('drawbox:remind', function (Credits $credits) {
    $this->info('Sent '.$credits->sendReminders().' reminder(s).');
})->purpose('Email employees whose draws expire soon');

Artisan::command('drawbox:make-admin {email} {name}', function (string $email, string $name) {
    $user = User::updateOrCreate(['email' => strtolower($email)], ['name' => $name, 'role' => 'admin', 'active' => true]);
    $this->info("{$user->name} <{$user->email}> is an admin.");
})->purpose('Create or promote an admin account');

Schedule::command('drawbox:expire')->everyFifteenMinutes();
Schedule::command('drawbox:remind')->dailyAt('09:00');
