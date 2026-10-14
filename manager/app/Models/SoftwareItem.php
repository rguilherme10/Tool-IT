<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['endpoint_id', 'identity_hash', 'name', 'version', 'publisher', 'install_date', 'arch', 'source', 'first_seen_at', 'last_seen_at', 'removed_at'])]
class SoftwareItem extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'install_date' => 'date',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public static function identityHash(string $name, ?string $version, ?string $arch, string $source): string
    {
        return hash('sha256', implode('|', [mb_strtolower($name), $version ?? '', $arch ?? '', $source]));
    }
}
