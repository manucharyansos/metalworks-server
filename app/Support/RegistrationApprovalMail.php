<?php

namespace App\Support;

use App\Mail\RegistrationApproved;
use App\Models\{Company, RegistrationRequest};
use Illuminate\Support\Facades\{DB, Log, Mail};
use Throwable;

final class RegistrationApprovalMail
{
    public function send(RegistrationRequest $application): string
    {
        $claimed = DB::transaction(function () use ($application): bool {
            $row = RegistrationRequest::whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($row->status !== 'approved' || $row->notification_status === 'sent'
                || ($row->notification_status === 'sending' && $row->updated_at->greaterThan(now()->subSeconds(30)))) return false;
            $row->update(['notification_status' => 'sending']);
            return true;
        });
        if (!$claimed) return $application->fresh()->notification_status ?? 'pending';

        $status = 'unconfigured';
        if ($this->delivers(config('mail.default')) && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) {
            try {
                $company = Company::findOrFail($application->company_id);
                Mail::to($application->email)->send(new RegistrationApproved($application->name, $company->name, $application->locale));
                $status = 'sent';
            } catch (Throwable $error) {
                $status = 'failed';
                // No addresses, passwords or SMTP credentials in this event.
                Log::warning('Registration approval email failed', ['request_id' => $application->id, 'exception' => $error::class]);
            }
        }
        $application->fresh()->update(['notification_status' => $status, 'notification_sent_at' => $status === 'sent' ? now() : null]);
        return $status;
    }

    private function delivers(?string $name, array $visited = []): bool
    {
        if (!$name || in_array($name, $visited, true)) return false;
        $mailer = config('mail.mailers.' . $name, []);
        if (in_array($mailer['transport'] ?? null, ['failover', 'roundrobin'], true)) {
            $children = $mailer['mailers'] ?? [];
            if (!$children) return false;
            foreach ($children as $child) if (!$this->delivers($child, [...$visited, $name])) return false;
            return true;
        }
        return in_array($mailer['transport'] ?? null, ['smtp', 'sendmail', 'ses', 'postmark', 'mailgun'], true);
    }
}
