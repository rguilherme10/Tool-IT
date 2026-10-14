<?php

namespace Tests\Feature\Api\Agent;

use App\Models\AgentInstallation;
use App\Models\MetricSample;
use Illuminate\Support\Str;
use Tests\Support\Payloads;
use Tests\TestCase;

class MetricsIngestTest extends TestCase
{
    private const URL = '/api/agent/v1/metrics';

    public function test_batch_is_stored(): void
    {
        $agent = $this->enrolledAgent();

        $agent->post(self::URL, ['samples' => [Payloads::sample(), Payloads::sample(['cpu_percent' => null])]])
            ->assertStatus(202)->assertExactJson(['accepted' => 2, 'duplicates' => 0, 'rejected' => 0]);

        $sample = MetricSample::orderBy('id')->firstOrFail();
        $this->assertSame(12.5, $sample->cpu_percent);
        $this->assertSame(16384, $sample->mem_total_mb);
        $this->assertSame('C:', $sample->volumes[0]['mount']);
        $this->assertNull(MetricSample::orderByDesc('id')->firstOrFail()->cpu_percent);
    }

    public function test_resending_samples_is_idempotent_and_partial_duplicates_are_counted(): void
    {
        $agent = $this->enrolledAgent();
        $first = Payloads::sample();

        $agent->post(self::URL, ['samples' => [$first]])->assertStatus(202)->assertJson(['accepted' => 1]);
        $agent->post(self::URL, ['samples' => [$first, Payloads::sample()]])->assertStatus(202)
            ->assertExactJson(['accepted' => 1, 'duplicates' => 1, 'rejected' => 0]);

        $this->assertSame(2, MetricSample::count());
    }

    public function test_samples_outside_the_time_window_are_rejected_individually(): void
    {
        $agent = $this->enrolledAgent();

        $agent->post(self::URL, ['samples' => [
            Payloads::sample(),
            Payloads::sample(['at' => now()->subDays(8)->toIso8601String()]),   // além da retenção de 7 dias
            Payloads::sample(['at' => now()->addHour()->toIso8601String()]),     // relógio no futuro
        ]])->assertStatus(202)->assertExactJson(['accepted' => 1, 'duplicates' => 0, 'rejected' => 2]);
    }

    public function test_validation_rejects_bad_samples(): void
    {
        $agent = $this->enrolledAgent();
        $bad = [
            ['samples' => []],
            ['samples' => array_fill(0, 121, null)],
            ['samples' => [Payloads::sample(['sample_id' => 'x'])]],
            ['samples' => [Payloads::sample(['cpu_percent' => 150])]],
            ['samples' => [Payloads::sample(['cpu_percent' => -1])]],
            ['samples' => [Payloads::sample(['mem_total_mb' => -5])]],
            ['samples' => [Payloads::sample(['uptime_seconds' => 'muito'])]],
            ['samples' => [Payloads::sample(['volumes' => [['mount' => 'C:', 'total_gb' => -1, 'free_gb' => 1]]])]],
            ['samples' => [Payloads::sample(['volumes' => array_fill(0, 33, ['mount' => 'C:', 'total_gb' => 1, 'free_gb' => 1])])]],
            ['samples' => [Payloads::sample(['temperature_c' => 999])]],
            ['samples' => [Payloads::sample(['at' => 'ontem'])]],
        ];

        foreach ($bad as $i => $payload) {
            $agent->post(self::URL, $payload)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        }
        $this->assertSame(0, MetricSample::count());
    }

    public function test_a_full_batch_of_120_is_accepted(): void
    {
        $agent = $this->enrolledAgent();
        $samples = array_map(fn () => Payloads::sample(['sample_id' => (string) Str::uuid()]), range(1, 120));

        $agent->post(self::URL, ['samples' => $samples])->assertStatus(202)->assertJson(['accepted' => 120]);
    }

    public function test_samples_are_attributed_to_the_authenticated_endpoint_only(): void
    {
        $a = $this->enrolledAgent();
        $b = $this->enrolledAgent();
        $a->post(self::URL, ['samples' => [Payloads::sample()]])->assertStatus(202);

        $endpointB = AgentInstallation::where('uuid', $b->agentId)->firstOrFail()->endpoint;
        $this->assertSame(0, MetricSample::where('endpoint_id', $endpointB->id)->count());
    }

    public function test_same_sample_id_from_two_endpoints_does_not_collide(): void
    {
        $a = $this->enrolledAgent();
        $b = $this->enrolledAgent();
        $sample = Payloads::sample();

        $a->post(self::URL, ['samples' => [$sample]])->assertJson(['accepted' => 1]);
        $b->post(self::URL, ['samples' => [$sample]])->assertJson(['accepted' => 1]);
    }
}
