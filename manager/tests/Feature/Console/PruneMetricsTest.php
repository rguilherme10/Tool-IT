<?php

namespace Tests\Feature\Console;

use App\Models\Endpoint;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PruneMetricsTest extends TestCase
{
    private function insert(Endpoint $endpoint, string $sampledAt): void
    {
        DB::table('metric_samples')->insert([
            'endpoint_id' => $endpoint->id, 'sample_id' => (string) Str::uuid(), 'sampled_at' => $sampledAt,
            'received_at' => $sampledAt, 'mem_total_mb' => 1, 'mem_available_mb' => 1, 'uptime_seconds' => 1, 'volumes' => '[]',
        ]);
    }

    public function test_prunes_only_samples_older_than_retention(): void
    {
        $endpoint = Endpoint::factory()->create();
        $this->insert($endpoint, now()->subDays(8)->format('Y-m-d H:i:s'));
        $this->insert($endpoint, now()->subDays(7)->subMinute()->format('Y-m-d H:i:s'));
        $this->insert($endpoint, now()->subDays(6)->format('Y-m-d H:i:s'));
        $this->insert($endpoint, now()->format('Y-m-d H:i:s'));

        $this->artisan('metrics:prune')->expectsOutputToContain('removidas: 2')->assertSuccessful();

        $this->assertSame(2, DB::table('metric_samples')->count());
    }

    public function test_days_option_overrides_configuration_and_rejects_invalid_values(): void
    {
        $endpoint = Endpoint::factory()->create();
        $this->insert($endpoint, now()->subDays(2)->format('Y-m-d H:i:s'));

        $this->artisan('metrics:prune', ['--days' => 1])->assertSuccessful();
        $this->assertSame(0, DB::table('metric_samples')->count());

        $this->artisan('metrics:prune', ['--days' => -3])->assertFailed();
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'metrics:prune'));

        $this->assertNotNull($scheduled);
        $this->assertSame('15 3 * * *', $scheduled->expression);
    }

    public function test_deleting_an_endpoint_cascades_its_history(): void
    {
        $endpoint = Endpoint::factory()->create();
        $this->insert($endpoint, now()->format('Y-m-d H:i:s'));

        $endpoint->forceDelete();

        $this->assertSame(0, DB::table('metric_samples')->count());
    }
}
