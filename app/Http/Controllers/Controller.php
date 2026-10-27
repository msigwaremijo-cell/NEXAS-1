<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Lets a controller say $this->authorize('post', $journal) instead of
    // reaching for the Gate facade, so every endpoint's policy check is
    // visible in the method it protects.
    use AuthorizesRequests;
}
