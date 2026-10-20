<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeadExportRequest;
use App\Models\Lead;
use App\Support\Audit;
use App\Support\Csv;
use App\Support\Dates;
use App\Support\LeadFilters;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export the current filtered lead list as CSV or Excel (.xlsx) (admins and managers). Scoped by visibility,
 * streamed, max 20,000 rows, rate-limited (10 per hour per user), formula-injection protected (CSV: Csv::cell;
 * Excel: every value is a text cell, never a formula), audited with the filters used, format and row count
 * (never the contact data).
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

    public function __invoke(LeadExportRequest $request): StreamedResponse
    {
        Gate::authorize('export', Lead::class);
        $user = $request->user();
        $filters = $request->filters();
        $format = $request->fileFormat();
        $columns = array_values(array_intersect(array_keys(self::COLUMNS), (array) $request->validated('columns', self::DEFAULT_COLUMNS))) ?: self::DEFAULT_COLUMNS;

        $query = Lead::query()->visibleTo($user)->with(['status:id,name', 'source:id,name', 'assignee:id,name', 'tags:id,name']);
        LeadFilters::apply($query, $filters, $user);
        $count = min(self::MAX_ROWS, (clone $query)->toBase()->getCountForPagination());

        // The search text may be a lead's email or phone: record only that a search was used.
        $logged = array_filter($filters);
        if (isset($logged['q'])) {
            $logged['q'] = '(search)';
        }
        Audit::log('leads.exported', null, ['filters' => $logged, 'columns' => $columns, 'format' => $format, 'rows' => $count]);

        $name = 'monks-crm-leads-'.now(Dates::zone())->format('Y-m-d').'.'.$format;

        return $format === 'xlsx'
            ? response()->streamDownload(fn () => $this->writeXlsx($query, $columns), $name,
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store'])
            : response()->streamDownload(fn () => $this->writeCsv($query, $columns), $name,
                ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @param  list<string>  $columns */
    private function writeCsv(Builder $query, array $columns): void
    {
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₹ correctly
        Csv::write($out, array_map(fn ($c) => self::COLUMNS[$c], $columns));
        foreach ($this->leads($query) as $lead) {
            Csv::write($out, array_map(fn ($c) => $this->value($lead, $c), $columns));
        }
        fclose($out);
    }

    /** @param  list<string>  $columns */
    private function writeXlsx(Builder $query, array $columns): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile('php://output');
        $writer->getCurrentSheet()->setName('Leads');
        $writer->addRow(new Row(array_map(fn ($c) => new StringCell(self::COLUMNS[$c]), $columns)));
        foreach ($this->leads($query) as $lead) {
            $writer->addRow(new Row(array_map(fn ($c) => $this->cell($lead, $c), $columns)));
        }
        $writer->close();
    }

    /** @return iterable<Lead> */
    private function leads(Builder $query): iterable
    {
        $written = 0;
        foreach ($query->limit(self::MAX_ROWS)->lazy(500) as $lead) {
            yield $lead;
            if (++$written >= self::MAX_ROWS) {
                return;
            }
        }
    }

    /** Excel cell: deal value as a number, everything else as text (never a formula, whatever it starts with). */
    private function cell(Lead $lead, string $column): StringCell|NumericCell|EmptyCell
    {
        if ($column === 'deal_value' && $lead->deal_value !== null) {
            return new NumericCell((float) Money::toInput($lead->deal_value, $lead->currency));
        }
        $value = $this->value($lead, $column);

        return $value === '' ? new EmptyCell(null) : new StringCell($value);
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
