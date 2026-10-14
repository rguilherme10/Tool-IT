<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Escrita em lote por MetricsIngestService (insertOrIgnore); o modelo serve para leitura. */
class MetricSample extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'received_at' => 'datetime',
            'volumes' => 'array',
            'cpu_percent' => 'float',
            'temperature_c' => 'float',
        ];
    }
}
