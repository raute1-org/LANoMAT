<?php

declare(strict_types=1);

namespace App\Modules\News\Filament\Resources\NewsPosts\Pages;

use App\Models\User;
use App\Modules\News\Filament\Resources\NewsPosts\NewsPostResource;
use App\Modules\News\Models\NewsPost;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateNewsPost extends CreateRecord
{
    protected static string $resource = NewsPostResource::class;

    /**
     * `author_id` is deliberately not in NewsPost::$fillable (ownership
     * field, see the model's docblock) and is never a form field, so the
     * default `handleRecordCreation` (which mass-assigns `$data` via the
     * model constructor) would silently drop it. It is `forceFill()`'d here
     * from the authenticated user instead — mirrors
     * CreateVoiceClientInstaller's handling of non-fillable fields — never
     * trusted from client-supplied `$data`.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        unset($data['author_id']);

        $record = new NewsPost($data);
        $record->forceFill(['author_id' => self::actor()->id]);
        $record->save();

        return $record;
    }

    private static function actor(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
