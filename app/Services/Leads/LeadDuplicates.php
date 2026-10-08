<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Duplicate detection on normalized email / phone (digits only).
 *
 * Deliberate exception to "every lead query is visibility-scoped": to block duplicates the check must
 * look at ALL (non-archived) leads, but it never reveals anything about a lead the user can't see:
 * for those, callers only learn "a lead with this email/phone already exists" (see visibleMatch()).
 */
class LeadDuplicates
{
    /** Shortest phone (in digits) that counts for matching; shorter numbers are too ambiguous. */
    public const MIN_PHONE_DIGITS = 6;

    /**
     * @return Collection<int, Lead>
     */
    public function find(?string $email, ?string $phone, ?int $ignoreId = null): Collection
    {
        $email = Lead::normalizeEmail($email);
        $phone = Lead::normalizePhone($phone);
        if ($phone !== null && strlen($phone) < self::MIN_PHONE_DIGITS) {
            $phone = null;
        }
        if ($email === null && $phone === null) {
            return collect();
        }

        return Lead::query()
            ->where(function ($q) use ($email, $phone) {
                if ($email !== null) {
                    $q->orWhere('email_normalized', $email);
                }
                if ($phone !== null) {
                    $q->orWhere('phone_normalized', $phone);
                }
            })
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))
            ->orderBy('id')
            ->limit(5)
            ->get();
    }

    /** First match the user may see (to show its name and a link), or null. */
    public function visibleMatch(Collection $matches, User $user): ?Lead
    {
        return $matches->first(fn (Lead $lead) => $lead->isVisibleTo($user));
    }

    /** Which field matched, for the message: "email", "phone" or "email and phone". */
    public function matchedOn(Collection $matches, ?string $email, ?string $phone): string
    {
        $email = Lead::normalizeEmail($email);
        $phone = Lead::normalizePhone($phone);
        $onEmail = $email !== null && $matches->contains('email_normalized', $email);
        $onPhone = $phone !== null && $matches->contains('phone_normalized', $phone);

        return $onEmail && $onPhone ? 'email and phone' : ($onEmail ? 'email' : 'phone');
    }
}
