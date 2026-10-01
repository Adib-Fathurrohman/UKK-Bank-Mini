<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Models\Customer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
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
            DB::table('majors')->updateOrInsert(
                ['major_name' => $major],
                [
                    'major_name' => $major,
                    'updated_at' => now(),
                ]
            );
        }

        // =========================
        // CUSTOMERS
        // =========================

        $customers = [
            [
                'account_number' => '1000000001',
                'nis' => '20260001',
                'full_name' => 'Budi Santoso',
                'class' => 'X',
                'major_id' => 1,
                'group' => '1',
                'account_status' => AccountStatus::ACTIVE->value,
                'pin' => Hash::make('123456'),
                'balance' => 500000,
            ],
            [
                'account_number' => '1000000002',
                'nis' => '20260002',
                'full_name' => 'Andi Pratama',
                'class' => 'XI',
                'major_id' => 2,
                'group' => '2',
                'account_status' => AccountStatus::ACTIVE->value,
                'pin' => Hash::make('123456'),
                'balance' => 750000,
            ],
            [
                'account_number' => '1000000003',
                'nis' => '20260003',
                'full_name' => 'Citra Lestari',
                'class' => 'XII',
                'major_id' => 1,
                'group' => '3',
                'account_status' => AccountStatus::ACTIVE->value,
                'pin' => Hash::make('123456'),
                'balance' => 1000000,
            ],
        ];

        foreach ($customers as $customer) {
            Customer::updateOrCreate(
                [
                    'account_number' => $customer['account_number'],
                ],
                [
                    'nis' => $customer['nis'],
                    'full_name' => $customer['full_name'],
                    'class' => $customer['class'],
                    'major_id' => $customer['major_id'],
                    'group' => $customer['group'],
                    'account_status' => $customer['account_status'],
                    'pin' => $customer['pin'],
                    'balance' => $customer['balance'],
                ]
            );
        }
    }
}