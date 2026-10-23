<?php

namespace App\Http\Controllers\API\Admin;

use App\Enums\UserRoles;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

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

        DB::transaction(function () use ($request, $staff) {
            $staff->update($request->validated());

            if (! $staff->is_active) {
                $staff->tokens()->delete();
            }
        });

        return new StaffResource($staff);
    }

    /**
     * Remove a staff member's access. The account is deactivated rather than
     * deleted because their transactions must keep pointing at them.
     */
    public function destroy(User $staff): StaffResource
    {
        $this->ensureManageable($staff);

        DB::transaction(function () use ($staff) {
            $staff->update(['is_active' => false]);
            $staff->tokens()->delete();
        });

        return new StaffResource($staff);
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
