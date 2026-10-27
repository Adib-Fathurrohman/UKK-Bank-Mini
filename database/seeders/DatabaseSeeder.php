<?php

namespace Database\Seeders;

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\Major;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\TransactionService;
use Illuminate\Database\Seeder;

/**
 * Demo data for the UKK presentation. Safe to run more than once.
 */
class DatabaseSeeder extends Seeder
{
    public function run(TransactionService $transactions, CustomerService $customerService): void
    {
        // =========================
        // MAJORS
        // =========================

        // Codes are used in account numbers, e.g. RPL-2026-001.
        $majors = [
            'RPL' => 'Rekayasa Perangkat Lunak',
            'DKV' => 'Desain Komunikasi Visual',
            'MP' => 'Manajemen Perkantoran',
            'AK' => 'Akuntansi dan Keuangan Lembaga',
            'LPS' => 'Layanan Perbankan Syariah',
            'BD' => 'Bisnis Daring',
            'BR' => 'Bisnis Retail',
            'TKJ' => 'Teknik Komputer dan Jaringan',
        ];

        foreach ($majors as $code => $name) {
            Major::updateOrCreate(['major_name' => $name], ['code' => $code]);
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
                'nis' => '20260001',
                'full_name' => 'Budi Santoso',
                'class' => 'X',
                'major' => 'Rekayasa Perangkat Lunak',
                'group' => '1',
                'opening_balance' => 500000,
            ],
            [
                'nis' => '20260002',
                'full_name' => 'Andi Pratama',
                'class' => 'XI',
                'major' => 'Desain Komunikasi Visual',
                'group' => '2',
                'opening_balance' => 750000,
            ],
            [
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
            $customer = Customer::withTrashed()->firstWhere('nis', $data['nis'])
                ?? $customerService->create([
                    'nis' => $data['nis'],
                    'full_name' => $data['full_name'],
                    'class' => $data['class'],
                    'major_id' => Major::where('major_name', $data['major'])->value('id'),
                    'group' => $data['group'],
                    'pin' => '123456',
                ]);

            // Opening balances go through a real teller deposit so the balance,
            // the transaction history and the journal always agree.
            if ($customer->wasRecentlyCreated) {
                $transactions->deposit($customer, $data['opening_balance'], $teller);
            }
        }
    }
}
