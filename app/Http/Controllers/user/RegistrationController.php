<?php

namespace App\Http\Controllers\user;

use App\Http\Controllers\Controller;
use App\Imports\UsersImport;
use App\Models\Address;
use App\Models\Department;
use App\Models\Designation;
use App\Models\EmployeeType;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\UserDocuments;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class RegistrationController extends Controller
{
    /**
     * Get Form Options
     *
     * Fetches dropdown options required for employee registration forms.
     *
     * @group User Management
     *
     * @response 200 {
     *  "status": true,
     *  "departments": [{"id": 1, "name": "HR"}],
     *  "designations": [{"id": 1, "name": "Manager"}],
     *  "employee_types": [{"id": 1, "name": "Full Time"}],
     *  "reporting_managers": [{"id": 1, "first_name": "John", "last_name": "Doe"}]
     * }
     */
    public function sendFormOptions()
    {
        try {
            $departments = Department::select('id', 'name')->get();
            $designations = Designation::select('id', 'name')->get();
            $employee_types = EmployeeType::select('id', 'name')->get();
            $reporting_managers = User::select('id', 'first_name', 'last_name')
                ->where('role_id', 2) //  role_id 2 is for Admin
                ->where('is_disable', false)
                ->get();

            return response()->json([
                'status' => true,
                'departments' => $departments,
                'designations' => $designations,
                'employee_types' => $employee_types,
                'reporting_managers' => $reporting_managers,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to fetch form options', [
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Error occurred while fetching form options',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save Personal Information
     *
     * Creates a new employee or updates an existing employee's personal details.
     *
     *Note:
     * - `emp_id` is **nullable when creating a new employee**
     * - `emp_id` is **required when updating an existing employee**
     * @group User Management
     *
     * @bodyParam emp_id integer nullable  Existing employee ID (for update). Example: 1
     * @bodyParam salutation string nullable Example: Mr
     * @bodyParam first_name string required Example: John
     * @bodyParam middle_name string nullable Example: A
     * @bodyParam last_name string required Example: Doe
     * @bodyParam gender string required Example: Male
     * @bodyParam personal_email string required Example: john@example.com
     * @bodyParam phone_no string required Example: 9876543210
     * @bodyParam alt_phone_no string nullable Example: 9123456789
     * @bodyParam date_of_birth date required Example: 1995-05-10
     * @bodyParam marital_status string nullable Example: Single
     * @bodyParam blood_grp string nullable Example: O+
     * @bodyParam specially_abled string nullable Example: No
     *
     * @response 200 {
     *  "status": true,
     *  "message": "personal info saved successfully",
     *  "emp_id": 1
     * }
     *
     * @response 422 {
     *  "status": false,
     *  "message": "Validation failed"
     * }
     */
    public function savePersonalInfo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'emp_id' => 'nullable|exists:users,id',
            'salutation' => 'nullable|string',
            'first_name' => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name' => 'required|string|max:255',
            'gender' => 'required|string|max:10',
            'personal_email' => ['required', 'email', 'max:255', Rule::unique('users', 'personal_email')->ignore($request->emp_id)],
            'phone_no' => ['required', 'string', 'regex:/^[0-9]{10,15}$/', Rule::unique('users', 'phone_no')->ignore($request->emp_id)],
            'alt_phone_no' => ['nullable', 'string', 'regex:/^[0-9]{10,15}$/', Rule::unique('users', 'alt_phone_no')->ignore($request->emp_id)],
            'date_of_birth' => 'required|date|before:today',
            'marital_status' => 'nullable|string|max:50',
            'blood_grp' => 'nullable|string|max:5',
            'specially_abled' => 'nullable|string|max:50',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        DB::beginTransaction();
        try {
            $user = User::where('id', $request->emp_id)
                ->where('is_disable', false)
                ->first();

            if ($user) {
                // Update existing user
                User::where('id', $request->emp_id)->update([
                    'salutation' => $request->salutation,
                    'first_name' => $request->first_name,
                    'middle_name' => $request->middle_name ?? null,
                    'last_name' => $request->last_name,
                    'gender' => $request->gender,
                    'personal_email' => $request->personal_email,
                    'phone_no' => $request->phone_no,
                    'alt_phone_no' => $request->alt_phone_no,
                    'date_of_birth' => $request->date_of_birth,
                    'marital_status' => $request->marital_status,
                    'blood_grp' => $request->blood_grp ?? null,
                    'specially_abled' => $request->specially_abled ?? null,
                ]);
            } else {
                // Create new user
                $user = User::create([
                    'uuid' => Str::uuid(),
                    'salutation' => $request->salutation,
                    'first_name' => $request->first_name,
                    'middle_name' => $request->middle_name ?? null,
                    'last_name' => $request->last_name,
                    'gender' => $request->gender,
                    'personal_email' => $request->personal_email,
                    'phone_no' => $request->phone_no,
                    'alt_phone_no' => $request->alt_phone_no,
                    'role_id' => 3,
                    'date_of_birth' => $request->date_of_birth,
                    'marital_status' => $request->marital_status,
                    'blood_grp' => $request->blood_grp ?? null,
                    'specially_abled' => $request->specially_abled ?? null,
                    'is_disable' => false,
                ]);
                // In your controller after creating a user
                // $user = User::create($request->all());
                $this->addLeaveForNewUser($user->id);
            }
            DB::commit();
            return response()->json([
                'status' => true,
                'message' => ' personal info saved successfully',
                'emp_id' => $request->emp_id ?? $user->id,
                'user_id' => $user->id,
                'user_uuid' => $user->uuid,
            ], 200);
        } catch (Exception $e) {
            Log::error('Failed to save personal info', [
                'message' => $e->getMessage(),
            ]);
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Error occurred while saving personal info',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save User Addresses
     *
     * Saves or updates permanent and current addresses for a user.
     *
     * @group User Management
     *
     * @bodyParam emp_id integer required User ID. Example: 1
     * @bodyParam addresses array required List of addresses.
     * @bodyParam addresses[].type string required Allowed: permanent,current. Example: permanent
     * @bodyParam addresses[].address1 string required Example: Street 123
     * @bodyParam addresses[].address2 string nullable Example: Near Mall
     * @bodyParam addresses[].city string required Example: Mumbai
     * @bodyParam addresses[].state string required Example: Maharashtra
     * @bodyParam addresses[].pincode string required Example: 400001
     * @bodyParam addresses[].country string required Example: India
     *
     * @response 200 {
     *  "status": true,
     *  "message": "Addresses saved successfully"
     * }
     */
    public function saveAddress(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'emp_id' => 'required|exists:users,id',
            'addresses' => 'required|array',
            'addresses.*.type' => 'required|in:permanent,current',
            'addresses.*.address1' => 'required|string|max:255',
            'addresses.*.address2' => 'nullable|string|max:255',
            'addresses.*.city' => 'required|string|max:100',
            'addresses.*.state' => 'required|string|max:100',
            'addresses.*.pincode' => 'required|string|max:10',
            'addresses.*.country' => 'required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();
            // Ensure addresses is an array
            $addresses = is_string($request->addresses)
                ? json_decode($request->addresses, true)
                : $request->addresses;

            foreach ($addresses as $address) {
                Address::updateOrCreate(
                    [
                        'user_id' => $request->emp_id,
                        'type'    => $address['type'], // condition: same user + same type
                    ],
                    [
                        'uuid'    => Str::uuid(), // keep generating new uuid if inserted
                        'address1' => $address['address1'],
                        'address2' => $address['address2'] ?? null,
                        'city'     => $address['city'],
                        'state'    => $address['state'],
                        'pincode'  => $address['pincode'],
                        'country'  => $address['country'],
                    ]
                );
            }

            DB::commit();
            return response()->json([
                'status' => true,
                'message' => 'Addresses saved successfully',
            ]);
        } catch (Exception $e) {
            Log::error('Error occurred while saving Address Info', [
                'message' => $e->getMessage(),
            ]);
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Error occurred while saving Address Info',
            ], 500);
        }
    }

    /**
     * Save Employment Details
     *
     * Stores employment-related details for a user.
     *
     * @group User Management
     *
     * @bodyParam emp_id integer required Example: 1
     * @bodyParam office_id string nullable Example: EMP001
     * @bodyParam office_email string nullable Example: john@company.com
     * @bodyParam department_id integer nullable Example: 1
     * @bodyParam designation_id integer nullable Example: 2
     * @bodyParam date_of_joining date nullable Example: 2024-01-01
     * @bodyParam employee_type_id integer nullable Example: 1
     * @bodyParam reporting_manager_id integer nullable Example: 5
     * @bodyParam current_location string nullable Example: Mumbai
     * @bodyParam work_mode string nullable Example: remote,onsite,hybrid
     *
     * @response 200 {
     *  "status": true,
     *  "message": "Employment details saved successfully"
     * }
     */
    public function saveEmploymentDetails(Request $request)
    {
        Log::info('Employeement Request Data:', $request->all());
        $validator = Validator::make($request->all(), [
            'emp_id' => 'required|exists:users,id',
            'office_id' => ['nullable', 'string', 'max:255', Rule::unique('users', 'office_id')->ignore($request->emp_id)],
            'office_email' => 'nullable|email|max:255|unique:users,office_email',
            'department_id' => 'nullable|exists:departments,id',
            'designation_id' => 'nullable|exists:designations,id',
            'date_of_joining' => 'nullable|date',
            'employee_type_id' => 'nullable|exists:employee_types,id',
            'reporting_manager_id' => 'nullable|exists:users,id',
            'current_location' => 'nullable|string|max:255',
            'work_mode' => 'nullable|string|in:remote,onsite,hybrid',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        try {
            DB::beginTransaction();
            User::where('id', $request->emp_id,)->update([
                'office_id' => $request->office_id,
                'office_email' => $request->office_email,
                'department_id' => $request->department_id,
                'designation_id' => $request->designation_id,
                'date_of_joining' => $request->date_of_joining,
                'probation_end_date' => Carbon::parse($request->date_of_joining)->addMonths(3),
                'employee_type_id' => $request->employee_type_id,
                'reporting_manager_id' => $request->reporting_manager_id ?? null,
                'current_location' => $request->current_location ?? null,
                'work_mode' => $request->work_mode ?? null,
            ]);
            DB::commit();
            return response()->json([
                'status' => true,
                'message' => 'Employment details saved successfully',
            ]);
        } catch (Exception $e) {
            Log::error('Error occurred while saving Employeement Details', [
                'message' => $e->getMessage(),
            ]);
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Error occurred while saving Employeement Details',
            ], 500);
        }
    }

    /**
     * Upload User Documents
     *
     * Uploads employee documents such as Aadhaar, PAN, certificates, etc.
     *
     * @group User Management
     *
     * @bodyParam emp_id integer required Example: 1
     * @bodyParam adhar_card file nullable Image (jpeg/png). Max: 3MB
     * @bodyParam pan_card file nullable Image (jpeg/png). Max: 3MB
     * @bodyParam certificate file nullable Image (jpeg/png). Max: 3MB
     * @bodyParam experience_letter file nullable Image (jpeg/png). Max: 3MB
     * @bodyParam salary_slip file nullable File (jpeg/png/pdf)
     *
     * @response 200 {
     *  "status": true,
     *  "message": "Documents saved successfully"
     * }
     */
    public function saveDocuments(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'emp_id' => 'required|exists:users,id',
            'adhar_card' => 'nullable|file|mimes:jpeg,png|max:3000',
            'pan_card' => 'nullable|file|mimes:jpeg,png|max:3000',
            'certificate' => 'nullable|file|mimes:jpeg,png|max:3000',
            'experience_letter' => 'nullable|file|mimes:jpeg,png|max:3000',
            'salary_slip' => 'nullable|file|mimes:jpeg,png,pdf',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        try {
            DB::beginTransaction();
            $path = "user/{$request->emp_id}";

            // Handle file uploads if provided
            foreach (['adhar_card', 'pan_card', 'certificate', 'experience_letter', 'salary_slip'] as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    Storage::disk('public')->putFileAs($path, $file, $file->getClientOriginalName());

                    // Save the storage path for DB
                    $filePaths[$field] = "storage/{$path}/{$file->getClientOriginalName()}";
                } else {
                    $filePaths[$field] = null; // If file not uploaded
                }
            }

            UserDocuments::create(array_merge(
                ['user_id' => $request->emp_id],
                $filePaths
            ));
            DB::commit();
            return response()->json([
                'status' => true,
                'message' => 'Documnets saved successfully',
            ], 200);
        } catch (Exception $e) {
            Log::error('Error occurred while saving documnets', [
                'message' => $e->getMessage(),
            ]);
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Error occurred while saving documnets',
            ], 500);
        }
    }

    /**
     * Disable User
     *
     * Disables an employee account (soft disable).
     *
     * @group User Management
     *
     * @bodyParam user_id integer required Example: 1
     *
     * @response 200 {
     *  "message": "Employee credentials disabled successfully!"
     * }
     *
     * @response 422 {
     *  "status": false,
     *  "message": "Validation failed"
     * }
     */
    public function disableUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        try {

            $user = User::findOrFail($request->user_id);
            $user->is_disable = true;
            $user->save();

            return response()->json(['message' => 'Employee credentials disabled successfully!']);
        } catch (\Exception $e) {
            Log::error('Failed to disable user', [
                'message' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Failed to disable user.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function importExcel(Request $request)
    {
        Log::info('Import Excel Request Data:');
        Log::info('Import Excel Request Data:', $request->all());

        $request->validate([
            'file' => 'required|mimes:xlsx,csv,xls'
        ]);
        try {
            Excel::import(new UsersImport, $request->file('file'));
            return response()->json(['message' => 'Data imported successfully!']);
        } catch (\Exception $e) {
            Log::error('Excel Import Failed', [
                'message' => $e->getMessage(),
                'line'    => $e->getLine(),
            ]);
            return response()->json([
                'error' => 'Failed to import data.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function addLeaveForNewUser($userId)
    {
        $year = now()->year;
        $leaveTypes = LeaveType::all();

        foreach ($leaveTypes as $type) {
            // Skip the old half-day leave type
            // if ($type->id === 4) {
            //     continue;
            // }

            // // Set default allocation per leave type
            // $total = match ($type->id) {
            //     1 => 21,    // Paid Leave
            //     2 => 0,   // Comp-off 
            //     3 => 365,    // Unpaid Leave
            //     5 => 182,   // Maternity Leave
            //     default => 0,
            // };

            LeaveBalance::updateOrCreate(
                [
                    'user_id' => $userId,
                    'leave_type_id' => $type->id,
                    'year' => $year,
                ],
                [
                    'total_allocated' => $type->max_allowed_days,
                    'used_days' => 0,
                    'remaining_days' => $type->max_allowed_days,
                    'carry_forward_days' => 0,
                ]
            );
        }
    }
}
