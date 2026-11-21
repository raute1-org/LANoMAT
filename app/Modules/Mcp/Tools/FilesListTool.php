<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\VisibleFiles;
use App\Modules\Mcp\Support\SafeText;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('files_list')]
#[Description('Approved files on the LAN file host for one event (ISOs, avatars, mods) plus the caller\'s own pending uploads.')]
#[IsReadOnly]
class FilesListTool extends LanomatTool
{
    public function __construct(private readonly Modules $modules) {}

    public function shouldRegister(Request $request): bool
    {
        return $this->modules->enabled(Module::Files);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_slug' => $schema->string()->description('Event slug.')->required(),
            'query' => $schema->string()->description('Optional filename filter.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $event = $this->eventFromSlug((string) $request->get('event_slug'));
        $query = mb_strtolower(trim((string) $request->get('query', '')));

        $files = VisibleFiles::listFor($event, $this->user()->id)
            ->when($query !== '', fn ($collection) => $collection->filter(
                static fn (SharedFile $file): bool => str_contains(mb_strtolower($file->original_name), $query),
            ))
            ->values();

        return $this->structured([
            'event_slug' => $event->slug,
            'files' => $files->map(fn (SharedFile $file): array => [
                'id' => $file->id,
                // Uploader-chosen text that ends up in an LLM context.
                'name' => SafeText::fileName($file->original_name),
                'size_bytes' => $file->size_bytes,
                'visibility' => $file->visibility->value,
                'mine' => $file->user_id === $this->user()->id,
                'uploaded_at' => $file->created_at?->toIso8601String(),
                'download_url' => route('mcp.files.download', ['sharedFile' => $file->id], true),
            ])->all(),
        ]);
    }
}
