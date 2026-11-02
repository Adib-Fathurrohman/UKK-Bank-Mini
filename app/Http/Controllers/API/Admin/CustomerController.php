<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerRequest;
use App\Http\Requests\Admin\UpdateCustomerRequest;
use App\Http\Resources\CustomerCardResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerActivationService;
use App\Services\CustomerService;
use App\Services\PdfReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer accounts. Administrators manage them; supervisors may only read
 * (index and show), which is enforced in routes/api.php.
 */
class CustomerController extends Controller
{
    public function __construct(
        private CustomerService $customers,
        private CustomerActivationService $activation,
    ) {
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
            ->when($request->input('activation'), fn ($query, $activation) => match ($activation) {
                'pending' => $query->whereNull('activated_at'),
                'activated' => $query->whereNotNull('activated_at'),
                default => $query,
            })
            ->orderBy('full_name')
            ->paginate($this->perPage($request));

        return CustomerResource::collection($customers);
    }

    /**
     * Open the account and email the activation link. The account exists even
     * if sending fails ("invitation.mail_sent": false); the administrator can
     * then resend it.
     */
    public function store(StoreCustomerRequest $request): CustomerResource
    {
        $customer = $this->customers->create($request->validated());
        $invitation = $this->activation->invite($customer, $request->user());

        return (new CustomerResource($customer->load('major')))->additional(['invitation' => $invitation]);
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->load('major'));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $data = $request->validated();

        // A PIN reset by the administrator also lifts a lock from wrong attempts.
        if (array_key_exists('pin', $data)) {
            $data += ['pin_failed_attempts' => 0, 'pin_locked_until' => null];
        }

        DB::transaction(function () use ($customer, $data) {
            $customer->update($data);

            // Links sent to the old address stop working; send a new invitation to the new one.
            if ($customer->wasChanged('email')) {
                $this->activation->revokeOutstanding($customer);
            }
        });

        return new CustomerResource($customer->load('major'));
    }

    /**
     * Send a new activation link to the registered email. Earlier links stop working.
     */
    public function invite(Request $request, Customer $customer): JsonResponse
    {
        $invitation = $this->activation->invite($customer, $request->user());

        return response()->json([
            'message' => $invitation['mail_sent']
                ? 'Tautan aktivasi baru telah dikirim.'
                : 'Tautan aktivasi dibuat, tetapi email gagal dikirim. Periksa konfigurasi email lalu kirim ulang.',
            'data' => $invitation,
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
