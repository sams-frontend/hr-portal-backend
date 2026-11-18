<?php

use App\Models\Address;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tymon\JWTAuth\Facades\JWTAuth;

// Truncation (not transactions) so Postgres ID sequences restart: the seeders rely on ids starting at 1.
uses(DatabaseTruncation::class);

const IMPORT_HEADERS = [
    'salutation', 'first_name', 'middle_name', 'last_name', 'gender', 'office_id', 'personal_email', 'office_email',
    'phone_no', 'alt_phone_no', 'department_id', 'designation_id', 'date_of_joining', 'date_of_birth', 'marital_status',
    'about', 'current_location', 'blood_grp', 'specially_abled', 'employee_type_id', 'reporting_manager_id',
    'current_address1', 'current_address2', 'current_city', 'current_state', 'current_pincode', 'current_country',
    'same_as_current', 'permanent_address1', 'permanent_address2', 'permanent_city', 'permanent_state',
    'permanent_pincode', 'permanent_country',
];

beforeEach(function () {
    $this->seed();
    $this->admin = User::where('role_id', 2)->firstOrFail();
    $this->token = JWTAuth::fromUser($this->admin);
    $this->n = 0;
});

function employeeRow(array $overrides = []): array
{
    $n = test()->n = test()->n + 1;

    return array_merge([
        'salutation' => 'Mr', 'first_name' => "Import{$n}", 'last_name' => 'Tester', 'gender' => 'Male',
        'office_id' => "IMP{$n}", 'personal_email' => "import{$n}@example.com", 'office_email' => "import{$n}@company.com",
        'phone_no' => (string) (9100000000 + $n), 'department_id' => 1, 'designation_id' => 1, 'employee_type_id' => 1,
        'date_of_joining' => '2026-01-15', 'date_of_birth' => '1995-05-10',
        'current_address1' => '12 MG Road', 'current_city' => 'Mumbai', 'current_state' => 'Maharashtra',
        'current_pincode' => '400001', 'current_country' => 'India', 'same_as_current' => 'yes',
    ], $overrides);
}

function employeeSheet(array $rows, array $headers = IMPORT_HEADERS): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray($headers, null, 'A1');
    foreach ($rows as $i => $row) {
        $sheet->fromArray(array_map(fn ($h) => $row[$h] ?? null, $headers), null, 'A' . ($i + 2), true);
    }
    $path = tempnam(sys_get_temp_dir(), 'emp') . '.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'employees.xlsx', null, null, true);
}

function uploadEmployees(UploadedFile $file)
{
    return test()->withHeader('Authorization', 'Bearer ' . test()->token)
        ->post('/api/user/import', ['file' => $file], ['Accept' => 'application/json']);
}

it('imports every row when all rows are valid', function () {
    $before = User::count();

    $response = uploadEmployees(employeeSheet([employeeRow(), employeeRow()]));

    $response->assertOk()->assertJson([
        'status' => true,
        'message' => 'All 2 employees imported successfully.',
        'summary' => ['total_rows' => 2, 'imported' => 2, 'failed' => 0],
        'failed' => [],
    ]);
    expect($response->json('imported.*.row'))->toBe([2, 3]);
    expect(User::count())->toBe($before + 2);

    $user = User::where('personal_email', 'import1@example.com')->firstOrFail();
    expect($user->role_id)->toBe(3)
        ->and($user->work_mode)->toBe('onsite')
        ->and($user->probation_end_date)->toBe('2026-04-15')
        ->and(Address::where('user_id', $user->id)->pluck('type')->sort()->values()->all())->toBe(['current', 'permanent'])
        ->and(LeaveBalance::where('user_id', $user->id)->count())->toBe(LeaveType::count());
});

it('skips invalid rows, imports the rest and reports why each row failed', function () {
    $existing = User::firstOrFail();

    $response = uploadEmployees(employeeSheet([
        employeeRow(),                                                   // row 2 ok
        employeeRow(['first_name' => null]),                             // row 3 required missing
        employeeRow(['personal_email' => $existing->personal_email]),    // row 4 duplicate in DB
        employeeRow(['department_id' => 999, 'gender' => 'xyz']),        // row 5 invalid values
        employeeRow(['date_of_birth' => 'not-a-date']),                  // row 6 invalid date
        employeeRow(),                                                   // row 7 ok
    ]));

    $response->assertOk()->assertJson([
        'status' => true,
        'message' => '2 of 6 employees imported. 4 rows failed.',
        'summary' => ['total_rows' => 6, 'imported' => 2, 'failed' => 4],
    ]);
    expect($response->json('imported.*.row'))->toBe([2, 7]);

    $failed = collect($response->json('failed'))->keyBy('row');
    expect($failed->keys()->all())->toBe([3, 4, 5, 6])
        ->and($failed[3]['errors'])->toHaveKey('first_name')
        ->and($failed[4]['errors']['personal_email'][0])->toContain('already used by another employee')
        ->and($failed[5]['errors'])->toHaveKeys(['department_id', 'gender'])
        ->and($failed[6]['errors']['date_of_birth'])->toBe(['The date of birth must be a valid date in YYYY-MM-DD format (DD-MM-YYYY and DD/MM/YYYY are also accepted).'])
        ->and($failed[4]['name'])->toBe('Import3 Tester');

    expect(User::where('personal_email', 'like', 'import%@example.com')->count())->toBe(2);
});

it('reports a duplicate within the same file against the later row', function () {
    $response = uploadEmployees(employeeSheet([
        employeeRow(['phone_no' => '9199999999']),
        employeeRow(['phone_no' => '9199999999']),
    ]));

    $response->assertOk()->assertJsonPath('summary.imported', 1)->assertJsonPath('failed.0.row', 3);
    expect($response->json('failed.0.errors'))->toHaveKey('phone_no');
});

it('returns 422 when no row could be imported', function () {
    $response = uploadEmployees(employeeSheet([employeeRow(['phone_no' => '12ab']), employeeRow(['current_city' => null])]));

    $response->assertStatus(422)->assertJson([
        'status' => false,
        'message' => 'No employees were imported. 2 rows failed.',
        'summary' => ['total_rows' => 2, 'imported' => 0, 'failed' => 2],
        'imported' => [],
    ]);
    expect($response->json('failed.0.errors'))->toHaveKey('phone_no')
        ->and($response->json('failed.1.errors'))->toHaveKey('current_city');
});

it('requires the permanent address unless same_as_current is yes', function () {
    $response = uploadEmployees(employeeSheet([
        employeeRow(['same_as_current' => 'no']),
        employeeRow(['same_as_current' => 'No', 'permanent_address1' => '45 Station Rd', 'permanent_city' => 'Pune',
            'permanent_state' => 'Maharashtra', 'permanent_pincode' => '411001', 'permanent_country' => 'India']),
    ]));

    $response->assertOk()->assertJsonPath('summary.imported', 1)->assertJsonPath('failed.0.row', 2);
    expect($response->json('failed.0.errors'))->toHaveKeys(['permanent_address1', 'permanent_city', 'permanent_state', 'permanent_pincode', 'permanent_country']);

    $user = User::where('personal_email', 'import2@example.com')->firstOrFail();
    expect(Address::where('user_id', $user->id)->where('type', 'permanent')->value('city'))->toBe('Pune');
});

it('understands Excel date cells, DD/MM/YYYY dates and numeric phone numbers', function () {
    $response = uploadEmployees(employeeSheet([
        employeeRow(['date_of_birth' => 34829, 'phone_no' => 9187654321, 'current_pincode' => 400001]), // 34829 = 1995-05-10
        employeeRow(['date_of_birth' => '10/05/1995', 'date_of_joining' => '15-01-2026', 'gender' => 'female']),
    ]));

    $response->assertOk()->assertJsonPath('summary.failed', 0);

    $first = User::where('personal_email', 'import1@example.com')->firstOrFail();
    expect($first->date_of_birth)->toBe('1995-05-10')->and($first->phone_no)->toBe('9187654321');

    $second = User::where('personal_email', 'import2@example.com')->firstOrFail();
    expect($second->date_of_birth)->toBe('1995-05-10')
        ->and($second->date_of_joining)->toBe('2026-01-15')
        ->and($second->gender)->toBe('Female');
});

it('accepts files without optional columns', function () {
    $headers = array_values(array_diff(IMPORT_HEADERS, ['about', 'middle_name', 'reporting_manager_id', 'permanent_address1']));

    uploadEmployees(employeeSheet([employeeRow()], $headers))->assertOk()->assertJsonPath('summary.imported', 1);
});

it('rejects a file that is missing required columns', function () {
    $headers = array_values(array_diff(IMPORT_HEADERS, ['phone_no', 'current_city']));

    uploadEmployees(employeeSheet([employeeRow()], $headers))
        ->assertStatus(422)
        ->assertJson([
            'status' => false,
            'message' => 'The file is missing required columns: phone_no, current_city',
            'missing_columns' => ['phone_no', 'current_city'],
        ]);
});

it('rejects a file with no data rows and ignores blank rows', function () {
    uploadEmployees(employeeSheet([]))
        ->assertStatus(422)
        ->assertJson(['status' => false, 'message' => 'The file does not contain any employee rows.']);

    // A blank row in the middle is skipped but row numbers still match the spreadsheet.
    $response = uploadEmployees(employeeSheet([employeeRow(), [], employeeRow(['first_name' => null])]));
    $response->assertOk()->assertJsonPath('summary.total_rows', 2)->assertJsonPath('failed.0.row', 4);
});

it('rejects non Excel files', function () {
    uploadEmployees(UploadedFile::fake()->create('employees.txt', 1, 'text/plain'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

it('requires authentication', function () {
    $this->post('/api/user/import', ['file' => employeeSheet([employeeRow()])], ['Accept' => 'application/json'])
        ->assertStatus(401);
});
