<?php

namespace App\Services\Provisioning;

/**
 * A WireGuard keypair, generated here rather than on the device.
 *
 * The obvious way round would be to let the router make its own private key and read the
 * public one back over the API — which is exactly what cannot be done, because until the
 * tunnel exists there is no API to read it over. That is the whole problem this solves. So
 * the platform generates both halves, writes the private key into the script the operator
 * pastes, and keeps it encrypted at rest.
 *
 * WireGuard keys are Curve25519. The clamping is the part everyone forgets, and an unclamped
 * key produces a public key the other end will never agree with.
 *
 * The scalar multiplication goes through `ParagonIE_Sodium_Compat`, which uses the libsodium
 * extension where it exists and a pure-PHP implementation where it does not. XAMPP ships
 * without ext-sodium, and this platform is proven locally before it is deployed — a feature
 * that only works on the production box is a feature nobody can develop against.
 */
final class WireGuardKeypair
{
    public function __construct(
        public readonly string $privateKey,
        public readonly string $publicKey,
    ) {}

    public static function generate(): self
    {
        $private = random_bytes(32);

        // Curve25519 clamping, as the WireGuard protocol requires: clear the three low bits,
        // clear the top bit, set the second-highest. Skipping this yields a key that looks
        // fine and never completes a handshake.
        $private[0] = chr(ord($private[0]) & 248);
        $private[31] = chr((ord($private[31]) & 127) | 64);

        return new self(
            privateKey: base64_encode($private),
            publicKey: base64_encode(\ParagonIE_Sodium_Compat::crypto_scalarmult_base($private)),
        );
    }

    /**
     * Derive the public half of a private key we already hold.
     */
    public static function publicKeyFor(string $privateKey): ?string
    {
        $decoded = base64_decode($privateKey, true);

        if ($decoded === false || strlen($decoded) !== 32) {
            return null;
        }

        return base64_encode(\ParagonIE_Sodium_Compat::crypto_scalarmult_base($decoded));
    }
}
