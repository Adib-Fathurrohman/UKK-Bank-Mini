<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerRequest;
use App\Http\Requests\Admin\UpdateCustomerRequest;
use App\Http\Resources\CustomerCardResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerImportService;
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
    public function __construct(private CustomerService $customers) {}

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
            ->when($request->input('activation'), fn ($query, $activation) => match ($activation) {
                'pending' => $query->whereNull('activated_at'),
                'activated' => $query->whereNotNull('activated_at'),
                default => $query,
            })
            // Imported accounts without an email cannot be activated until one is added.
            ->when($request->input('email'), fn ($query, $email) => match ($email) {
                'missing' => $query->whereNull('email'),
                'present' => $query->whereNotNull('email'),
                default => $query,
            })
            ->orderBy('full_name')
            ->paginate($this->perPage($request));

        return CustomerResource::collection($customers);
    }

    /**
     * Open the account. It starts "pending": the student activates the app by
     * signing in with the registered email and entering their NIS, then
     * creates their own PIN.
     */
    public function store(StoreCustomerRequest $request): CustomerResource
    {
        $customer = $this->customers->create($request->validated());

        return new CustomerResource($customer->load('major'));
    }

    /**
     * Bring existing savers over from the old bookkeeping: customers with their
     * balance and, optionally, their transaction history (a "Transaksi" sheet
     * in the workbook or a second file). All or nothing.
     */
    public function import(Request $request, CustomerImportService $importer): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt'],
            'transactions_file' => ['nullable', 'file', 'max:5120', 'mimes:xlsx,csv,txt'],
        ]);

        $result = $importer->import($request->file('file'), $request->file('transactions_file'), $request->user());
        $customers = $result['customers']->each->load('major');

        return response()->json([
            'message' => 'Data nasabah berhasil diimpor.',
            'data' => [
                'import_id' => $result['import']->id,
                'customers_created' => $result['import']->customer_count,
                'transactions_created' => $result['import']->transaction_count,
                // These stay pending until an email is added (PATCH /admin/customers/{id}).
                'customers_without_email' => $customers->whereNull('email')->count(),
                'customers' => CustomerResource::collection($customers)->resolve($request),
            ],
        ], 201);
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
     * Forgotten PIN: remove it (and any lock) so the customer creates a new
     * one in the app, after signing in with their Firebase account again.
     */
    public function resetPin(Customer $customer, PinService $pins): JsonResponse
    {
        $pins->reset($customer);

        return response()->json([
            'message' => 'PIN direset. Nasabah membuat PIN baru di aplikasi Bank Mini.',
            'data' => new CustomerResource($customer->load('major')),
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
