<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['endpoint_id', 'name', 'description', 'type', 'mac', 'ips', 'up', 'speed_mbps'])]
class NetworkInterface extends Model
{
    protected function casts(): array
    {
        return ['ips' => 'array', 'up' => 'boolean'];
    }
}
