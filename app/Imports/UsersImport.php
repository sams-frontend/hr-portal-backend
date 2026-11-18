<?php

namespace App\Imports;

use App\Http\Controllers\user\RegistrationController;
use App\Models\Address;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Bulk-creates employees from an Excel / CSV sheet (first sheet, header in row 1).
 *
 * Every row is validated and saved on its own: valid rows are imported, invalid
 * rows are skipped and reported back with their spreadsheet row number and reasons.
 */
class UsersImport implements WithHeadingRow
{
    /** Columns that must exist in the header row. */
    public const REQUIRED_COLUMNS = [
        'first_name', 'last_name', 'gender', 'personal_email', 'phone_no', 'date_of_birth',
        'current_address1', 'current_city', 'current_state', 'current_pincode', 'current_country',
    ];

    /** All columns the importer understands (any other column is ignored). */
    public const COLUMNS = [
        'salutation', 'first_name', 'middle_name', 'last_name', 'gender', 'date_of_birth',
        'marital_status', 'blood_grp', 'specially_abled', 'personal_email', 'phone_no', 'alt_phone_no',
        'office_id', 'office_email', 'department_id', 'designation_id', 'employee_type_id',
        'reporting_manager_id', 'date_of_joining', 'work_mode', 'current_location', 'about',
        'current_address1', 'current_address2', 'current_city', 'current_state', 'current_pincode', 'current_country',
        'same_as_current',
        'permanent_address1', 'permanent_address2', 'permanent_city', 'permanent_state', 'permanent_pincode', 'permanent_country',
    ];

    public const MAX_ROWS = 1000;

    private const DATE_COLUMNS = ['date_of_birth', 'date_of_joining'];

    /** Numeric foreign keys; every other column is treated as text. */
    private const ID_COLUMNS = ['department_id', 'designation_id', 'employee_type_id', 'reporting_manager_id'];

    /** Header names (normalised the same way WithHeadingRow does) found in the file. */
    public function headings(UploadedFile $file): array
    {
        $headings = (new HeadingRowImport())->toArray($file)[0][0] ?? [];

        return array_values(array_filter($headings, fn ($h) => $h !== null && $h !== ''));
    }

    public function missingColumns(UploadedFile $file): array
    {
        return array_values(array_diff(self::REQUIRED_COLUMNS, $this->headings($file)));
    }

    /** @return Collection<int, array{row: int, data: array}> non-empty rows with their spreadsheet row number */
    public function rows(UploadedFile $file): Collection
    {
        $sheet = Excel::toCollection($this, $file)->first() ?? collect();

        return $sheet
            ->map(fn ($row, $i) => ['row' => $i + 2, 'data' => $row->toArray()]) // +2: 0-based index + header row
            ->filter(fn ($r) => collect($r['data'])->contains(fn ($v) => $v !== null && trim((string) $v) !== ''))
            ->values();
    }

    /**
     * @return array{imported: list<array>, failed: list<array>}
     */
    public function process(Collection $rows): array
    {
        $imported = [];
        $failed = [];

        foreach ($rows as ['row' => $rowNumber, 'data' => $raw]) {
            $data = $this->normalise($raw);
            $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?: null;

            $validator = Validator::make($data, $this->rules($data), $this->messages(), $this->attributes());
            if ($validator->fails()) {
                $failed[] = ['row' => $rowNumber, 'name' => $name, 'errors' => $validator->errors()->toArray()];
                continue;
            }

            try {
                $user = DB::transaction(fn () => $this->save($validator->validated()));
                $imported[] = [
                    'row' => $rowNumber,
                    'id' => $user->id,
                    'uuid' => $user->uuid,
                    'name' => $name,
                    'personal_email' => $user->personal_email,
                ];
            } catch (\Throwable $e) {
                Log::error('Employee import: failed to save row', ['row' => $rowNumber, 'message' => $e->getMessage()]);
                $failed[] = ['row' => $rowNumber, 'name' => $name, 'errors' => ['row' => ['Could not save this row due to an unexpected error. Please check the data and try again.']]];
            }
        }

        return ['imported' => $imported, 'failed' => $failed];
    }

    private function rules(array $data): array
    {
        $digits = 'regex:/^[0-9]{10,15}$/';

        return [
            'salutation' => 'nullable|string|max:20',
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'gender' => 'required|in:Male,Female,Other',
            'date_of_birth' => 'bail|required|date_format:Y-m-d|before:today',
            'marital_status' => 'nullable|string|max:50',
            'blood_grp' => 'nullable|string|max:5',
            'specially_abled' => 'nullable|string|max:50',
            'personal_email' => ['required', 'email', 'max:255', Rule::unique('users', 'personal_email')],
            'phone_no' => ['required', $digits, Rule::unique('users', 'phone_no')],
            'alt_phone_no' => ['nullable', $digits, 'different:phone_no', Rule::unique('users', 'alt_phone_no')],
            'office_id' => ['nullable', 'string', 'max:255', Rule::unique('users', 'office_id')],
            'office_email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'office_email')],
            'department_id' => 'nullable|integer|exists:departments,id',
            'designation_id' => 'nullable|integer|exists:designations,id',
            'employee_type_id' => 'nullable|integer|exists:employee_types,id',
            'reporting_manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_disable', false)],
            'date_of_joining' => 'bail|nullable|date_format:Y-m-d',
            'work_mode' => 'nullable|in:remote,onsite,hybrid',
            'current_location' => 'nullable|string|max:255',
            'about' => 'nullable|string',
            'current_address1' => 'required|string|max:255',
            'current_address2' => 'nullable|string|max:255',
            'current_city' => 'required|string|max:100',
            'current_state' => 'required|string|max:100',
            'current_pincode' => 'required|string|max:10',
            'current_country' => 'required|string|max:100',
            'same_as_current' => 'nullable|in:yes,no',
            'permanent_address1' => 'required_unless:same_as_current,yes|nullable|string|max:255',
            'permanent_address2' => 'nullable|string|max:255',
            'permanent_city' => 'required_unless:same_as_current,yes|nullable|string|max:100',
            'permanent_state' => 'required_unless:same_as_current,yes|nullable|string|max:100',
            'permanent_pincode' => 'required_unless:same_as_current,yes|nullable|string|max:10',
            'permanent_country' => 'required_unless:same_as_current,yes|nullable|string|max:100',
        ];
    }

    private function messages(): array
    {
        return [
            'unique' => 'This :attribute is already used by another employee (or by an earlier row in this file).',
            'regex' => 'The :attribute must contain only digits (10 to 15 digits).',
            'date_format' => 'The :attribute must be a valid date in YYYY-MM-DD format (DD-MM-YYYY and DD/MM/YYYY are also accepted).',
            'gender.in' => 'The gender must be one of: Male, Female, Other.',
            'work_mode.in' => 'The work mode must be one of: remote, onsite, hybrid.',
            'same_as_current.in' => 'The same as current column must be yes or no.',
            'exists' => 'The selected :attribute does not exist.',
            'reporting_manager_id.exists' => 'The reporting manager id does not match any active employee.',
            'required_unless' => 'The :attribute is required unless same_as_current is yes.',
        ];
    }

    private function attributes(): array
    {
        return collect(self::COLUMNS)->mapWithKeys(fn ($c) => [$c => str_replace('_', ' ', $c)])->all();
    }

    /** Trim strings, turn blanks into null, and undo Excel's number/date conversions. */
    private function normalise(array $raw): array
    {
        $data = [];
        foreach (self::COLUMNS as $column) {
            $value = $raw[$column] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '') {
                $value = null;
            }
            if (is_float($value) && floor($value) == $value) {
                $value = (int) $value;
            }
            if ($value !== null && in_array($column, self::DATE_COLUMNS, true)) {
                $value = $this->normaliseDate($value);
            } elseif (is_scalar($value) && ! in_array($column, self::ID_COLUMNS, true)) {
                $value = (string) $value; // e.g. phone numbers / pincodes that Excel stored as numbers
            }

            $data[$column] = $value;
        }

        foreach (['personal_email', 'office_email'] as $email) {
            if (is_string($data[$email])) {
                $data[$email] = Str::lower($data[$email]);
            }
        }
        if (is_string($data['gender'])) {
            $data['gender'] = Str::ucfirst(Str::lower($data['gender']));
        }
        foreach (['work_mode', 'same_as_current'] as $lower) {
            if (is_string($data[$lower])) {
                $data[$lower] = Str::lower($data[$lower]);
            }
        }

        return $data;
    }

    /** Returns Y-m-d when the value is a recognisable date, otherwise the raw value (so validation reports it). */
    private function normaliseDate(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value) || is_float($value)) {
            // Excel stores dates as serial day numbers.
            return $value > 0 && $value < 2958466
                ? ExcelDate::excelToDateTimeObject($value)->format('Y-m-d')
                : (string) $value;
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d', 'd.m.Y'] as $format) {
            $date = \DateTime::createFromFormat('!' . $format, (string) $value);
            if ($date && $date->format($format) === (string) $value) {
                return $date->format('Y-m-d');
            }
        }

        return (string) $value;
    }

    private function save(array $data): User
    {
        $user = User::create([
            'uuid' => Str::uuid(),
            ...Arr::only($data, [
                'salutation', 'first_name', 'middle_name', 'last_name', 'gender', 'office_id',
                'personal_email', 'office_email', 'phone_no', 'alt_phone_no', 'department_id',
                'designation_id', 'date_of_joining', 'date_of_birth', 'marital_status', 'about',
                'current_location', 'blood_grp', 'specially_abled', 'employee_type_id', 'reporting_manager_id',
            ]),
            'work_mode' => $data['work_mode'] ?? 'onsite',
            'role_id' => 3, // Employee
            'probation_end_date' => $data['date_of_joining'] ? Carbon::parse($data['date_of_joining'])->addMonths(3) : null,
            'is_disable' => false,
        ]);

        $current = [
            'address1' => $data['current_address1'],
            'address2' => $data['current_address2'],
            'city' => $data['current_city'],
            'state' => $data['current_state'],
            'pincode' => $data['current_pincode'],
            'country' => $data['current_country'],
        ];
        $permanent = $data['same_as_current'] === 'yes' ? $current : [
            'address1' => $data['permanent_address1'],
            'address2' => $data['permanent_address2'],
            'city' => $data['permanent_city'],
            'state' => $data['permanent_state'],
            'pincode' => $data['permanent_pincode'],
            'country' => $data['permanent_country'],
        ];

        Address::create(['uuid' => Str::uuid(), 'user_id' => $user->id, 'type' => 'current', ...$current]);
        Address::create(['uuid' => Str::uuid(), 'user_id' => $user->id, 'type' => 'permanent', ...$permanent]);

        app(RegistrationController::class)->addLeaveForNewUser($user->id);

        return $user;
    }
}
