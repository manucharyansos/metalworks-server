<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            // Do not transform identifiers with an unstable or empty key.
            // A normal Laravel production deployment should always have APP_KEY.
            return;
        }

        DB::table('visitors')
            ->select(['id', 'ip'])
            ->whereNotNull('ip')
            ->orderBy('id')
            ->chunkById(500, function ($visitors) use ($appKey): void {
                foreach ($visitors as $visitor) {
                    $ip = (string) $visitor->ip;

                    // Only transform rows that still contain an actual IPv4 or
                    // IPv6 address. Already-anonymized fingerprints are left as-is.
                    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                        continue;
                    }

                    $fingerprint = rtrim(strtr(
                        base64_encode(hash_hmac('sha256', $ip, $appKey, true)),
                        '+/',
                        '-_'
                    ), '=');

                    DB::table('visitors')
                        ->where('id', $visitor->id)
                        ->update(['ip' => $fingerprint]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally irreversible: raw IP addresses cannot and should not
        // be reconstructed from the keyed fingerprints.
    }
};
