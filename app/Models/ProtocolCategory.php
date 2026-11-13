<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A chapter of the protocol book, like Cardiac Arrest or Respiratory. */
class ProtocolCategory extends Model
{
    protected $guarded = [];

    public static function ordered()
    {
        return static::orderBy('position')->orderBy('name')->get();
    }
}
