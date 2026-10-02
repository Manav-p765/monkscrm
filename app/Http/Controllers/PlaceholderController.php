<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Temporary page for sections built in later steps. Access control lives on each route.
 */
class PlaceholderController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('placeholder', ['section' => $request->route()->defaults['section']]);
    }
}
