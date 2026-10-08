<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyMembership;
use App\Models\MembershipAssignment;
use Closure;

final class CompanyContext
{
    private ?Company $company = null;
    private array $memberships = [];
    private ?MembershipAssignment $assignment = null;

    public function id(): ?int { return $this->company?->id; }
    public function company(): ?Company { return $this->company; }
    public function set(?Company $company): void { $this->company = $company; $this->memberships = []; $this->assignment = null; }
    public function selectAssignment(?MembershipAssignment $assignment): void { $this->assignment = $assignment; }
    public function assignment(int $userId): ?MembershipAssignment { return $this->assignment?->user_id === $userId ? $this->assignment : null; }
    public function forgetMembership(int $userId): void { unset($this->memberships[$userId]); }

    public function membership(int $userId): ?CompanyMembership
    {
        if (!$this->id()) return null;
        if (!array_key_exists($userId, $this->memberships)) {
            $this->memberships[$userId] = CompanyMembership::query()
                ->where('company_id', $this->id())->where('user_id', $userId)->first();
        }
        return $this->memberships[$userId];
    }

    public function run(Company $company, Closure $callback): mixed
    {
        $previous = $this->company;
        $memberships = $this->memberships;
        $assignment = $this->assignment;
        $this->set($company);
        try { return $callback(); }
        finally { $this->company = $previous; $this->memberships = $memberships; $this->assignment = $assignment; }
    }
}
