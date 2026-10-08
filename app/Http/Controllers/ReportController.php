<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Csv;
use App\Support\Dates;
use App\Support\LeadStats;
use App\Support\Money;
use App\Http\Requests\ReportFilterRequest;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports (prototype ReportsView), admin and manager only (route middleware can:view-reports).
 * Leads created in the chosen date range (IST days), visibility-scoped; money per currency.
 */
class ReportController extends Controller
{
    public function index(ReportFilterRequest $request): View
    {
        [$filters, $stats] = $this->stats($request);

        return view('reports.index', [
            'filters' => $filters,
            'cards' => $stats->cards(),
            'wonValue' => $stats->wonValue(),
            'pipeline' => $stats->pipeline(),
            'funnel' => $stats->funnel(),
            'bySource' => $stats->bySource(),
            'byAssignee' => $stats->byAssignee(),
            'byTeam' => $stats->byTeam(),
            'scorecard' => $stats->scorecard(),
            'completion' => $stats->followUpCompletion(),
        ]);
    }

    public function export(ReportFilterRequest $request): StreamedResponse
    {
        [$filters, $stats] = $this->stats($request);
        Audit::log('report.exported', null, ['from' => $filters['from'], 'to' => $filters['to']]);

        $name = 'monks-crm-report-'.$filters['from'].'-to-'.$filters['to'].'.csv';

        return response()->streamDownload(function () use ($stats, $filters) {
            $out = fopen('php://output', 'w');
            Csv::write($out, ['Report', config('app.name'), 'From', $filters['from'], 'To', $filters['to']]);
            Csv::write($out, []);

            Csv::write($out, ['Funnel', 'Leads']);
            foreach ($stats->funnel() as $row) {
                Csv::write($out, [$row['label'], $row['count']]);
            }

            foreach (['By status' => $stats->pipeline()->map(fn ($r) => ['label' => $r['status']->name, 'count' => $r['count'], 'value' => Money::formatTotals($r['totals'])]),
                'By source' => $stats->bySource(), 'By assignee' => $stats->byAssignee(), 'By team' => $stats->byTeam()] as $title => $rows) {
                Csv::write($out, []);
                Csv::write($out, [$title, 'Leads', 'Deal value']);
                foreach ($rows as $row) {
                    Csv::write($out, [$row['label'], $row['count'], $row['value'] ?? '']);
                }
            }

            Csv::write($out, []);
            Csv::write($out, ['Scorecard', 'Team', 'Assigned', 'Contacted', 'Qualified', 'Won', 'Won value', 'Avg hours to first contact', 'Follow-up completion %']);
            foreach ($stats->scorecard() as $r) {
                Csv::write($out, [$r['user']->name, $r['user']->team?->name, $r['assigned'], $r['contacted'], $r['qualified'], $r['won'],
                    Money::formatTotals($r['won_value']), $r['avg_hours_to_contact'], $r['follow_up_rate']]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @return array{0: array{from: string, to: string}, 1: LeadStats} */
    private function stats(ReportFilterRequest $request): array
    {
        $filters = $request->range();

        return [$filters, new LeadStats($request->user(), Dates::fromInput($filters['from']), Dates::fromInput($filters['to'])->addDay())];
    }
}
