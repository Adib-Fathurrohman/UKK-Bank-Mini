<?php

namespace App\Http\Controllers\API\Admin;

use App\Enums\UserRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\User;
use App\Services\StaffImportService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Internal user accounts. Administrators manage them (C, R, U, D);
 * supervisors may only read them (index and show), enforced in routes/api.php.
 */
class StaffController extends Controller
{
    /**
     * List staff. Only active accounts unless ?status=inactive or ?status=all.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $staff = User::query()
            ->when($request->input('status', 'active'), fn ($query, $status) => match ($status) {
                'inactive' => $query->where('is_active', false),
                'all' => $query,
                default => $query->where('is_active', true),
            })
            ->when($request->input('role'), fn ($query, $role) => $query->where('role', $role))
            ->when($request->input('search'), fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('username', 'like', "%{$search}%")
                ->orWhere('full_name', 'like', "%{$search}%")))
            ->orderBy('full_name')
            ->paginate($this->perPage($request));

        return StaffResource::collection($staff);
    }

    public function store(StoreStaffRequest $request): StaffResource
    {
        return new StaffResource(User::create($request->validated()));
    }

    public function show(User $staff): StaffResource
    {
        return new StaffResource($staff);
    }

    public function update(UpdateStaffRequest $request, User $staff): StaffResource
    {
        $this->ensureManageable($staff);

        $staff->update($request->validated());

        return new StaffResource($staff);
    }

    /**
     * Block access immediately: the account stays (its history must keep
     * pointing at it) but it cannot log in and its tokens are revoked.
     */
    public function block(User $staff): StaffResource
    {
        $this->ensureManageable($staff);

        DB::transaction(function () use ($staff) {
            $staff->update(['is_active' => false]);
            $staff->tokens()->delete();
        });

        return new StaffResource($staff);
    }

    public function unblock(User $staff): StaffResource
    {
        $this->ensureManageable($staff);

        $staff->update(['is_active' => true]);

        return new StaffResource($staff);
    }

    /**
     * Permanently delete an account that has never been used for anything.
     * Accounts with transactions, reports or approvals are protected by
     * ON DELETE RESTRICT and must be blocked instead.
     */
    public function destroy(User $staff): JsonResponse
    {
        $this->ensureManageable($staff);

        try {
            DB::transaction(function () use ($staff) {
                $staff->tokens()->delete();
                $staff->delete();
            });
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return response()->json([
                'message' => 'Staf ini memiliki riwayat transaksi, laporan, atau persetujuan sehingga tidak dapat dihapus. Blokir akunnya saja.',
            ], 409);
        }

        return response()->json(['message' => 'Akun staf dihapus.']);
    }

    /**
     * Create many tellers/supervisors from an .xlsx or .csv file.
     */
    public function import(Request $request, StaffImportService $importer): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimes:xlsx,csv,txt'],
        ]);

        $result = $importer->import($request->file('file'));

        return response()->json([
            'data' => [
                'created' => $result['created'],
                'users' => array_map(fn (array $row) => [
                    ...(new StaffResource($row['user']))->resolve($request),
                    'generated_password' => $row['generated_password'],
                ], $result['users']),
            ],
        ], 201);
    }

    /**
     * Administrator accounts are managed outside the API so an admin cannot
     * lock everyone (including themselves) out.
     */
    private function ensureManageable(User $staff): void
    {
        abort_if($staff->role === UserRoles::ADMIN, 403, 'Akun administrator tidak dapat diubah melalui API.');
    }
}
