<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['endpoint_id', 'snapshot_id', 'collected_at', 'received_at', 'section_status', 'changes', 'payload_hash'])]
class InventorySnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'received_at' => 'datetime',
            'section_status' => 'array',
            'changes' => 'array',
        ];
    }
}
