<?php

namespace App\Http\Controllers\manage_Leaves;

use App\Http\Controllers\Controller;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LeaveTypeController extends Controller
{
    /**
     * Get Leave Types (Paginated)
     *
     * Fetch a paginated list of leave types.
     *
     * - Returns 5 leave types per page
     * - Includes pagination metadata (current page, total, etc.)
     *
     * @group Leave Type Management
     *
     * @authenticated
     *
     * @queryParam page integer optional Page number for pagination. Example: 1
     *
     * @response 200 {
     *   "data": {
     *     "current_page": 1,
     *     "data": [
     *       {
     *         "id": 1,
     *         "name": "Paid Leave",
     *         "type": "Annual",
     *         "code": "PL",
     *         "max_allowed_days": 20,
     *         "is_paid": true
     *       }
     *     ]
     *   },
     *   "message": "Leave Type fetched successfully"
     * }
     *
     * @response 500 {
     *   "message": "An error occurred while fetching leave types."
     * }
     */
    public function index()
    {
        try {
            $leaveTypes = LeaveType::paginate(5);

            return response()->json(['data' => $leaveTypes, 'message' => 'Leave Type fetched successfully'], 200);
            // $leaveTypes = LeaveType::all();
            // return response()->json(['data' => $leaveTypes, 'message' => 'Leave Type fetched successfully'], 200);
        } catch (Exception $e) {
            return response()->json(['message' => 'An error occurred while fetching leave types.'], 500);
        }
    }

    /**
     * Get Pending Leaves of All Employees
     *
     * Fetch leave balances for all employees and calculate:
     * - Pending Paid Leaves
     * - Pending Comp-Off Leaves
     *
     * Includes:
     * - Employee details (name, department, designation)
     * - Leave type info
     *
     * @group Leave Management
     *
     * @authenticated
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "user": {
     *         "id": 1,
     *         "first_name": "John",
     *         "last_name": "Doe",
     *         "department": { "id": 1, "name": "HR" },
     *         "designation": { "id": 2, "name": "Manager" }
     *       },
     *       "pending_paid_leave": 10,
     *       "pending_compoff": 2,
     *       "carry_forward": 0
     *     }
     *   ],
     *   "message": "Pending leaves calculated successfully"
     * }
     *
     * @response 500 {
     *   "message": "An error occurred while fetching leave types."
     * }
     */
    public function showPendingLeavesOfAllEmployees()
    {
        try {

            $leaveBalances = LeaveBalance::with([
                'user:id,first_name,last_name,office_id,department_id,designation_id',
                'user.department:id,name',
                'user.designation:id,name',
                'leaveType:id,name,is_paid,code'
            ])->get();

            // Group leave balances by user
            $leavesByUser = $leaveBalances->groupBy('user_id')->map(function ($balances, $userId) {
                $user = $balances->first()->user;

                // Calculate pending leaves by type
                $pendingPaidLeave = $balances
                    ->where('leave_type_id', 1) // Paid Leave
                    ->sum(fn($b) => $b->total_allocated - $b->used_days);

                $pendingCompOff = $balances
                    ->where('leave_type_id', 2) // Comp-off
                    ->sum(fn($b) => $b->total_allocated - $b->used_days);

                $carryForward = $balances
                    ->where('leave_type_id', 1)
                    ->sum(fn($b) => $b->carry_forward_days ?? 0);


                return [
                    'user' => $user,
                    'pending_paid_leave' => $pendingPaidLeave,
                    'pending_compoff' => $pendingCompOff,
                    'carry_forward' => $carryForward,
                    // 'leave_balances' => $balancesData
                ];
            });

            return response()->json([
                'data' => $leavesByUser,
                'message' => 'Pending leaves calculated successfully'
            ], 200);
        } catch (Exception $e) {
            return response()->json(['message' => 'An error occurred while fetching leave types.'], 500);
        }
    }

    /**
     * Create Leave Type
     *
     * Create a new leave type with configuration details.
     *
     * @group Leave Type Management
     *
     * @authenticated
     *
     * @bodyParam name string required Leave type name. Example: "Sick Leave"
     * @bodyParam type string required Leave category/type. Example: "Medical"
     * @bodyParam code string optional Short code. Example: "SL"
     * @bodyParam max_allowed_days integer required Maximum allowed days. Example: 10
     * @bodyParam is_paid boolean required Whether leave is paid. Example: true
     *
     * @response 201 {
     *   "message": "Leave type created successfully",
     *   "leaveType": {
     *     "id": 1,
     *     "name": "Sick Leave",
     *     "type": "Medical",
     *     "code": "SL",
     *     "max_allowed_days": 10,
     *     "is_paid": true
     *   }
     * }
     *
     * @response 422 {
     *   "message": "Validation failed",
     *   "errors": {
     *     "name": ["The name field is required."]
     *   }
     * }
     *
     * @response 500 {
     *   "message": "Something went wrong"
     * }
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'              => 'required|string|max:255',
                'type'              => 'required|string|max:255',
                'code'              => 'nullable|string|max:50',
                'max_allowed_days'  => 'required|integer|min:0|max:999',
                'is_paid' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $leaveType = LeaveType::create($validator->validated());

            return response()->json([
                'message'   => 'Leave type created successfully',
                'leaveType' => $leaveType
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong',
                // 'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update Leave Type
     *
     * Update an existing leave type.
     *
     * @group Leave Type Management
     *
     * @authenticated
     *
     * @bodyParam id integer required Leave type ID. Example: 1
     * @bodyParam name string optional Leave type name. Example: "Updated Leave"
     * @bodyParam type string optional Leave category/type. Example: "Annual"
     * @bodyParam code string optional Short code. Example: "UL"
     * @bodyParam max_allowed_days integer optional Maximum allowed days. Example: 15
     * @bodyParam is_paid boolean optional Whether leave is paid. Example: true
     *
     * @response 200 {
     *   "message": "Leave type updated successfully",
     *   "leaveType": {
     *     "id": 1,
     *     "name": "Updated Leave"
     *   }
     * }
     *
     * @response 422 {
     *   "message": "Validation failed"
     * }
     *
     * @response 500 {
     *   "message": "Something went wrong"
     * }
     */
    public function update(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id'                => 'required|integer|exists:leave_types,id',
                'name'              => 'sometimes|required|string|max:255',
                'type'              => 'sometimes|required|string|max:255',
                'code'              => 'nullable|string|max:50',
                'max_allowed_days'  => 'sometimes|required|integer|min:0|max:999',
                'is_paid' => 'sometimes|required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors()
                ], 422);
            }
            $leaveType = LeaveType::findOrFail($request->id);
            $leaveType->update($validator->validated());

            return response()->json([
                'message'   => 'Leave type updated successfully',
                'leaveType' => $leaveType
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong',
                // 'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete Leave Type
     *
     * Delete a leave type by its ID.
     * @group Leave Type Management
     *
     * @authenticated
     *
     * @urlParam id integer required Leave type ID. Example: 1
     *
     * @response 200 {
     *   "message": "Leave type deleted successfully"
     * }
     *
     * @response 404 {
     *   "message": "Leave type not found"
     * }
     *
     * @response 500 {
     *   "message": "Something went wrong"
     * }
     */
    public function destroy($id)
    {
        try {
            $leaveType = LeaveType::findOrFail($id);
            $leaveType->delete();

            return response()->json([
                'message' => 'Leave type deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
