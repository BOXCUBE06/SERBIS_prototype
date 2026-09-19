<?php

namespace App\Services;

use App\Models\DeviceToken;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The single place this application talks to FCM. HTTP v1 only — there is no
 * legacy server-key API left to fall back to.
 *
 * Auth is a Google service-account OAuth2 flow, not a static token: the
 * credentials file is exchanged for a short-lived bearer token, which is
 * cached so a burst of sends does not mint (and sign) a fresh one per call.
 *
 * sendToDevice() is the best-effort boundary itself: it never throws, and it
 * is the thing that decides a dead token gets deleted.
 */
class Fcm
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_KEY = 'fcm_access_token';

    /**
     * Comfortably inside a real token's ~3600s life, leaving margin for
     * clock drift and the time between minting it and using it.
     */
    private const TOKEN_CACHE_SECONDS = 3000;

    /**
     * A developer machine has no credentials file. Without this the send
     * path would attempt a real request on every local run and fail.
     */
    public static function configured(): bool
    {
        $path = config('services.firebase.credentials');

        return filled($path) && is_string($path) && file_exists($path);
    }

    /**
     * Pushes a title/body to every device a resident has registered.
     * Walk-in requests carry no resident_id and are silently skipped —
     * there is no account to push to.
     *
     * Extracted from ServiceRequestController::notifyResidentDevices() so a
     * second controller (equipment borrowing) can reuse it instead of
     * duplicating the device-token lookup.
     *
     * $data is the FCM data payload — string values only, FCM's own
     * requirement. Lets a caller identify what the push was about
     * (request_id, service_type) without putting either in the visible
     * title/body. No client reads this yet; nothing in the app deep-links
     * on it.
     */
    public function notifyResident(?int $residentId, string $title, string $body, array $data = []): void
    {
        if ($residentId === null) {
            return;
        }

        DeviceToken::where('resident_id', $residentId)
            ->get()
            ->each(fn (DeviceToken $deviceToken) => $this->sendToDevice($deviceToken, $title, $body, $data));
    }

    /**
     * Pushes a title/body to every device any resident has registered —
     * unscoped, unlike notifyResident(). Built for the info-materials
     * publish notice (MDRRMO feedback, 2026-09-19): a new safety material is
     * for every resident, not one. Each device is sent to independently, so
     * one dead or rejected token never stops the rest of the broadcast —
     * same isolation sendToDevice() already gives a per-resident push.
     */
    public function notifyAllResidents(string $title, string $body, array $data = []): void
    {
        DeviceToken::all()
            ->each(fn (DeviceToken $deviceToken) => $this->sendToDevice($deviceToken, $title, $body, $data));
    }

    /**
     * One push to one device. Best-effort, like every other notification
     * channel in this app: a missing config, a network error, or FCM
     * rejecting the request is logged and swallowed, never thrown — the
     * caller of this method needs no try/catch of its own.
     *
     * FCM reporting the token itself as the problem (unregistered — the app
     * was uninstalled or cleared its storage — or malformed) is the one
     * outcome that changes anything on our side: the row is deleted so
     * nothing keeps sending to a device that will never answer again. Every
     * other failure leaves it alone, since it might still be good next time.
     */
    public function sendToDevice(DeviceToken $deviceToken, string $title, string $body, array $data = []): void
    {
        if (! self::configured()) {
            // The one branch that used to leave zero trace: every other
            // failure path below logs, so a missing credential was
            // indistinguishable from "nothing tried to send" — see
            // docs/mdrrmo-feedback.md item 1's debug notes, 2026-09-18.
            $path = config('services.firebase.credentials');
            Log::warning('FCM push skipped: not configured', [
                'device_token_id' => $deviceToken->getKey(),
                'reason' => filled($path)
                    ? "FIREBASE_CREDENTIALS is set to \"{$path}\" but that file does not exist"
                    : 'FIREBASE_CREDENTIALS is not set',
            ]);

            return;
        }

        try {
            $response = $this->post($deviceToken->token, $title, $body, $data);

            if ($response->successful()) {
                // The one success path that used to leave zero trace, same
                // gap as the "not configured" branch above: nothing here
                // distinguished "FCM accepted this" from "never attempted".
                Log::info('FCM send accepted', [
                    'device_token_id' => $deviceToken->getKey(),
                    'message_name' => $response->json('name'),
                ]);

                return;
            }

            if ($this->tokenIsDead($response)) {
                // Logged before the delete, not after — there's nothing
                // left to log about a row that no longer exists.
                Log::info('FCM device token deleted', [
                    'device_token_id' => $deviceToken->getKey(),
                    'resident_id' => $deviceToken->resident_id,
                    'reason' => $response->json('error.status'),
                ]);

                $deviceToken->delete();

                return;
            }

            Log::warning('FCM send not accepted', [
                'device_token_id' => $deviceToken->getKey(),
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            Log::error('FCM send failed', [
                'device_token_id' => $deviceToken->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function post(string $token, string $title, string $body, array $data = []): Response
    {
        $projectId = $this->credentials()->getProjectId();

        return Http::withToken($this->accessToken())
            ->timeout(6)
            ->connectTimeout(3)
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => [
                    'token' => $token,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                    ],
                    // Omitted entirely when empty rather than sent as {} — an
                    // absent key and an empty map should not be two different
                    // wire shapes for the same "nothing extra" case.
                    ...($data === [] ? [] : ['data' => $data]),
                ],
            ]);
    }

    /**
     * FCM's own vocabulary for "this token will never work again" —
     * https://firebase.google.com/docs/reference/fcm/rest/v1/ErrorCode.
     * Every other error (UNAVAILABLE, INTERNAL, a bad access token, a
     * network failure) is transient and must not delete a token that could
     * still be good on the next attempt.
     */
    private function tokenIsDead(Response $response): bool
    {
        $status = $response->json('error.status');

        return in_array($status, ['UNREGISTERED', 'INVALID_ARGUMENT'], true);
    }

    /**
     * Cache::remember() only stores the result once fetchAuthToken() returns
     * — a failure to mint (bad credentials file, network down) propagates
     * instead of caching a broken token, and is caught by sendToDevice().
     */
    private function accessToken(): string
    {
        return Cache::remember(self::CACHE_KEY, self::TOKEN_CACHE_SECONDS, function () {
            return $this->credentials()->fetchAuthToken()['access_token'];
        });
    }

    private function credentials(): ServiceAccountCredentials
    {
        return new ServiceAccountCredentials(self::SCOPE, config('services.firebase.credentials'));
    }
}
