<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Enables $this->authorize() for policy-backed controllers (M14).
     * Purely additive: existing controllers keep their explicit abort_unless
     * ownership checks and are unaffected.
     */
    use AuthorizesRequests;
}
