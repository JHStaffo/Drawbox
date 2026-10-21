<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Lockers;

class LockerController extends Controller
{
    public function index(Lockers $lockers)
    {
        return view('admin.lockers', ['offices' => $lockers->map()]);
    }
}
