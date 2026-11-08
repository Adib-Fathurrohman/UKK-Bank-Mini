<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\TransactionTypes;
use App\Models\Customer;
use App\Models\CustomerImport;
use App\Models\Major;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Email;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 * Moves existing savers from the old bookkeeping into the system at once,
 * from a spreadsheet (.xlsx or .csv).
 *
 * Customers: one row per student, with an optional balance (saldo) and an
 * optional email. Without an email the account stays "pending" until an
 * administrator adds one (PATCH /admin/customers/{id}); deposits at the
 * counter work in the meantime.
 *
 * Transaction history (optional): a second sheet "Transaksi" in the same
 * workbook, or a second file. Each row is a deposit or withdrawal of a
 * student in the customer list. When both a balance and a history are given,
 * the part of the balance the history does not explain becomes an opening
 * balance before the first transaction.
 *
 * Imported transactions belong to the import, not to a teller's shift, so no
 * shift report or cash count changes. They get their journal lines like any
 * transaction. The import is all or nothing: if any row is invalid, nothing
 * is saved.
 */
class CustomerImportService
{
    public const MAX_CUSTOMERS = 1000;

    public const MAX_TRANSACTIONS = 10000;

    public const OPENING_BALANCE_NOTE = 'Saldo awal dari pembukuan lama';

    /**
     * Header names (normalized, see key()) mapped to customer fields.
     */
    private const CUSTOMER_HEADERS = [
        'nis' => 'nis',
        'full_name' => 'full_name',
        'nama' => 'full_name',
        'nama_lengkap' => 'full_name',
        'nama_siswa' => 'full_name',
        'email' => 'email',
        'e_mail' => 'email',
        'class' => 'class',
        'kelas' => 'class',
        'major' => 'major',
        'jurusan' => 'major',
        'kode_jurusan' => 'major',
        'group' => 'group',
        'rombel' => 'group',
        'kelompok' => 'group',
        'balance' => 'balance',
        'saldo' => 'balance',
        'saldo_akhir' => 'balance',
        'account_number' => 'account_number',
        'no_rekening' => 'account_number',
        'nomor_rekening' => 'account_number',
    ];

    private const TRANSACTION_HEADERS = [
        'nis' => 'nis',
        'date' => 'date',
        'tanggal' => 'date',
        'tgl' => 'date',
        'type' => 'type',
        'jenis' => 'type',
        'jenis_transaksi' => 'type',
        'amount' => 'amount',
        'nominal' => 'amount',
        'jumlah' => 'amount',
        'note' => 'note',
        'keterangan' => 'note',
        'catatan' => 'note',
    ];

    private const TYPE_ALIASES = [
        'deposit' => 'deposit',
        'setor' => 'deposit',
        'setoran' => 'deposit',
        'setor_tunai' => 'deposit',
        'masuk' => 'deposit',
        'withdrawal' => 'withdrawal',
        'tarik' => 'withdrawal',
        'penarikan' => 'withdrawal',
        'tarik_tunai' => 'withdrawal',
        'keluar' => 'withdrawal',
    ];

    /**
     * Sheet names that hold the transaction history inside a customer workbook.
     */
    private const TRANSACTION_SHEETS = ['transaksi', 'transactions', 'riwayat', 'riwayat_transaksi', 'mutasi'];

    public function __construct(private CustomerService $customers) {}

    /**
     * @return array{import: CustomerImport, customers: Collection<int, Customer>}
     */
    public function import(UploadedFile $file, ?UploadedFile $transactionsFile, User $admin): array
    {
        [$customerSheet, $historySheet] = $this->sheets($file);

        if ($transactionsFile) {
            $historySheet = $this->sheets($transactionsFile)[0];
        }

        $majors = Major::all();
        $rows = array_map(fn (array $row) => $this->customerRecord($row), $this->records($customerSheet, self::CUSTOMER_HEADERS, ['nis', 'full_name', 'email', 'class', 'major', 'group', 'balance', 'account_number']));
        $history = array_map(fn (array $row) => $this->transactionRecord($row), $this->records($historySheet, self::TRANSACTION_HEADERS, ['nis', 'date', 'type', 'amount', 'note']));

        $this->validate($rows, $history, $majors);

        $plans = $this->plan($rows, $history);

        return DB::transaction(function () use ($file, $admin, $rows, $plans, $majors) {
            $import = CustomerImport::create([
                'imported_by' => $admin->id,
                'file_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'customer_count' => count($rows),
                'transaction_count' => array_sum(array_map(fn (array $plan) => count($plan['transactions']), $plans)),
            ]);

            // Rows that keep their old account number go first, so a generated
            // number never takes one that a later row brings along.
            $order = collect($rows)->sortBy(fn (array $row, int $index) => [$row['account_number'] === '' ? 1 : 0, $index]);

            $customers = $order->map(function (array $row, int $index) use ($admin, $import, $plans, $majors) {
                $major = $majors->first(fn (Major $major) => $this->matchesMajor($major, $row['major']));

                $customer = Customer::create([
                    'account_number' => $row['account_number'] ?: $this->customers->nextAccountNumber($major, now()),
                    'nis' => $row['nis'],
                    'full_name' => $row['full_name'],
                    'email' => $row['email'] ?: null,
                    'class' => $row['class'],
                    'major_id' => $major->id,
                    'group' => $row['group'],
                    'account_status' => AccountStatus::ACTIVE,
                    'balance' => $plans[$index]['balance'],
                ]);

                foreach ($plans[$index]['transactions'] as $line) {
                    $this->recordTransaction($customer, $import, $admin, $line);
                }

                return $customer;
            })->sortKeys()->values();

            return ['import' => $import, 'customers' => $customers];
        });
    }

    /**
     * Field-level checks; error keys name the row (rows.3.nis, transactions.0.date)
     * and the messages name the spreadsheet row ("baris 5").
     */
    private function validate(array $rows, array $history, Collection $majors): void
    {
        $majorNames = $majors->flatMap(fn (Major $major) => [Str::lower($major->code), Str::lower($major->major_name)])->all();

        $validator = Validator::make(['rows' => $rows, 'transactions' => $history], [
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_CUSTOMERS],
            'rows.*.nis' => ['required', 'string', 'max:20', 'distinct', 'unique:customers,nis'],
            'rows.*.full_name' => ['required', 'string', 'max:255'],
            'rows.*.email' => ['nullable', 'string', 'email:rfc', 'max:255', 'distinct', 'unique:customers,email'],
            'rows.*.class' => ['required', 'in:X,XI,XII'],
            'rows.*.major' => ['required', Rule::in($majorNames)],
            'rows.*.group' => ['required', 'in:1,2,3,4'],
            'rows.*.balance' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'rows.*.account_number' => ['nullable', 'string', 'max:30', 'distinct', 'unique:customers,account_number'],
            'transactions' => ['array', 'max:'.self::MAX_TRANSACTIONS],
            'transactions.*.nis' => ['required', 'string', Rule::in(array_column($rows, 'nis'))],
            'transactions.*.date' => ['required', 'date_format:Y-m-d H:i:s', 'before_or_equal:now'],
            'transactions.*.type' => ['required', 'in:deposit,withdrawal'],
            'transactions.*.amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'transactions.*.note' => ['nullable', 'string', 'max:255'],
        ], [
            'rows.*.major.in' => ':attribute tidak dikenal. Gunakan kode atau nama jurusan yang terdaftar.',
            'transactions.*.nis.in' => ':attribute tidak ada di daftar nasabah yang diimpor.',
            'transactions.*.date.date_format' => ':attribute tidak dikenali. Gunakan format YYYY-MM-DD atau DD/MM/YYYY.',
            'transactions.*.type.in' => ':attribute harus setor atau tarik.',
        ], $this->attributeNames(count($rows), count($history)));

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Per customer: the transactions to record, oldest first, and the final balance.
     *
     * @return array<int, array{balance: int, transactions: array<int, array{type: TransactionTypes, amount: int, balance_before: int, balance_after: int, date: Carbon, note: ?string}>}>
     */
    private function plan(array $rows, array $history): array
    {
        $byNis = [];
        foreach ($history as $index => $line) {
            $byNis[$line['nis']][] = [...$line, 'index' => $index];
        }

        $errors = [];
        $plans = [];

        foreach ($rows as $rowIndex => $row) {
            $lines = $byNis[$row['nis']] ?? [];
            // Oldest first; rows of the same moment keep the order of the file.
            usort($lines, fn (array $a, array $b) => [$a['date'], $a['index']] <=> [$b['date'], $b['index']]);

            $net = array_sum(array_map(fn (array $line) => $line['type'] === 'deposit' ? $line['amount'] : -$line['amount'], $lines));
            $opening = $row['balance'] === '' ? 0 : (int) $row['balance'] - $net;

            if ($opening < 0) {
                $errors["rows.{$rowIndex}.balance"] = 'Saldo nasabah '.$row['nis'].' (baris '.($rowIndex + 2).') lebih kecil dari hasil riwayat transaksinya (Rp'.number_format($net, 0, ',', '.').').';

                continue;
            }

            $balance = $opening;
            $transactions = [];

            if ($opening > 0) {
                $transactions[] = [
                    'type' => TransactionTypes::DEPOSIT,
                    'amount' => $opening,
                    'balance_before' => 0,
                    'balance_after' => $opening,
                    'date' => $lines ? Carbon::parse($lines[0]['date']) : now(),
                    'note' => self::OPENING_BALANCE_NOTE,
                ];
            }

            foreach ($lines as $line) {
                $after = $line['type'] === 'deposit' ? $balance + $line['amount'] : $balance - $line['amount'];

                if ($after < 0) {
                    $errors["transactions.{$line['index']}.amount"] = 'Saldo nasabah '.$row['nis'].' menjadi minus pada transaksi baris '.($line['index'] + 2).'.';

                    continue 2;
                }

                $transactions[] = [
                    'type' => TransactionTypes::from($line['type']),
                    'amount' => (int) $line['amount'],
                    'balance_before' => $balance,
                    'balance_after' => $after,
                    'date' => Carbon::parse($line['date']),
                    'note' => $line['note'] ?: null,
                ];
                $balance = $after;
            }

            $plans[$rowIndex] = ['balance' => $balance, 'transactions' => $transactions];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $plans;
    }

    private function recordTransaction(Customer $customer, CustomerImport $import, User $admin, array $line): void
    {
        $transaction = (new Transaction)->forceFill([
            'user_id' => $admin->id,
            'customer_id' => $customer->id,
            'customer_import_id' => $import->id,
            'type' => $line['type'],
            'amount' => $line['amount'],
            'balance_before' => $line['balance_before'],
            'balance_after' => $line['balance_after'],
            'note' => $line['note'],
            'created_at' => $line['date'],
            'updated_at' => $line['date'],
        ]);
        $transaction->save();

        // Dated like the transaction, so the journal reports put it in its period.
        foreach (JournalService::entriesFor($line['type']) as $entry) {
            $transaction->accountingJournals()->make([...$entry, 'amount' => $line['amount']])
                ->forceFill(['created_at' => $line['date'], 'updated_at' => $line['date']])
                ->save();
        }
    }

    /**
     * "XI RPL 2" in the class column is split into class, major and group
     * when those columns are empty; balances like "Rp 150.000" become 150000.
     */
    private function customerRecord(array $row): array
    {
        if ($row['major'] === '' && $row['group'] === ''
            && preg_match('/^(XII|XI|X)\s+([A-Za-z]+)\s*-?\s*([1-4])$/i', $row['class'], $parts)) {
            [, $row['class'], $row['major'], $row['group']] = $parts;
        }

        $row['class'] = Str::upper($row['class']);
        $row['major'] = Str::lower($row['major']);
        $row['email'] = Email::normalize($row['email']);
        $row['balance'] = $this->amount($row['balance']);

        return $row;
    }

    private function transactionRecord(array $row): array
    {
        $row['type'] = self::TYPE_ALIASES[$this->key($row['type'])] ?? $row['type'];
        $row['amount'] = $this->amount($row['amount']);
        $row['date'] = $this->date($row['date']) ?? $row['date'];

        return $row;
    }

    private function matchesMajor(Major $major, string $value): bool
    {
        return in_array($value, [Str::lower($major->code), Str::lower($major->major_name)], true);
    }

    /**
     * Whole rupiah from "150000", "150.000", "Rp 150.000,00" or 150000.0;
     * anything else is returned unchanged so validation reports it.
     */
    private function amount(string $value): int|string
    {
        $clean = preg_replace('/^rp\.?\s*/i', '', str_replace(' ', '', $value));

        return match (true) {
            $clean === '' => '',
            (bool) preg_match('/^\d+$/', $clean) => (int) $clean,
            (bool) preg_match('/^\d{1,3}(\.\d{3})+(,0+)?$/', $clean) => (int) str_replace('.', '', preg_replace('/,0+$/', '', $clean)),
            (bool) preg_match('/^\d+[.,]0+$/', $clean) => (int) $clean,
            default => $value,
        };
    }

    /**
     * Dates as Y-m-d H:i:s from ISO (2025-07-14) or Indonesian (14/07/2025) notation,
     * or from an Excel day number.
     * Impossible dates such as 31/02/2025 are refused.
     */
    private function date(string $value): ?string
    {
        // A date cell without a date format reaches us as Excel's day number (45852 = 2025-07-14).
        if (preg_match('/^\d{5}(\.\d+)?$/', $value)) {
            // Counted in UTC: local time zones of 1899 have odd offsets (Jakarta +07:07:12).
            return Carbon::create(1899, 12, 30, 0, 0, 0, 'UTC')->addSeconds((int) round((float) $value * 86400))->format('Y-m-d H:i:s');
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'j/n/Y H:i:s', 'j/n/Y H:i', 'j/n/Y', 'j-n-Y H:i', 'j-n-Y', 'j.n.Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /**
     * The first sheet of the file and, in a workbook, the transaction sheet (if any).
     *
     * @return array{0: array<int, array<int, string>>, 1: array<int, array<int, string>>}
     */
    private function sheets(UploadedFile $file): array
    {
        $reader = $this->readerFor($file);
        $reader->open($file->getRealPath());

        $first = null;
        $history = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $isHistory = $first !== null && in_array($this->key($sheet->getName()), self::TRANSACTION_SHEETS, true);

                if ($first !== null && ! $isHistory) {
                    continue;
                }

                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_map(fn ($value) => $this->cell($value), $row->toArray());

                    if (count($rows) > self::MAX_TRANSACTIONS + 1) {
                        break;
                    }
                }

                if ($first === null) {
                    $first = $rows;
                } else {
                    $history = $rows;
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return [$first ?? [], $history];
    }

    /**
     * Records keyed by field from the rows under the header row; empty rows are skipped.
     *
     * @return array<int, array<string, string>>
     */
    private function records(array $rows, array $aliases, array $fields): array
    {
        $header = null;
        $records = [];

        foreach ($rows as $cells) {
            if (array_filter($cells, fn ($cell) => $cell !== '') === []) {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn ($name) => $aliases[$this->key($name)] ?? null, $cells);

                continue;
            }

            $record = array_fill_keys($fields, '');
            foreach ($header as $index => $field) {
                if ($field !== null) {
                    $record[$field] = $cells[$index] ?? '';
                }
            }
            $records[] = $record;
        }

        return $records;
    }

    private function cell(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            // Excel stores 150000 and NIS numbers as floats.
            is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX => (string) (int) $value,
            is_bool($value) => $value ? '1' : '0',
            default => trim((string) $value),
        };
    }

    /**
     * "No. Rekening" → "no_rekening", "E-mail" → "e_mail".
     */
    private function key(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', Str::lower(trim($name))), '_');
    }

    private function readerFor(UploadedFile $file): ReaderInterface
    {
        if (Str::lower($file->getClientOriginalExtension()) === 'xlsx') {
            return new XlsxReader;
        }

        // Excel with Indonesian regional settings saves CSV with ";" separators.
        $firstLine = (string) fgets(fopen($file->getRealPath(), 'r'));
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        return new CsvReader(new CsvOptions(FIELD_DELIMITER: $delimiter));
    }

    /**
     * @return array<string, string>
     */
    private function attributeNames(int $customers, int $transactions): array
    {
        $names = [];
        for ($i = 0; $i < $customers; $i++) {
            foreach (['nis', 'full_name', 'email', 'class', 'major', 'group', 'balance', 'account_number'] as $field) {
                $names["rows.{$i}.{$field}"] = "{$field} (baris ".($i + 2).')';
            }
        }
        for ($i = 0; $i < $transactions; $i++) {
            foreach (['nis', 'date', 'type', 'amount', 'note'] as $field) {
                $names["transactions.{$i}.{$field}"] = "{$field} (transaksi baris ".($i + 2).')';
            }
        }

        return $names;
    }
}
