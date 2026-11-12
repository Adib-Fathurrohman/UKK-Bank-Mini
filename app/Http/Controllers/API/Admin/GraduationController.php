<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GraduationRequest;
use App\Http\Resources\CustomerSummaryResource;
use App\Http\Resources\GraduationResource;
use App\Models\Graduation;
use App\Services\GraduationService;
use App\Services\PdfReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Graduation (kelulusan): preview, pay out and close a graduating class, then
 * print one withdrawal slip per student.
 */
class GraduationController extends Controller
{
    public function __construct(private GraduationService $graduations)
    {
    }

    /**
     * The open accounts of the class and what each will receive (the whole
     * balance, minimum balance included). Nothing is changed.
     */
    public function preview(GraduationRequest $request): JsonResponse
    {
        $preview = $this->graduations->preview($request->filters());

        return response()->json([
            'data' => $preview['customers']->map(fn ($customer) => [
                ...(new CustomerSummaryResource($customer))->resolve($request),
                // Paid out in full: the minimum balance no longer applies once the account closes.
                'payout' => $customer->balance,
            ])->values(),
            'summary' => [
                'customer_count' => $preview['customer_count'],
                'paid_count' => $preview['paid_count'],
                'total_amount' => $preview['total_amount'],
            ],
        ]);
    }

    /**
     * Pay out and close exactly the accounts confirmed in the preview.
     */
    public function store(GraduationRequest $request): JsonResponse
    {
        $graduation = $this->graduations->execute(
            $request->user(),
            $request->filters(),
            $request->validated('customer_ids'),
            $request->validated('note'),
        );

        return (new GraduationResource($this->detail($graduation)))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return GraduationResource::collection(
            Graduation::with(['major', 'executor'])->latest('id')->paginate($this->perPage($request))
        );
    }

    public function show(Graduation $graduation): GraduationResource
    {
        return new GraduationResource($this->detail($graduation));
    }

    /**
     * All slips in one PDF, one page per student who received money.
     */
    public function slips(Graduation $graduation, PdfReportService $pdf): Response
    {
        return $pdf->graduationSlips($graduation);
    }

    /**
     * One student's slip (e.g. to reprint a lost one).
     */
    public function slip(Graduation $graduation, int $customer, PdfReportService $pdf): Response
    {
        $transaction = $graduation->transactions()->where('customer_id', $customer)->first();
        abort_unless($transaction, 404);

        return $pdf->graduationSlips($graduation, $transaction);
    }

    private function detail(Graduation $graduation): Graduation
    {
        return $graduation->load([
            'major',
            'executor',
            'transactions',
            'customers' => fn ($query) => $query->with('major')->orderBy('major_id')->orderBy('group')->orderBy('full_name'),
        ]);
    }
}
