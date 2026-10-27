<?php

namespace App\Services;

use App\Enums\UserRoles;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Creates many teller/supervisor accounts at once from a spreadsheet (.xlsx or .csv).
 *
 * The first row is the header. Indonesian column names are accepted too, so the
 * existing piket list (Nama, Kelas, No WA) can be uploaded as is. The import is
 * all or nothing: if any row is invalid, no account is created.
 */
class StaffImportService
{
    public const MAX_ROWS = 500;

    /**
     * Header names (lowercased) mapped to user fields.
     */
    private const HEADER_ALIASES = [
        'username' => 'username',
        'full_name' => 'full_name',
        'nama' => 'full_name',
        'nama_lengkap' => 'full_name',
        'password' => 'password',
        'kata_sandi' => 'password',
        'role' => 'role',
        'peran' => 'role',
        'class' => 'class',
        'kelas' => 'class',
        'phone' => 'phone',
        'no_wa' => 'phone',
        'whatsapp' => 'phone',
        'no_hp' => 'phone',
    ];

    /**
     * @return array{created: int, users: array<int, array{user: User, generated_password: ?string}>}
     */
    public function import(UploadedFile $file): array
    {
        $rows = $this->read($file);

        $validator = Validator::make(['rows' => $rows], [
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*.username' => ['required', 'string', 'alpha_dash', 'max:50', 'distinct:ignore_case', 'unique:users,username'],
            'rows.*.full_name' => ['required', 'string', 'max:255'],
            'rows.*.password' => ['nullable', 'string', 'min:8'],
            'rows.*.role' => ['nullable', Rule::enum(UserRoles::class)->only([UserRoles::TELLER, UserRoles::SUPERVISOR])],
            'rows.*.class' => ['nullable', 'string', 'max:20'],
            'rows.*.phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
        ], [], $this->attributeNames(count($rows)));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($rows) {
            $users = array_map(function (array $row) {
                // Rows without a password get a random one, returned once so the
                // administrator can hand it to the new staff member.
                $generated = blank($row['password'] ?? null) ? Str::password(10, symbols: false) : null;

                $user = User::create([
                    'username' => $row['username'],
                    'full_name' => $row['full_name'],
                    'password' => $generated ?? $row['password'],
                    'role' => $row['role'] ?: UserRoles::TELLER,
                    'class' => $row['class'] ?: null,
                    'phone' => $row['phone'] ?: null,
                ]);

                return ['user' => $user, 'generated_password' => $generated];
            }, $rows);

            return ['created' => count($users), 'users' => $users];
        });
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function read(UploadedFile $file): array
    {
        $reader = $this->readerFor($file);
        $reader->open($file->getRealPath());

        $header = null;
        $rows = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn ($cell) => trim((string) $cell), $row->toArray());

                    if ($header === null) {
                        $header = array_map(fn ($name) => self::HEADER_ALIASES[Str::snake(Str::lower($name))] ?? null, $cells);

                        continue;
                    }

                    if (array_filter($cells, fn ($cell) => $cell !== '') === []) {
                        continue;
                    }

                    $record = array_fill_keys(['username', 'full_name', 'password', 'role', 'class', 'phone'], '');
                    foreach ($header as $index => $field) {
                        if ($field !== null) {
                            $record[$field] = $cells[$index] ?? '';
                        }
                    }
                    $record['role'] = Str::lower($record['role']);
                    $rows[] = $record;

                    if (count($rows) > self::MAX_ROWS) {
                        break 2;
                    }
                }

                break; // Only the first sheet is read.
            }
        } finally {
            $reader->close();
        }

        return $rows;
    }

    private function readerFor(UploadedFile $file): ReaderInterface
    {
        if (Str::lower($file->getClientOriginalExtension()) === 'xlsx') {
            return new XlsxReader();
        }

        // Excel with Indonesian regional settings saves CSV with ";" separators.
        $firstLine = (string) fgets(fopen($file->getRealPath(), 'r'));
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        return new CsvReader(new CsvOptions(FIELD_DELIMITER: $delimiter));
    }

    /**
     * Error messages name the spreadsheet row ("baris 3") instead of "rows.1".
     *
     * @return array<string, string>
     */
    private function attributeNames(int $count): array
    {
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            foreach (['username', 'full_name', 'password', 'role', 'class', 'phone'] as $field) {
                $names["rows.{$i}.{$field}"] = "{$field} (baris ".($i + 2).')';
            }
        }

        return $names;
    }
}
