<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\RegistersImageConversions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithMedia, Notifiable, RegistersImageConversions {
        // Both traits define registerMediaConversions(); ours holds the real conversions.
        RegistersImageConversions::registerMediaConversions insteadof InteractsWithMedia;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isEditor(): bool
    {
        return $this->role === 'editor';
    }

    /**
     * Deletes this account after handing its posts, pages and media library
     * uploads to $recipient. Without the hand-over they would be lost:
     * posts.author_id and pages.author_id cascade on delete, and Spatie deletes
     * a model's media together with the model.
     */
    public function deleteAndTransferContentTo(User $recipient): void
    {
        DB::transaction(function () use ($recipient) {
            Post::where('author_id', $this->id)->update(['author_id' => $recipient->id]);
            Page::where('author_id', $this->id)->update(['author_id' => $recipient->id]);
            Media::where('model_type', self::class)->where('model_id', $this->id)
                ->update(['model_id' => $recipient->id]);

            $this->delete();
        });
    }

    /**
     * Files uploaded through the admin media library. Spatie needs an owning model,
     * so library uploads are attached to the user who uploaded them (which also
     * records the uploader, like the old media.user_id column did).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('library');
    }
}
