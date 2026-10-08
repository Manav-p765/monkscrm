<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeadListRequest;
use App\Models\Lead;
use App\Support\Audit;
use App\Support\Csv;
use App\Support\Dates;
use App\Support\LeadFilters;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export the current filtered lead list as CSV (admins and managers). Scoped by visibility, streamed,
 * max 20,000 rows, rate-limited (10 per hour per user), formula-injection protected, audited with the
 * filters used and the row count (never the contact data).
 */
class LeadExportController extends Controller
{
    public const MAX_ROWS = 20000;

    public const COLUMNS = [
        'reference' => 'Reference', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'company' => 'Company',
        'job_title' => 'Job Title', 'email' => 'Email', 'phone' => 'Phone', 'secondary_email' => 'Secondary Email',
        'secondary_phone' => 'Secondary Phone', 'status' => 'Status', 'source' => 'Source', 'priority' => 'Priority',
        'assigned_to' => 'Assigned To', 'deal_value' => 'Deal Value', 'currency' => 'Currency', 'tags' => 'Tags',
        'website' => 'Website', 'address_line1' => 'Street Address', 'city' => 'City', 'state' => 'State',
        'postal_code' => 'Postal Code', 'country' => 'Country', 'next_follow_up_at' => 'Next Follow-Up',
        'last_contacted_at' => 'Last Contacted', 'created_at' => 'Created', 'description' => 'Description',
    ];

    public const DEFAULT_COLUMNS = ['reference', 'first_name', 'last_name', 'company', 'email', 'phone', 'status', 'source',
        'priority', 'assigned_to', 'deal_value', 'currency', 'city', 'country', 'next_follow_up_at', 'created_at'];

    public function __invoke(LeadListRequest $request): StreamedResponse
    {
        Gate::authorize('export', Lead::class);
        $user = $request->user();
        $filters = $request->filters();
        $columns = array_values(array_intersect(array_keys(self::COLUMNS), (array) $request->query('columns', self::DEFAULT_COLUMNS))) ?: self::DEFAULT_COLUMNS;

        $query = Lead::query()->visibleTo($user)->with(['status:id,name', 'source:id,name', 'assignee:id,name', 'tags:id,name']);
        LeadFilters::apply($query, $filters, $user);
        $count = min(self::MAX_ROWS, (clone $query)->toBase()->getCountForPagination());

        Audit::log('leads.exported', null, ['filters' => array_filter($filters), 'columns' => $columns, 'rows' => $count]);

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₹ correctly
            Csv::write($out, array_map(fn ($c) => self::COLUMNS[$c], $columns));
            $written = 0;
            foreach ($query->limit(self::MAX_ROWS)->lazy(500) as $lead) {
                Csv::write($out, array_map(fn ($c) => $this->value($lead, $c), $columns));
                if (++$written >= self::MAX_ROWS) {
                    break;
                }
            }
            fclose($out);
        }, 'monks-crm-leads-'.now(Dates::zone())->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function value(Lead $lead, string $column): string
    {
        return match ($column) {
            'status' => $lead->status?->name ?? '',
            'source' => $lead->source?->name ?? '',
            'priority' => $lead->priority?->label() ?? '',
            'assigned_to' => $lead->assignee?->name ?? '',
            'deal_value' => $lead->deal_value === null ? '' : Money::toInput($lead->deal_value, $lead->currency),
            'currency' => $lead->currency?->value ?? '',
            'tags' => $lead->tags->pluck('name')->implode(', '),
            'next_follow_up_at', 'last_contacted_at', 'created_at' => $lead->{$column} ? Dates::format($lead->{$column}, '') : '',
            default => (string) ($lead->{$column} ?? ''),
        };
    }
}
