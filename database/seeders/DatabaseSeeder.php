<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\Major;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Database\Seeder;

/**
 * Demo data for the UKK presentation. Safe to run more than once.
 */
class DatabaseSeeder extends Seeder
{
    public function run(TransactionService $transactions): void
    {
        // =========================
        // MAJORS
        // =========================

        $majors = [
            'Rekayasa Perangkat Lunak',
            'Desain Komunikasi Visual',
            'Manajemen Perkantoran',
            'Akuntansi dan Keuangan Lembaga',
            'Layanan Perbankan Syariah',
            'Bisnis Daring',
            'Bisnis Retail',
            'Teknik Komputer dan Jaringan',
        ];

        foreach ($majors as $major) {
            Major::firstOrCreate(['major_name' => $major]);
        }

        // =========================
        // STAFF (password: "password")
        // =========================

        $staff = [
            ['username' => 'admin', 'full_name' => 'Administrator', 'role' => UserRoles::ADMIN],
            ['username' => 'supervisor', 'full_name' => 'Supervisor Bank Mini', 'role' => UserRoles::SUPERVISOR],
            ['username' => 'teller', 'full_name' => 'Teller Loket 1', 'role' => UserRoles::TELLER],
        ];

        foreach ($staff as $member) {
            User::firstOrCreate(
                ['username' => $member['username']],
                [...$member, 'password' => 'password', 'is_active' => true],
            );
        }

        // =========================
        // CUSTOMERS (PIN: "123456")
        // =========================

        $customers = [
            [
                'account_number' => '1000000001',
                'nis' => '20260001',
                'full_name' => 'Budi Santoso',
                'class' => 'X',
                'major' => 'Rekayasa Perangkat Lunak',
                'group' => '1',
                'opening_balance' => 500000,
            ],
            [
                'account_number' => '1000000002',
                'nis' => '20260002',
                'full_name' => 'Andi Pratama',
                'class' => 'XI',
                'major' => 'Desain Komunikasi Visual',
                'group' => '2',
                'opening_balance' => 750000,
            ],
            [
                'account_number' => '1000000003',
                'nis' => '20260003',
                'full_name' => 'Citra Lestari',
                'class' => 'XII',
                'major' => 'Rekayasa Perangkat Lunak',
                'group' => '3',
                'opening_balance' => 1000000,
            ],
        ];

        $teller = User::where('username', 'teller')->firstOrFail();

        foreach ($customers as $data) {
            $customer = Customer::withTrashed()->firstOrCreate(
                ['account_number' => $data['account_number']],
                [
                    'nis' => $data['nis'],
                    'full_name' => $data['full_name'],
                    'class' => $data['class'],
                    'major_id' => Major::where('major_name', $data['major'])->value('id'),
                    'group' => $data['group'],
                    'account_status' => AccountStatus::ACTIVE,
                    'pin' => '123456',
                    'balance' => 0,
                ],
            );

            // Opening balances go through a real teller deposit so the balance,
            // the transaction history and the journal always agree.
            if ($customer->wasRecentlyCreated) {
                $transactions->deposit($customer, $data['opening_balance'], $teller);
            }
        }
    }
}
