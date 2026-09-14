<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use ParagonIE\ConstantTime\Base32;
use PragmaRX\Google2FA\Google2FA;

/**
 * Admin-only TOTP. The secret is never stored: it is derived from the admin's
 * id and APP_KEY, so there is nothing in the database to leak and nothing to
 * migrate. APP_KEY is `sync: false` in render.yaml, so the secret survives
 * deploys; rotating the key re-enrolls every admin, which is an accepted
 * consequence rather than a bug.
 *
 * Enrollment status lives in cache, not a column, because tbl_user has no
 * spare one and adding it was deferred. Cache::forever survives a normal
 * request cycle but not `cache:clear` — a cleared cache re-shows the QR to
 * anyone who knows the password until the next successful code re-earns it.
 */
class Totp
{
    private function engine(): Google2FA
    {
        return new Google2FA;
    }

    public function secretFor(int $adminId): string
    {
        // Truncated to 20 bytes (160 bits) on purpose, not just hashed and used
        // whole: google2fa additionally requires the base32 secret's *character
        // count* be a power of two for Google Authenticator compatibility. Only
        // byte lengths that are multiples of 5 base32-encode with no padding,
        // and of those, 20 bytes is the one that lands on a power-of-two length
        // (32 chars) while still clearing the "big enough" floor comfortably.
        $bytes = substr(hash_hmac('sha256', 'totp:'.$adminId, config('app.key'), true), 0, 20);

        return Base32::encodeUpper($bytes);
    }

    public function verify(int $adminId, string $code): bool
    {
        return (bool) $this->engine()->verifyKey($this->secretFor($adminId), $code);
    }

    public function provisioningUri(int $adminId, string $email): string
    {
        $secret = $this->secretFor($adminId);
        $issuer = rawurlencode('SERBIS Admin');
        $label = rawurlencode('SERBIS Admin:'.$email);

        return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }

    public function qrCodeDataUri(string $otpauthUri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString($otpauthUri);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function isEnrolled(int $adminId): bool
    {
        return (bool) Cache::get("mfa:admin-enrolled:{$adminId}", false);
    }

    public function markEnrolled(int $adminId): void
    {
        Cache::forever("mfa:admin-enrolled:{$adminId}", true);
    }
}
