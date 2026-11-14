<?php

namespace App\Services\Ems;

use RuntimeException;

/** The provider search couldn't be run or read. The message is short and shown as is. */
class SearchFailed extends RuntimeException {}
