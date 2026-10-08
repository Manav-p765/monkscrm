<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\Leads\LeadDuplicates;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Duplicate check while typing in the lead form. HTML fragment; rate-limited (throttle:live).
 * A match the user can see shows its name and a link; any other match shows only
 * "A lead with this email/phone already exists", with no details.
 */
class LeadDuplicateCheckController extends Controller
{
    public function __invoke(Request $request, LeadDuplicates $duplicates): Response
    {
        $data = $request->validate([
            'email' => ['nullable', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'ignore' => ['nullable', 'string', 'max:20'],
        ]);

        // Only compare against the lead being edited if the user can actually see it.
        $ignoreId = ! empty($data['ignore'])
            ? Lead::query()->visibleTo($request->user())->where('reference', $data['ignore'])->value('id')
            : null;

        $email = mb_strlen((string) ($data['email'] ?? '')) >= 2 ? $data['email'] : null;
        $phone = $data['phone'] ?? null;
        $matches = $duplicates->find($email, $phone, $ignoreId);

        return response()->view('leads._duplicate-warning', [
            'found' => $matches->isNotEmpty(),
            'lead' => $duplicates->visibleMatch($matches, $request->user()),
            'on' => $matches->isNotEmpty() ? $duplicates->matchedOn($matches, $email, $phone) : null,
            'canOverride' => $request->user()->can('overrideDuplicate', Lead::class),
        ]);
    }
}
