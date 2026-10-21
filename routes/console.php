<?php

use App\Models\User;
use App\Services\Credits;
use App\Services\Lockers;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('drawbox:expire', function (Credits $credits, Lockers $lockers) {
    $this->info('Expired '.$credits->expireOverdue().' draw(s).');
    $this->info('Forfeited '.$lockers->forfeitOverdue().' locker(s).');
})->purpose('Mark draws and locker pickups past their window as expired or forfeited');

Artisan::command('drawbox:remind', function (Credits $credits) {
    $this->info('Sent '.$credits->sendReminders().' reminder(s).');
})->purpose('Email employees whose draws expire within 24 hours');

Artisan::command('drawbox:make-admin {email} {name}', function (string $email, string $name) {
    $user = User::updateOrCreate(['email' => strtolower($email)], ['name' => $name, 'role' => 'admin', 'active' => true]);
    $this->info("{$user->name} <{$user->email}> is an admin.");
})->purpose('Create or promote an admin account');

Schedule::command('drawbox:expire')->everyFifteenMinutes();
Schedule::command('drawbox:remind')->hourly();
