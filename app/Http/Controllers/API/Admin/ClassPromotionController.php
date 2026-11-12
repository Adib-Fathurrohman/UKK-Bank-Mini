<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClassPromotionResource;
use App\Http\Resources\CustomerSummaryResource;
use App\Models\ClassPromotion;
use App\Services\ClassPromotionService;
use App\Support\SchoolYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Kenaikan kelas: preview and run the yearly move X -> XI, XI -> XII.
 */
class ClassPromotionController extends Controller
{
    public function __construct(private ClassPromotionService $promotions)
    {
    }

    /**
     * Every open X and XI account with its next class, and whether the run is possible
     * (class XII graduated, not yet run for this school year). Nothing is changed.
     */
    public function preview(Request $request): JsonResponse
    {
        $preview = $this->promotions->preview();
        $next = ['X' => 'XI', 'XI' => 'XII'];

        return response()->json([
            'data' => $preview['customers']->map(fn ($customer) => [
                ...(new CustomerSummaryResource($customer))->resolve($request),
                'next_class' => $next[$customer->class],
            ])->values(),
            'summary' => [
                'school_year' => SchoolYear::label($preview['school_year']),
                'x_count' => $preview['customers']->where('class', 'X')->count(),
                'xi_count' => $preview['customers']->where('class', 'XI')->count(),
                'open_xii_count' => $preview['open_xii_count'],
                'already_done' => $preview['already_done'],
                'can_run' => $preview['open_xii_count'] === 0 && ! $preview['already_done'] && $preview['customers']->isNotEmpty(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'customer_ids.*' => ['integer', 'distinct'],
            'retained_customer_ids' => ['sometimes', 'array'],
            'retained_customer_ids.*' => ['integer', 'distinct'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $promotion = $this->promotions->execute($request->user(), $data['customer_ids'], $data['retained_customer_ids'] ?? [], $data['note'] ?? null);

        return (new ClassPromotionResource($promotion->load(['executor', 'items.customer'])))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return ClassPromotionResource::collection(ClassPromotion::with('executor')->latest('id')->paginate($this->perPage($request)));
    }

    public function show(ClassPromotion $classPromotion): ClassPromotionResource
    {
        return new ClassPromotionResource($classPromotion->load(['executor', 'items.customer']));
    }
}
