<?php

namespace App\Services\Agents;

use InvalidArgumentException;

/**
 * Chave pública ECDSA P-256 do Agent (SubjectPublicKeyInfo DER em Base64, como exporta o .NET
 * `ECDsa.ExportSubjectPublicKeyInfo()`).
 */
final class PublicKey
{
    private function __construct(
        public readonly string $base64Der,
        public readonly string $pem,
        public readonly string $fingerprint,
    ) {}

    /** @throws InvalidArgumentException se não for uma chave EC P-256 válida */
    public static function fromBase64Der(string $base64): self
    {
        $der = base64_decode($base64, true);
        if ($der === false || $der === '' || strlen($der) > 512) {
            throw new InvalidArgumentException('Chave pública inválida.');
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new InvalidArgumentException('Chave pública inválida.');
        }

        $details = openssl_pkey_get_details($key);
        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new InvalidArgumentException('A chave deve ser ECDSA P-256.');
        }

        return new self(base64_encode($der), $pem, hash('sha256', $der));
    }
}
