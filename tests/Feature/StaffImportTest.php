<?php

use App\Enums\UserRoles;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

beforeEach(function () {
    actingAsStaff(UserRoles::ADMIN);
});

function csvUpload(string $content, string $name = 'tellers.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

test('admin imports tellers from a CSV using the piket list column names', function () {
    $csv = "Username;Nama;Kelas;No WA;Password\n"
        ."rina;Rina Wulandari;XI AK 1;081200000001;rahasia123\n"
        ."dodi;Dodi Saputra;XI AK 2;081200000002;\n";

    $response = $this->postJson('/api/admin/staff/import', ['file' => csvUpload($csv)]);

    $response->assertCreated()
        ->assertJsonPath('data.created', 2)
        ->assertJsonPath('data.users.0.username', 'rina')
        ->assertJsonPath('data.users.0.role', 'teller')
        ->assertJsonPath('data.users.0.class', 'XI AK 1')
        ->assertJsonPath('data.users.0.generated_password', null);

    $generated = $response->json('data.users.1.generated_password');
    expect($generated)->toBeString()->toHaveLength(10)
        ->and(Hash::check('rahasia123', User::firstWhere('username', 'rina')->password))->toBeTrue()
        ->and(Hash::check($generated, User::firstWhere('username', 'dodi')->password))->toBeTrue()
        ->and(User::firstWhere('username', 'dodi')->phone)->toBe('081200000002');
});

test('admin imports staff from an Excel file', function () {
    $path = tempnam(sys_get_temp_dir(), 'staff').'.xlsx';
    $writer = new XlsxWriter();
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['username', 'full_name', 'role', 'password']));
    $writer->addRow(Row::fromValues(['spv2', 'Supervisor Dua', 'supervisor', 'rahasia123']));
    $writer->close();

    $upload = new UploadedFile($path, 'staff.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

    $this->postJson('/api/admin/staff/import', ['file' => $upload])
        ->assertCreated()
        ->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.users.0.role', 'supervisor');
});

test('the import is all or nothing and reports the spreadsheet row', function () {
    User::factory()->create(['username' => 'rina']);
    $csv = "username,full_name\nbudi,Budi\nrina,Rina\nbudi,Budi Lagi\n";

    $response = $this->postJson('/api/admin/staff/import', ['file' => csvUpload($csv)]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['rows.1.username', 'rows.2.username']);

    expect($response->json('errors')['rows.1.username'][0])->toContain('baris 3')
        ->and(User::where('username', 'budi')->exists())->toBeFalse();
});

test('imported accounts cannot be administrators', function () {
    $this->postJson('/api/admin/staff/import', ['file' => csvUpload("username,full_name,role\nboss,Boss,admin\n")])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rows.0.role');
});

test('the import requires a spreadsheet file', function () {
    $this->postJson('/api/admin/staff/import', ['file' => UploadedFile::fake()->create('foto.png', 10, 'image/png')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    $this->postJson('/api/admin/staff/import', ['file' => csvUpload("username,full_name\n")])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rows');
});

test('only administrators can import staff', function () {
    actingAsStaff(UserRoles::SUPERVISOR);

    $this->postJson('/api/admin/staff/import', ['file' => csvUpload("username,full_name\nx,X\n")])->assertForbidden();
});
