<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Shift;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;

/**
 * Compact CRUD for the organisational sub-entities (branches, departments, teams,
 * designations, shifts). All are company-scoped automatically via the tenant trait,
 * so listing/creating is bounded to the caller's company. Routed with a {type} param.
 */
class OrgController extends Controller
{
    private const MAP = [
        'branches'     => Branch::class,
        'departments'  => Department::class,
        'teams'        => Team::class,
        'designations' => Designation::class,
        'shifts'       => Shift::class,
    ];

    public function index(Request $request, string $type): JsonResponse
    {
        return response()->json(['data' => $this->model($type)::query()->latest('id')->get()]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $class = $this->model($type);
        $data = $request->validate($this->rules($type));
        /** @var Model $row */
        $row = $class::create($data); // company_id auto-filled by BelongsToCompany
        $this->audit($request, 'CREATE', $class, $row->id, $data);

        return response()->json(['data' => $row], 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $class = $this->model($type);
        $row = $class::findOrFail($id);
        $data = $request->validate($this->rules($type, false));
        $row->update($data);
        $this->audit($request, 'UPDATE', $class, $row->id, $data);

        return response()->json(['data' => $row]);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $class = $this->model($type);
        $row = $class::findOrFail($id);
        $row->delete();
        $this->audit($request, 'DELETE', $class, $id);

        return response()->json(null, 204);
    }

    private function model(string $type): string
    {
        abort_unless(isset(self::MAP[$type]), 404, "Unknown org resource: {$type}");
        return self::MAP[$type];
    }

    private function rules(string $type, bool $creating = true): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $base = ['name' => [$req, 'string', 'max:255'], 'code' => ['nullable', 'string', 'max:64']];

        return match ($type) {
            'branches' => $base + [
                'city' => ['nullable', 'string'], 'state' => ['nullable', 'string'],
                'country' => ['nullable', 'string'], 'public_ip_whitelist' => ['nullable', 'array'],
            ],
            'departments' => $base + ['branch_id' => ['nullable', 'integer', 'exists:branches,id']],
            'teams' => $base + [
                'department_id' => ['nullable', 'integer', 'exists:departments,id'],
                'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
                'team_leader_user_id' => ['nullable', 'integer', 'exists:users,id'],
            ],
            'designations' => $base + ['level' => ['nullable', 'integer', 'min:0']],
            'shifts' => $base + [
                'start_time' => ['nullable', 'date_format:H:i:s'],
                'end_time' => ['nullable', 'date_format:H:i:s'],
                'grace_minutes' => ['nullable', 'integer', 'min:0'],
                'working_days' => ['nullable', 'array'],
                'break_minutes_allowed' => ['nullable', 'integer', 'min:0'],
            ],
            default => $base,
        };
    }
}
