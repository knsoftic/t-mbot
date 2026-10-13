<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'color'])]
class Tag extends Model
{
    public const COLORS = ['slate', 'red', 'amber', 'green', 'teal', 'blue', 'indigo', 'purple', 'pink'];

    protected static function booted(): void
    {
        static::saving(function (Tag $tag) {
            $tag->slug = Str::slug($tag->name);
        });
    }

    /**
     * @return BelongsToMany<Content, $this>
     */
    public function contents(): BelongsToMany
    {
        return $this->belongsToMany(Content::class);
    }

    /**
     * Resolve tag names to ids, creating missing tags.
     *
     * @param  iterable<string>  $names
     * @return list<int>
     */
    public static function idsFor(iterable $names): array
    {
        $ids = [];

        foreach ($names as $name) {
            $name = trim((string) $name);

            if ($name === '' || Str::slug($name) === '') {
                continue;
            }

            $ids[] = static::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name])->id;
        }

        return array_values(array_unique($ids));
    }
}
