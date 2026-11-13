<?php

namespace App\Support;

use App\Models\Protocol;

class ProtocolLinks
{
    /**
     * Turns a linked protocol's ID into its address in the book, or null
     * when it isn't in the book (not approved yet, or retired).
     *
     * @return callable(int): ?string
     */
    public static function forBook(): callable
    {
        $live = Protocol::inBook()->pluck('id')->flip();

        return fn (int $id) => $live->has($id) ? route('protocols.show', $id) : null;
    }
}
