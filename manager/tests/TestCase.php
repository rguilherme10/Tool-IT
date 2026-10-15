<?php

namespace Tests;

use App\Enums\RoleKey;
use App\Models\AgentInstallation;
use App\Models\Endpoint;
use App\Models\EndpointGroup;
use App\Models\User;
use App\Services\Agents\EnrollmentService;
use App\Services\Users\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeAgent;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Trava de segurança: RefreshDatabase APAGA o banco. Só roda em SQLite em memória ou em um banco cujo nome
     * contenha "test" (perfil MySQL de testes). Uma variável DB_DATABASE herdada do shell já apagou um banco de
     * E2E uma vez — nunca mais.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $default = config('database.default');
        $database = (string) config("database.connections.{$default}.database");

        if (! ($default === 'sqlite' && $database === ':memory:') && ! str_contains(strtolower($database), 'test')) {
            throw new \RuntimeException("Recusado: os testes apagariam o banco '{$database}' ({$default}). Use :memory: ou um banco de teste.");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Marca o TOTP como ativo e confirmado (para passar pelo middleware de MFA obrigatório). */
    protected function withTwoFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['aaaaaaaaaa-bbbbbbbbbb'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    /** Agent simulado já registrado via fluxo real de enrollment. */
    protected function enrolledAgent(?EndpointGroup $group = null): FakeAgent
    {
        $admin = $this->userWithRole(RoleKey::Admin);
        [, $plain] = app(EnrollmentService::class)->issueToken($admin, $group);

        $agent = new FakeAgent($this);
        $agent->enroll($plain)->assertCreated();

        return $agent;
    }

    /** Computador ativo com instalação de agente ativa (sem criptografia real: para testes de regras/UI). */
    protected function activeEndpoint(?EndpointGroup $group = null, array $attributes = []): Endpoint
    {
        $endpoint = Endpoint::factory()->create($attributes + [
            'endpoint_group_id' => $group?->id, 'last_seen_at' => now(), 'agent_version' => '0.1.0',
        ]);
        AgentInstallation::create([
            'endpoint_id' => $endpoint->id, 'public_key' => 'x', 'key_fingerprint' => hash('sha256', Str::random(16)),
            'status' => 'active', 'enrolled_at' => now(),
        ]);

        return $endpoint;
    }

    /**
     * Insere amostras de métricas. Cada item: [minutosAtrás, cpu%, memória usada %, disco usado % de C:].
     * Opcionalmente um 5º item: lista completa de volumes (substitui o C:).
     *
     * @param  list<array{0: float|int, 1: ?float, 2: float, 3: float, 4?: list<array{mount: string, total_gb: float, free_gb: float}>}>  $rows
     */
    protected function insertSamples(Endpoint $endpoint, array $rows): void
    {
        foreach ($rows as $row) {
            $at = now()->subSeconds((int) round($row[0] * 60))->utc()->format('Y-m-d H:i:s');
            DB::table('metric_samples')->insert([
                'endpoint_id' => $endpoint->id, 'sample_id' => (string) Str::uuid(), 'sampled_at' => $at, 'received_at' => $at,
                'cpu_percent' => $row[1], 'mem_total_mb' => 10000, 'mem_available_mb' => (int) round(10000 * (1 - $row[2] / 100)),
                'uptime_seconds' => 1000,
                'volumes' => json_encode($row[4] ?? [['mount' => 'C:', 'total_gb' => 100, 'free_gb' => 100 - $row[3]]]),
            ]);
        }
    }

    /** Série constante: amostras a cada minuto, de $from até $to minutos atrás (inclusive). */
    protected function steady(Endpoint $endpoint, int $from, int $to, ?float $cpu, float $mem = 40, float $disk = 40): void
    {
        $this->insertSamples($endpoint, array_map(fn ($m) => [$m, $cpu, $mem, $disk], range($from, $to)));
    }

    /** Usuário ativo com um papel (global ou limitado a um grupo). */
    protected function userWithRole(RoleKey $role, ?EndpointGroup $group = null): User
    {
        $user = User::factory()->create();
        app(UserService::class)->assignRole($user, $role, $group);

        return $user;
    }
}
