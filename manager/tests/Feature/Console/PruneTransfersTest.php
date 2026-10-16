<?php

namespace Tests\Feature\Console;

use App\Enums\TransferStatus;
use App\Models\FileTransfer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneTransfersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function transferWithBlob(TransferStatus $status, int $daysAgo, array $attributes = []): FileTransfer
    {
        $t = FileTransfer::factory()->create(['status' => $status] + $attributes);
        Storage::disk('local')->put('transfers/'.$t->uuid.'/file.bin', 'conteudo');
        Storage::disk('local')->put('transfers/'.$t->uuid.'/up/0', 'parte');
        FileTransfer::query()->whereKey($t->id)->update(['updated_at' => now()->subDays($daysAgo)]);

        return $t->fresh();
    }

    public function test_prunes_blobs_of_old_finished_transfers_and_keeps_metadata(): void
    {
        $old = [];
        foreach ([TransferStatus::Completed, TransferStatus::Failed, TransferStatus::Cancelled, TransferStatus::Expired] as $status) {
            $old[] = $this->transferWithBlob($status, 8);
        }
        $recent = $this->transferWithBlob(TransferStatus::Completed, 6);
        $active = [
            $this->transferWithBlob(TransferStatus::Ready, 30),
            $this->transferWithBlob(TransferStatus::Transferring, 30),
            $this->transferWithBlob(TransferStatus::Verifying, 30),
            $this->transferWithBlob(TransferStatus::Uploading, 30, ['expires_at' => now()->addHour()]),
        ];

        $this->artisan('transfers:prune')->expectsOutputToContain('apagados: 4')->assertSuccessful();

        foreach ($old as $t) {
            $this->assertSame([], Storage::disk('local')->allFiles('transfers/'.$t->uuid));
            $this->assertNotNull($t->fresh()->purged_at, 'metadados ficam, marcados como apagados');
            $this->assertSame($t->status, $t->fresh()->status);
        }
        $this->assertTrue(Storage::disk('local')->exists('transfers/'.$recent->uuid.'/file.bin'));
        $this->assertNull($recent->fresh()->purged_at);
        foreach ($active as $t) {
            $this->assertTrue(Storage::disk('local')->exists('transfers/'.$t->uuid.'/file.bin'));
            $this->assertSame($t->status, $t->fresh()->status);
        }
        $this->assertSame(9, FileTransfer::query()->count());

        // Segunda execução: nada a fazer.
        $this->artisan('transfers:prune')->expectsOutputToContain('apagados: 0')->assertSuccessful();
    }

    public function test_abandoned_uploads_expire(): void
    {
        $abandoned = $this->transferWithBlob(TransferStatus::Uploading, 2, ['expires_at' => now()->subMinute()]);

        $this->artisan('transfers:prune')->assertSuccessful();

        $this->assertSame(TransferStatus::Expired, $abandoned->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transfer.failed']);
    }

    public function test_days_option_and_invalid_value(): void
    {
        $t = $this->transferWithBlob(TransferStatus::Completed, 2);

        $this->artisan('transfers:prune', ['--days' => 1])->assertSuccessful();
        $this->assertNotNull($t->fresh()->purged_at);

        $this->artisan('transfers:prune', ['--days' => 0])->assertFailed();
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'transfers:prune'));

        $this->assertNotNull($scheduled);
        $this->assertSame('45 3 * * *', $scheduled->expression);
    }
}
