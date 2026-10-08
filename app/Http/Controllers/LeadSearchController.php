<?php

namespace App\Http\Controllers;

use App\Http\Requests\LiveSearchRequest;
use App\Models\Lead;
use App\Support\LeadFilters;
use Illuminate\Http\Response;

/**
 * Header search as you type (prototype Navbar dropdown). Returns a server-rendered HTML fragment
 * (never JSON), scoped by visibility, at least 2 characters, max 8 results, rate-limited (throttle:live).
 */
class LeadSearchController extends Controller
{
    public function __invoke(LiveSearchRequest $request): Response
    {
        $term = $request->term();

        $leads = mb_strlen($term) < 2 ? collect() : LeadFilters::search(
            Lead::query()->visibleTo($request->user())->with('status:id,name,color'),
            $term
        )->orderByDesc('last_activity_at')->orderByDesc('id')->limit(8)->get();

        return response()->view('leads._search-results', ['leads' => $leads, 'term' => $term]);
    }
}
