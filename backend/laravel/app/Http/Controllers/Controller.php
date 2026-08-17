<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

/**
 * Laravel 12's base controller is deliberately empty. AuthorizesRequests is
 * pulled in here because every EMPOWER controller gates its actions through a
 * policy, and a missing $this->authorize() would otherwise leave an endpoint
 * silently unprotected.
 */
abstract class Controller
{
    use AuthorizesRequests;
    use ValidatesRequests;
}
