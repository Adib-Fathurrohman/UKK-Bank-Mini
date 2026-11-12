<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Graduation;
use App\Models\Transaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Printable PDF documents (interview: "bisa dibaca dan bisa diprint", not CSV).
 */
class PdfReportService
{
    public function __construct(private ReportService $reports)
    {
    }

    /**
     * The teller's closing report. Printing is locked until the shift has been
     * closed with physical cash matching the system (rubric 2.4).
     */
    public function dailyReport(DailyReport $report): Response
    {
        if (! in_array($report->status, [ReportStatus::SUBMITTED, ReportStatus::ON_HOLD, ReportStatus::APPROVED], true)) {
            throw new InvalidStatusTransitionException('Laporan harian baru dapat dicetak setelah shift ditutup dengan uang fisik yang sesuai saldo sistem.');
        }

        $report->load(['teller', 'supervisor', 'openingCounts', 'closingCounts']);

        return Pdf::loadView('pdf.daily-report', [
            'report' => $report,
            'transactions' => $report->transactions()->with('customer')->oldest('id')->get(),
        ])->download('laporan-harian-'.$report->report_date->toDateString().'-'.Str::slug($report->teller->full_name).'.pdf');
    }

    /**
     * Receipt (struk) for one transaction, on 80 mm receipt paper.
     */
    public function receipt(Transaction $transaction): Response
    {
        return Pdf::loadView('pdf.receipt', ['transaction' => $transaction->load(['customer.major', 'teller', 'authorizer'])])
            ->setPaper([0, 0, 226.77, 425.2])
            ->download("struk-{$transaction->id}.pdf");
    }

    /**
     * Graduation withdrawal slips: one A5 page per student who received money,
     * in class order, or only the given payout.
     */
    public function graduationSlips(Graduation $graduation, ?Transaction $only = null): Response
    {
        $graduation->load(['major', 'executor']);

        $payouts = $only
            ? collect([$only->load('customer.major')])
            : $graduation->transactions()
                ->with('customer.major')
                ->get()
                ->sortBy(fn (Transaction $payout) => [$payout->customer->major?->code, $payout->customer->group, $payout->customer->full_name])
                ->values();

        $name = 'kelulusan-'.Str::slug($graduation->label()).'-'.$graduation->created_at->format('Ymd')
            .($only ? '-'.$only->customer->account_number : '');

        return Pdf::loadView('pdf.graduation-slips', ['graduation' => $graduation, 'payouts' => $payouts])
            ->setPaper('a5', 'portrait')
            ->download($name.'.pdf');
    }

    public function statement(Customer $customer, ?CarbonInterface $from, ?CarbonInterface $to): Response
    {
        return Pdf::loadView('pdf.statement', $this->reports->statement($customer, $from, $to))
            ->download('mutasi-'.$customer->account_number.'.pdf');
    }

    public function annual(int $schoolYear): Response
    {
        return Pdf::loadView('pdf.annual', $this->reports->annual($schoolYear))
            ->setPaper('a4', 'landscape')
            ->download("laporan-tahunan-{$schoolYear}-".($schoolYear + 1).'.pdf');
    }
}
