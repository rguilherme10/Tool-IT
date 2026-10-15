<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** O IP do cliente alimenta limites de taxa, bloqueio de login e auditoria: só pode vir de X-Forwarded-For de um proxy confiável. */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/_probe', fn (Request $r) => response()->json(['ip' => $r->ip(), 'secure' => $r->isSecure(), 'host' => $r->getHost()]));
    }

    public function test_forwarded_headers_are_honoured_from_a_trusted_proxy(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'toolit.empresa.local'])
            ->getJson('/_probe');

        $response->assertOk()->assertJson(['ip' => '203.0.113.7', 'secure' => true, 'host' => 'toolit.empresa.local']);
    }

    public function test_forwarded_headers_are_ignored_from_anyone_else(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.7', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_probe');

        $response->assertOk()->assertJson(['ip' => '198.51.100.9', 'secure' => false]);
    }
}
