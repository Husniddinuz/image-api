<?php

namespace App\Models;

use Database\Factories\ImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's claim on a blob. Deleting one never touches another user's copy.
 */
#[Fillable(['user_id', 'image_blob_id', 'name'])]
class Image extends Model
{
    /** @use HasFactory<ImageFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<ImageBlob, $this> */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(ImageBlob::class, 'image_blob_id');
    }

    /**
     * Every read path goes through here, so "a user can only see their own
     * images" is enforced by the query itself rather than by a policy that
     * somebody could forget to call.
     *
     * @param  Builder<Image>  $query
     */
    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }
}
