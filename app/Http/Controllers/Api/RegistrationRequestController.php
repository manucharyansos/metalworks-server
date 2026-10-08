<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{Company, CompanyMembership, Factory, RegistrationRequest, Role, User};
use App\Support\CompanyContext;
use App\Support\RegistrationApprovalMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Hash, RateLimiter, Schema};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, ValidationException};

class RegistrationRequestController extends Controller
{
    private const STAFF_ROLES = ['admin', 'manager', 'engineer', 'laser', 'bend', 'powder_catting'];
    private const OPERATOR_ROLES = ['laser', 'bend', 'powder_catting'];

    public function companies(): JsonResponse
    {
        // The registration directory contains only the names needed to apply.
        // Workshops, staff, logos and business records remain authenticated.
        return response()->json([
            'companies' => Company::where('is_active', true)->orderBy('id')->get(['id', 'name']),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('registration_requests'), 503, 'Registration is temporarily unavailable.');
        $key = 'register|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many registration attempts. Please try again later.', 'retry_after' => RateLimiter::availableIn($key)], 429);
        }
        RateLimiter::hit($key, 600);
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
            'is_employee' => $request->input('is_employee', false),
        ]);
        $data = $request->validate([
            'name' => 'required|string|min:1|max:255',
            'last_name' => 'required_if:is_employee,true|nullable|string|max:255',
            'patronymic' => 'nullable|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8|max:255|confirmed',
            'is_employee' => 'required|boolean',
            'job_title' => 'required_if:is_employee,true|nullable|string|max:255',
            'company_id' => 'nullable|integer',
        ]);
        $companies = Company::where('is_active', true);
        if (!empty($data['company_id'])) {
            $company = $companies->find($data['company_id']);
        } else {
            $available = $companies->orderBy('id')->get();
            $company = $available->count() === 1 ? $available->first() : null;
        }
        if (!$company) throw ValidationException::withMessages(['company_id' => ['Select an active company.']]);

        app(CompanyContext::class)->run($company, function () use ($data, $company): void {
            DB::transaction(function () use ($data, $company): void {
                $existing = User::withoutGlobalScope('company')->where('email', $data['email'])->lockForUpdate()->first();
                if ($existing && !Hash::check($data['password'], $existing->password)) {
                    throw ValidationException::withMessages(['email' => ['For an existing account, enter its current password.']]);
                }
                if ($existing && CompanyMembership::where('company_id', $company->id)->where('user_id', $existing->id)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['email' => ['This account already has access to the selected company.']]);
                }
                $employee = (bool) $data['is_employee'];
                $type = $employee ? 'employee' : 'client';
                $application = RegistrationRequest::where('email', $data['email'])->where('type', $type)->lockForUpdate()->first();
                if ($application?->status === 'pending' && !$existing && !Hash::check($data['password'], $application->password_hash ?? '')) {
                    throw ValidationException::withMessages(['email' => ['A request for this email is already awaiting review.']]);
                }
                if ($application?->status === 'approved') {
                    throw ValidationException::withMessages(['email' => ['Please contact the company manager about your existing access.']]);
                }
                $values = [
                    'name' => $data['name'], 'last_name' => $data['last_name'] ?? null,
                    'patronymic' => $data['patronymic'] ?? null, 'email' => $data['email'],
                    'type' => $employee ? 'employee' : 'client', 'job_title' => $employee ? $data['job_title'] : null,
                    'password_hash' => $existing ? null : Hash::make($data['password']),
                    'existing_user_id' => $existing?->id, 'status' => 'pending',
                    'reviewed_by' => null, 'reviewed_at' => null,
                    'locale' => in_array(app()->getLocale(), ['hy', 'ru', 'en'], true) ? app()->getLocale() : 'hy',
                    'notification_status' => null, 'notification_sent_at' => null,
                ];
                $application ? $application->update($values) : RegistrationRequest::create($values);
            }, 3);
        });
        // No identity, token, session or company membership is created here.
        return response()->json(['status' => 'pending', 'message' => 'Ձեր հարցումն ընդունված է։'], 202);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'type' => ['nullable', Rule::in(['employee', 'client'])],
            'page' => 'nullable|integer|min:1',
        ]);
        $query = RegistrationRequest::query()->where('status', $data['status'] ?? 'pending');
        if (!empty($data['type'])) $query->where('type', $data['type']);
        $page = $query->orderByDesc('id')->paginate(20);
        $counts = RegistrationRequest::select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        return response()->json([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => ['pending' => (int) ($counts['pending'] ?? 0), 'approved' => (int) ($counts['approved'] ?? 0), 'rejected' => (int) ($counts['rejected'] ?? 0)],
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json([
            'roles' => Role::whereIn('name', $this->allowedRoles($request))->orderBy('name')->get(['id', 'name', 'value']),
            'factories' => Factory::orderBy('name')->get(['id', 'name', 'value']),
        ]);
    }

    public function approve(Request $request, RegistrationRequest $registrationRequest): JsonResponse
    {
        $employee = $registrationRequest->type === 'employee';
        $data = $request->validate([
            'role_id' => $employee ? ['required', 'integer', Rule::exists('roles', 'id')->where(fn ($q) => $q->whereIn('name', $this->allowedRoles($request)))] : 'prohibited',
            'factory_id' => $employee ? 'nullable|integer|exists:factories,id' : 'prohibited',
        ]);
        $role = $employee ? Role::findOrFail($data['role_id']) : Role::where('name', 'authenticatedUser')->firstOrFail();
        $operator = in_array($role->name, self::OPERATOR_ROLES, true);
        if ($operator && empty($data['factory_id'])) throw ValidationException::withMessages(['factory_id' => ['Select a workshop for this position.']]);
        $user = DB::transaction(function () use ($request, $registrationRequest, $role, $operator, $data, $employee): User {
            $application = RegistrationRequest::whereKey($registrationRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($application->status === 'pending', 409, 'This request has already been reviewed.');
            abort_unless($application->type === $registrationRequest->type, 409, 'The request type has changed. Refresh the list before reviewing it.');
            $user = User::withoutGlobalScope('company')->where('email', $application->email)->lockForUpdate()->first();
            if ($application->existing_user_id) {
                if (!$user || (int) $user->id !== (int) $application->existing_user_id) {
                    throw ValidationException::withMessages(['email' => ['The account has changed. Ask the applicant to submit a new request.']]);
                }
            } elseif ($user || !$application->password_hash) {
                throw ValidationException::withMessages(['email' => ['An account was created after this request. The applicant must resubmit with the current password.']]);
            }
            if ($user && CompanyMembership::where('company_id', $application->company_id)->where('user_id', $user->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['email' => ['This account already has company access.']]);
            }
            $user ??= User::create([
                'name' => $application->name, 'last_name' => $application->last_name,
                'patronymic' => $application->patronymic, 'email' => $application->email,
                'password' => $application->password_hash, 'role_id' => $role->id,
                'factory_id' => $operator ? $data['factory_id'] : null,
            ]);
            $membership = CompanyMembership::updateOrCreate(['company_id' => $application->company_id, 'user_id' => $user->id], [
                'role_id' => $role->id, 'factory_id' => $operator ? $data['factory_id'] : null, 'is_active' => true,
            ]);
            // Reactivating an account must not restore grants from an old job.
            $membership->permissions()->detach();
            app(CompanyContext::class)->forgetMembership($user->id);
            if ($employee) {
                $user->worker()->firstOrCreate([], ['last_name' => $application->last_name, 'phone' => '']);
            } else {
                $user->client()->firstOrCreate([], ['name' => $application->name, 'last_name' => $application->last_name, 'phone' => '', 'type' => 'physPerson']);
            }
            $application->update(['status' => 'approved', 'user_id' => $user->id, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'password_hash' => null, 'notification_status' => 'pending']);
            return $user;
        }, 3);
        // Delivery happens after the account transaction. A mail failure never
        // rolls back approval or reports the account itself as unapproved.
        $notification = app(RegistrationApprovalMail::class)->send($registrationRequest->fresh());
        return response()->json(['status' => 'approved', 'user_id' => $user->id, 'notification_status' => $notification, 'message' => 'Registration approved.']);
    }

    public function notify(Request $request, RegistrationRequest $registrationRequest): JsonResponse
    {
        abort_unless($registrationRequest->status === 'approved', 409, 'Approve the request before sending its notification.');
        abort_unless(Company::whereKey($registrationRequest->company_id)->where('is_active', true)->exists()
            && CompanyMembership::where('company_id', $registrationRequest->company_id)->where('user_id', $registrationRequest->user_id)->where('is_active', true)->exists(), 409, 'Company access is no longer active.');
        return response()->json(['status' => 'approved', 'notification_status' => app(RegistrationApprovalMail::class)->send($registrationRequest)]);
    }

    public function reject(Request $request, RegistrationRequest $registrationRequest): JsonResponse
    {
        DB::transaction(function () use ($request, $registrationRequest): void {
            $application = RegistrationRequest::whereKey($registrationRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($application->status === 'pending', 409, 'This request has already been reviewed.');
            $application->update(['status' => 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'password_hash' => null]);
        }, 3);
        return response()->json(['status' => 'rejected', 'message' => 'Registration request rejected.']);
    }

    private function allowedRoles(Request $request): array
    {
        return $request->user()->is_platform_admin ? self::STAFF_ROLES : array_values(array_diff(self::STAFF_ROLES, ['admin']));
    }
}
