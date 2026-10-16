<?php

namespace Database\Factories;

use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use App\Models\Endpoint;
use App\Models\FileTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Somente para testes: transferências reais nascem de App\Services\Transfers\TransferService (validação, política,
 * auditoria).
 *
 * @extends Factory<FileTransfer>
 */
class FileTransferFactory extends Factory
{
    protected $model = FileTransfer::class;

    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'stored_name' => $uuid,
            'endpoint_id' => Endpoint::factory(),
            'direction' => TransferDirection::Send,
            'created_by' => User::factory(),
            'original_name' => 'relatorio.pdf',
            'remote_path' => 'C:\\ProgramData\\Toolit\\Inbox\\relatorio.pdf',
            'size' => 8,
            'chunk_size' => 1048576,
            'chunks_total' => 1,
            'chunks_received' => 1,
            'overwrite' => false,
            'status' => TransferStatus::Ready,
        ];
    }
}
