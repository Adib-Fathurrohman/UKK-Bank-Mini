<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\PdfReportService;
use App\Support\SchoolYear;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Periodic reports for administrators and supervisors.
 */
class ReportController extends Controller
{
    /**
     * Annual report per school year: savings balance per product and a savings
     * card summary per account. ?school_year=2026 means 2026/2027 (default: current).
     */
    public function annual(Request $request, PdfReportService $pdf): Response
    {
        $validated = $request->validate([
            'school_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        return $pdf->annual((int) ($validated['school_year'] ?? SchoolYear::startYear(now())));
    }
}
