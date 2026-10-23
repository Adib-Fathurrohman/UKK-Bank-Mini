<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCustomerRequest;
use App\Http\Requests\Admin\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            ->orderBy('full_name')
            ->paginate($this->perPage($request));

        return CustomerResource::collection($customers);
    }

    public function store(StoreCustomerRequest $request): CustomerResource
    {
        return new CustomerResource($this->customers->create($request->validated())->load('major'));
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
     * Close a graduated student's account: status INACTIVE plus soft delete.
     */
    public function deactivate(Customer $customer): CustomerResource
    {
        return new CustomerResource($this->customers->deactivate($customer)->load('major'));
    }
}
