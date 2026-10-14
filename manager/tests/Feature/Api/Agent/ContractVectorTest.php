<?php

namespace Tests\Feature\Api\Agent;

use App\Services\Agents\PublicKey;
use App\Services\Agents\SignatureVerifier;
use Tests\TestCase;

/**
 * Vetor compartilhado com o Agent C# (tests/contracts/signature-vector.json).
 * O Manager e o Agent devem concordar byte a byte na string canônica e na verificação.
 */
class ContractVectorTest extends TestCase
{
    /** @return array<string, mixed> */
    private function vector(): array
    {
        return json_decode(file_get_contents(base_path('../tests/contracts/signature-vector.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_canonical_string_matches_the_shared_vector(): void
    {
        $v = $this->vector();
        $r = $v['request'];

        $canonical = app(SignatureVerifier::class)->canonical($r['method'], $r['path'], $r['query'], $r['timestamp'], $r['nonce'], $r['body']);

        $this->assertSame($v['canonical_string'], $canonical);
    }

    public function test_shared_vector_signature_verifies_with_the_published_key(): void
    {
        $v = $this->vector();
        $verifier = app(SignatureVerifier::class);
        $key = PublicKey::fromBase64Der($v['public_key_spki_der_base64']);

        $this->assertTrue($verifier->verify($key, $v['canonical_string'], $v['signature_der_base64']));
        $this->assertFalse($verifier->verify($key, $v['canonical_string'].'x', $v['signature_der_base64']), 'qualquer alteração invalida');
    }
}
