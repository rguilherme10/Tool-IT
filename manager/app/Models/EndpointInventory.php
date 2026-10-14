<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['endpoint_id', 'system', 'hardware', 'peripherals', 'section_status', 'last_snapshot_id', 'collected_at'])]
class EndpointInventory extends Model
{
    /** Seções persistidas como JSON no estado atual. */
    public const JSON_SECTIONS = ['system', 'hardware', 'peripherals'];

    protected function casts(): array
    {
        return [
            'system' => 'array',
            'hardware' => 'array',
            'peripherals' => 'array',
            'section_status' => 'array',
            'collected_at' => 'datetime',
        ];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }
}
