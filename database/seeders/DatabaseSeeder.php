<?php

namespace Database\Seeders;

use App\Enums\UserRoles;
use App\Models\Customer;
use App\Models\DailyReport;
use App\Models\Major;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\DailyReportService;
use App\Services\TransactionService;
use App\Support\Cash;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo data for the UKK presentation. Safe to run more than once.
 */
class DatabaseSeeder extends Seeder
{
    public function run(TransactionService $transactions, CustomerService $customerService, DailyReportService $reports): void
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
            ['username' => 'teller', 'full_name' => 'Teller Loket 1', 'role' => UserRoles::TELLER, 'class' => 'XI AK 1', 'phone' => '081200000001'],
        ];

        foreach ($staff as $member) {
            User::firstOrCreate(
                ['username' => $member['username']],
                [...$member, 'password' => 'password', 'is_active' => true],
            );
        }

        // =========================
        // CUSTOMERS
        // =========================
        // Seeded accounts are "pending" and have no PIN: the student activates
        // the app by signing in with the registered email and entering the NIS,
        // then creates a PIN. To try it, an administrator first changes the
        // email to a real address (allowed until activation).

        $customers = [
            [
                'nis' => '20260001',
                'email' => 'budi.santoso@example.test',
                'full_name' => 'Budi Santoso',
                'class' => 'X',
                'major' => 'Rekayasa Perangkat Lunak',
                'group' => '1',
                'opening_balance' => 500000,
            ],
            [
                'nis' => '20260002',
                'email' => 'andi.pratama@example.test',
                'full_name' => 'Andi Pratama',
                'class' => 'XI',
                'major' => 'Desain Komunikasi Visual',
                'group' => '2',
                'opening_balance' => 750000,
            ],
            [
                'nis' => '20260003',
                'email' => 'citra.lestari@example.test',
                'full_name' => 'Citra Lestari',
                'class' => 'XII',
                'major' => 'Rekayasa Perangkat Lunak',
                'group' => '3',
                'opening_balance' => 1000000,
            ],
        ];

        $newAccounts = [];

        foreach ($customers as $data) {
            $customer = Customer::withTrashed()->firstWhere('nis', $data['nis'])
                ?? $customerService->create([
                    'nis' => $data['nis'],
                    'full_name' => $data['full_name'],
                    'email' => $data['email'],
                    'class' => $data['class'],
                    'major_id' => Major::where('major_name', $data['major'])->value('id'),
                    'group' => $data['group'],
                ]);

            if ($customer->wasRecentlyCreated) {
                $newAccounts[] = [$customer, $data['opening_balance']];
            }
        }

        if ($newAccounts !== []) {
            $this->depositOpeningBalances($newAccounts, $transactions, $reports);
        }
    }

    /**
     * Opening balances go through real teller deposits so balances, history and
     * journal always agree. They are recorded in a complete shift dated
     * yesterday (opened, closed with matching cash, approved), so the demo
     * teller can open a fresh shift today.
     *
     * @param  array<int, array{0: Customer, 1: int}>  $accounts
     */
    private function depositOpeningBalances(array $accounts, TransactionService $transactions, DailyReportService $reports): void
    {
        $teller = User::where('username', 'teller')->firstOrFail();
        $supervisor = User::where('username', 'supervisor')->firstOrFail();
        $day = now()->subDay();

        if (DailyReport::where('teller_id', $teller->id)->whereDate('report_date', $day)->exists()) {
            return;
        }

        $previousNow = Carbon::getTestNow();

        try {
            Carbon::setTestNow($day->copy()->setTime(7, 0));
            $shift = $reports->open($teller, Cash::breakdown(100000));

            foreach ($accounts as [$customer, $amount]) {
                $transactions->deposit($customer, $amount, $teller);
            }

            Carbon::setTestNow($day->copy()->setTime(14, 0));
            $report = $reports->close($teller, Cash::breakdown($reports->summarize($shift)['system_balance']));
            $reports->approve($report, $supervisor, 'Saldo awal nasabah demo.');
        } finally {
            Carbon::setTestNow($previousNow);
        }
    }
}
