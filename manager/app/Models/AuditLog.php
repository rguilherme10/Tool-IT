<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Somente leitura na aplicação. Gravação exclusiva por App\Services\Audit\AuditLogger;
 * UPDATE/DELETE bloqueados também por triggers no banco.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        $block = fn () => throw new LogicException('audit_logs is append-only');

        static::creating($block);
        static::updating($block);
        static::deleting($block);
    }
}
