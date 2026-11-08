<?php

use App\Enums\AccountCode;
use App\Enums\TransactionTypes;
use App\Enums\UserRoles;
use App\Models\AccountingJournal;
use App\Models\Customer;
use App\Models\CustomerImport;
use App\Models\Major;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

beforeEach(function () {
    $this->admin = actingAsStaff(UserRoles::ADMIN);
    $this->rpl = Major::factory()->create(['code' => 'RPL', 'major_name' => 'Rekayasa Perangkat Lunak']);
    Major::factory()->create(['code' => 'AK', 'major_name' => 'Akuntansi']);
});

function customerCsv(string $content, string $name = 'nasabah.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

test('admin imports customers with a balance and without an email', function () {
    $csv = "NIS;Nama;Email;Kelas;Jurusan;Rombel;Saldo\n"
        ."1001;Ani Lestari;Ani@Example.com;XI;RPL;1;Rp 150.000\n"
        ."1002;Budi Santoso;;X;akuntansi;2;\n";

    $response = $this->postJson('/api/admin/customers/import', ['file' => customerCsv($csv)]);

    $response->assertCreated()
        ->assertJsonPath('data.customers_created', 2)
        ->assertJsonPath('data.transactions_created', 1)
        ->assertJsonPath('data.customers_without_email', 1)
        ->assertJsonPath('data.customers.0.email', 'ani@example.com')
        ->assertJsonPath('data.customers.0.balance', 150000)
        ->assertJsonPath('data.customers.0.activation_status', 'pending')
        ->assertJsonPath('data.customers.0.pin_set', false)
        ->assertJsonPath('data.customers.1.email', null)
        ->assertJsonPath('data.customers.1.balance', 0)
        ->assertJsonPath('data.customers.1.major.code', 'AK');

    $ani = Customer::firstWhere('nis', '1001');
    $opening = $ani->transactions()->sole();

    expect($ani->account_number)->toStartWith('RPL-')
        ->and($opening->type)->toBe(TransactionTypes::DEPOSIT)
        ->and($opening->amount)->toBe(150000)
        ->and($opening->balance_after)->toBe(150000)
        ->and($opening->daily_report_id)->toBeNull()
        ->and($opening->customer_import_id)->toBe($response->json('data.import_id'))
        ->and($opening->user_id)->toBe($this->admin->id)
        ->and($opening->accountingJournals()->count())->toBe(2)
        ->and(Customer::firstWhere('nis', '1002')->transactions()->exists())->toBeFalse();
});

test('the email of an imported customer can be added later', function () {
    $this->postJson('/api/admin/customers/import', ['file' => customerCsv("nis,nama,kelas,jurusan,rombel\n1002,Budi,X,RPL,2\n")])
        ->assertCreated();

    $this->getJson('/api/customers?email=missing')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nis', '1002');

    $budi = Customer::firstWhere('nis', '1002');
    $this->patchJson("/api/admin/customers/{$budi->id}", ['email' => 'budi@example.com'])
        ->assertOk()
        ->assertJsonPath('data.email', 'budi@example.com');

    $this->getJson('/api/customers?email=missing')->assertJsonCount(0, 'data');
});

test('history from a Transaksi sheet is recorded with its dates and an opening balance', function () {
    $path = tempnam(sys_get_temp_dir(), 'nasabah').'.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Nasabah');
    $writer->addRow(Row::fromValues(['NIS', 'Nama', 'Kelas', 'Saldo', 'No Rekening']));
    $writer->addRow(Row::fromValues([2001.0, 'Citra', 'XII RPL 3', 120000.0, 'LAMA-001']));
    $writer->addNewSheetAndMakeItCurrent()->setName('Transaksi');
    $writer->addRow(Row::fromValues(['NIS', 'Tanggal', 'Jenis', 'Nominal', 'Keterangan']));
    $writer->addRow(Row::fromValues(['2001', '20/08/2025', 'tarik', '30.000', 'Beli buku']));
    $writer->addRow(Row::fromValues(['2001', new DateTimeImmutable('2025-07-14'), 'Setor', 50000.0, '']));
    $writer->close();

    $upload = new UploadedFile($path, 'nasabah.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

    $this->postJson('/api/admin/customers/import', ['file' => $upload])
        ->assertCreated()
        ->assertJsonPath('data.transactions_created', 3)
        ->assertJsonPath('data.customers.0.account_number', 'LAMA-001')
        ->assertJsonPath('data.customers.0.class', 'XII')
        ->assertJsonPath('data.customers.0.group', '3')
        ->assertJsonPath('data.customers.0.balance', 120000);

    $lines = Customer::firstWhere('nis', '2001')->transactions()->oldest('id')->get();

    // 120.000 = opening 100.000 + 50.000 - 30.000; oldest first.
    expect($lines->map(fn (Transaction $t) => [$t->type->value, $t->amount, $t->balance_after, $t->created_at->toDateString(), $t->note])->all())->toBe([
        ['deposit', 100000, 100000, '2025-07-14', 'Saldo awal dari pembukuan lama'],
        ['deposit', 50000, 150000, '2025-07-14', null],
        ['withdrawal', 30000, 120000, '2025-08-20', 'Beli buku'],
    ])->and(AccountingJournal::where('account_code', AccountCode::CASH)->first()->created_at->toDateString())->toBe('2025-07-14');
});

test('history can come in a second file and the balance is then computed from it', function () {
    $history = "nis,tanggal,jenis,nominal\n3001,2025-07-01,setor,20000\n3001,2025-07-02,tarik,5000\n";

    $this->postJson('/api/admin/customers/import', [
        'file' => customerCsv("nis,nama,kelas,jurusan,rombel\n3001,Dewi,X,RPL,1\n"),
        'transactions_file' => customerCsv($history, 'transaksi.csv'),
    ])->assertCreated()
        ->assertJsonPath('data.transactions_created', 2)
        ->assertJsonPath('data.customers.0.balance', 15000);

    $this->getJson('/api/audit/transactions?customer_id='.Customer::firstWhere('nis', '3001')->id)
        ->assertOk()
        ->assertJsonPath('data.0.imported', true)
        ->assertJsonPath('data.0.daily_report_id', null);
});

test('imported customers see their history in the app', function () {
    $this->postJson('/api/admin/customers/import', [
        'file' => customerCsv("nis,nama,kelas,jurusan,rombel,saldo\n3002,Eka,X,RPL,1,10000\n"),
    ])->assertCreated();

    Sanctum::actingAs(Customer::firstWhere('nis', '3002'), guard: 'sanctum');

    $this->getJson('/api/customer/transactions')
        ->assertOk()
        ->assertJsonPath('data.0.amount', 10000)
        ->assertJsonPath('data.0.description', 'Saldo awal dari pembukuan lama')
        ->assertJsonPath('data.0.imported', true);
});

test('a balance lower than the history explains is refused', function () {
    $response = $this->postJson('/api/admin/customers/import', [
        'file' => customerCsv("nis,nama,kelas,jurusan,rombel,saldo\n4001,Fajar,X,RPL,1,5000\n"),
        'transactions_file' => customerCsv("nis,tanggal,jenis,nominal\n4001,2025-07-01,setor,20000\n", 'transaksi.csv'),
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('rows.0.balance');
    expect(Customer::count())->toBe(0)->and(CustomerImport::count())->toBe(0);
});

test('a history that goes below zero is refused', function () {
    $this->postJson('/api/admin/customers/import', [
        'file' => customerCsv("nis,nama,kelas,jurusan,rombel\n4002,Gita,X,RPL,1\n"),
        'transactions_file' => customerCsv("nis,tanggal,jenis,nominal\n4002,2025-07-01,tarik,1000\n", 'transaksi.csv'),
    ])->assertUnprocessable()->assertJsonValidationErrors('transactions.0.amount');

    expect(Customer::count())->toBe(0);
});

test('the import is all or nothing and names the spreadsheet row', function () {
    Customer::factory()->create(['nis' => '5002']);
    $csv = "nis,nama,email,kelas,jurusan,rombel,saldo\n"
        ."5001,Hana,hana@example.com,X,RPL,1,1000\n"
        ."5002,Indra,,XI,RPL,1,\n"
        ."5003,Joko,hana@example.com,XIII,TKJ,5,abc\n";

    $response = $this->postJson('/api/admin/customers/import', ['file' => customerCsv($csv)]);

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'rows.1.nis', 'rows.2.email', 'rows.2.class', 'rows.2.major', 'rows.2.group', 'rows.2.balance',
    ]);
    expect($response->json('errors')['rows.1.nis'][0])->toContain('baris 3')
        ->and(Customer::where('nis', '5001')->exists())->toBeFalse();
});

test('history rows must belong to an imported customer and have a valid date, type and amount', function () {
    $history = "nis,tanggal,jenis,nominal\n9999,2025-07-01,setor,1000\n6001,31/02/2025,transfer,0\n6001,2999-01-01,setor,1000\n";

    $this->postJson('/api/admin/customers/import', [
        'file' => customerCsv("nis,nama,kelas,jurusan,rombel\n6001,Kiki,X,RPL,1\n"),
        'transactions_file' => customerCsv($history, 'transaksi.csv'),
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'transactions.0.nis', 'transactions.1.date', 'transactions.1.type', 'transactions.1.amount', 'transactions.2.date',
    ]);
});

test('imported history never touches a teller shift', function () {
    $teller = actingAsStaff(UserRoles::TELLER);
    $shift = openShiftFor($teller);
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/admin/customers/import', [
        'file' => customerCsv("nis,nama,kelas,jurusan,rombel,saldo\n7001,Lina,X,RPL,1,50000\n"),
    ])->assertCreated();

    expect($shift->transactions()->count())->toBe(0);
});

test('the import requires a spreadsheet with at least one customer', function () {
    $this->postJson('/api/admin/customers/import', ['file' => UploadedFile::fake()->create('foto.png', 10, 'image/png')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    $this->postJson('/api/admin/customers/import', ['file' => customerCsv("nis,nama\n")])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rows');
});
