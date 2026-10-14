<?php

namespace App\Models;

use Database\Factories\EndpointGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'description', 'parent_id'])]
class EndpointGroup extends Model
{
    /** @use HasFactory<EndpointGroupFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $group) {
            $group->uuid ??= (string) Str::uuid();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function endpoints(): HasMany
    {
        return $this->hasMany(Endpoint::class);
    }

    /**
     * IDs do grupo e de todos os descendentes (a árvore é pequena; sem CTE para manter portabilidade).
     *
     * @param  list<int>  $rootIds
     * @return list<int>
     */
    public static function expandWithDescendants(array $rootIds): array
    {
        $all = array_values(array_unique($rootIds));
        $frontier = $all;

        while ($frontier !== []) {
            $frontier = self::query()->whereIn('parent_id', $frontier)->pluck('id')
                ->map(fn ($id) => (int) $id)->diff($all)->values()->all();
            $all = array_merge($all, $frontier);
        }

        return $all;
    }
}
