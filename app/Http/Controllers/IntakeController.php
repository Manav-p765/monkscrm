<?php

namespace App\Http\Controllers;

use App\Enums\SubmissionResult;
use App\Http\Requests\IntakeRequest;
use App\Models\Integration;
use App\Services\Leads\LeadIntake;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * POST /intake/{key}: public lead intake for website forms (and Zapier/Make). Outside the web group:
 * no session, cookies or CSRF; the integration's secret or allowed origins are the authentication
 * (IntakeRequest::authorize). Says nothing about whether a lead already existed.
 */
class IntakeController extends Controller
{
    public function store(IntakeRequest $request, LeadIntake $intake): BaseResponse
    {
        $integration = $request->integration();
        $mapped = $request->mapped();

        if ($mapped['spam']) {
            $intake->log($integration, SubmissionResult::Spam, null, $mapped['names'], [], $request->ip());
        } else {
            [$result, $lead] = $intake->capture($integration, $request->validated(), $mapped['extras'], $mapped['tracking']);
            $intake->log($integration, $result, $lead, $mapped['names'], [], $request->ip());
        }

        // A plain HTML form post from a visitor: send them to the thank-you page.
        if (! $request->isServerPost() && $request->prefers(['application/json', 'text/html']) === 'text/html') {
            return $integration->redirect_url
                ? redirect()->away($integration->redirect_url, 303)
                : response()->view('intake.thanks', status: 200);
        }

        return response()->json(['ok' => true], 201)->withHeaders($request->corsHeaders());
    }

    /** CORS preflight for fetch() from an allowed site. */
    public function preflight(Request $request, string $key): Response
    {
        $integration = Integration::query()->active()->where('public_key', $key)->first();
        $origin = $request->header('Origin');

        $response = response()->noContent();
        if ($integration?->acceptsOrigin($origin)) {
            $response->headers->add([
                'Access-Control-Allow-Origin' => Integration::normalizeOrigin($origin),
                'Access-Control-Allow-Methods' => 'POST',
                'Access-Control-Allow-Headers' => 'Content-Type, Accept',
                'Access-Control-Max-Age' => '86400',
                'Vary' => 'Origin',
            ]);
        }

        return $response;
    }
}
