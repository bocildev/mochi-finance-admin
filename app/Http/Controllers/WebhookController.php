<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Iam\Client\WebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        $rawBody = $request->getContent();
        $timestamp = (string) $request->header('X-IAM-Timestamp', '');
        $signature = (string) $request->header('X-IAM-Signature', '');
        $secret = (string) config('iam.webhook_secret');

        if (! $secret || ! WebhookVerifier::verify($rawBody, $timestamp, $signature, $secret)) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $data = json_decode($rawBody, true) ?: [];
        $eventId = (string) ($data['id'] ?? uniqid('evt_', true));
        $eventType = (string) ($data['type'] ?? 'unknown');
        $payload = $data['data'] ?? $data;

        $now = Carbon::now();
        DB::table('iam_webhook_events')->updateOrInsert(
            ['event_id' => $eventId],
            [
                'event_type' => $eventType,
                'payload' => json_encode($data),
                'processed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $sub = $payload['sub'] ?? $payload['user_sub'] ?? null;
        if ($sub) {
            if ($eventType === 'user.disabled') {
                DB::table('finance_users')->where('sub', $sub)->update([
                    'status' => 'disabled',
                    'updated_at' => $now,
                ]);
            } elseif ($eventType === 'user.updated') {
                $updates = ['updated_at' => $now];
                if (isset($payload['status'])) {
                    $updates['status'] = $payload['status'];
                }
                if (isset($payload['name'])) {
                    $updates['name'] = $payload['name'];
                }
                if (isset($payload['email'])) {
                    $updates['email'] = $payload['email'];
                }
                DB::table('finance_users')->where('sub', $sub)->update($updates);
            } elseif ($eventType === 'role.changed') {
                $updates = ['updated_at' => $now];
                if (isset($payload['roles']) && is_array($payload['roles'])) {
                    $updates['roles'] = json_encode($payload['roles']);
                }
                if (isset($payload['permissions']) && is_array($payload['permissions'])) {
                    $updates['permissions'] = json_encode($payload['permissions']);
                }
                DB::table('finance_users')->where('sub', $sub)->update($updates);
            }
        }

        return response()->json([
            'received' => true,
            'event_id' => $eventId,
            'event_type' => $eventType,
        ]);
    }
}
