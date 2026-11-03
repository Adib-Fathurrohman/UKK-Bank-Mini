<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerRequest;
use App\Http\Requests\Admin\UpdateCustomerRequest;
use App\Http\Resources\CustomerCardResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use App\Services\PdfReportService;
use App\Services\PinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer accounts. Administrators manage them; supervisors may only read
 * (index and show), which is enforced in routes/api.php.
 */
class CustomerController extends Controller
{
    public function __construct(private CustomerService $customers)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $customers = Customer::query()
            ->with('major')
            ->when($request->input('trashed'), fn ($query, $trashed) => match ($trashed) {
                'only' => $query->onlyTrashed(),
                'with' => $query->withTrashed(),
                default => $query,
            })
            ->when($request->input('search'), fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('nis', 'like', "%{$search}%")
                ->orWhere('account_number', 'like', "%{$search}%")))
            ->when($request->input('major_id'), fn ($query, $majorId) => $query->where('major_id', $majorId))
            ->when($request->input('class'), fn ($query, $class) => $query->where('class', $class))
            ->when($request->input('group'), fn ($query, $group) => $query->where('group', $group))
            ->when($request->input('status'), fn ($query, $status) => $query->where('account_status', $status))
            // Who still has the temporary PIN (has never logged in, or was reset).
            ->when($request->filled('pin_must_change'), fn ($query) => $query->where('pin_must_change', $request->boolean('pin_must_change')))
            ->orderBy('full_name')
            ->paginate($this->perPage($request));

        return CustomerResource::collection($customers);
    }

    /**
     * Open the account with a generated temporary PIN. The PIN is in this
     * response only (give it to the student, e.g. printed with the card); the
     * student must replace it at the first login.
     */
    public function store(StoreCustomerRequest $request): CustomerResource
    {
        [$customer, $temporaryPin] = $this->customers->create($request->validated());

        return (new CustomerResource($customer->load('major')))->additional(['temporary_pin' => $temporaryPin]);
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->load('major'));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return new CustomerResource($customer->load('major'));
    }

    /**
     * Forgotten PIN: a new temporary PIN (in this response only), the lock
     * lifted, and every app session ended. The student replaces it at the
     * next login.
     */
    public function resetPin(Customer $customer, PinService $pins): JsonResponse
    {
        $temporaryPin = $pins->reset($customer);

        return response()->json([
            'message' => 'PIN direset. Berikan PIN sementara kepada nasabah; nasabah wajib menggantinya saat masuk.',
            'data' => new CustomerResource($customer->load('major')),
            'temporary_pin' => $temporaryPin,
        ]);
    }

    /**
     * A customer's statement (kartu simpanan) as PDF, e.g. for a parent or the school.
     */
    public function statement(Request $request, Customer $customer, PdfReportService $pdf): Response
    {
        $range = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return $pdf->statement(
            $customer,
            isset($range['from']) ? Carbon::parse($range['from'])->startOfDay() : null,
            isset($range['to']) ? Carbon::parse($range['to'])->endOfDay() : null,
        );
    }

    /**
     * Data for printing the customer's card with its QR code.
     */
    public function card(Customer $customer): CustomerCardResource
    {
        return new CustomerCardResource($customer->load('major'));
    }

    /**
     * Close a graduated student's account: status INACTIVE plus soft delete.
     */
    public function deactivate(Customer $customer): CustomerResource
    {
        return new CustomerResource($this->customers->deactivate($customer)->load('major'));
    }
}
