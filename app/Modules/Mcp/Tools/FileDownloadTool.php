<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Support\SafeText;
use App\Modules\Mcp\Support\AccessibleFiles;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('file_download_url')]
#[Description('A bearer-authenticated download URL for one LANoMAT file. Files are served from the LAN server, not the internet.')]
#[IsReadOnly]
class FileDownloadTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Files);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'file_id' => $schema->integer()->description('Shared file id from files_list or recipes.')->required(),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $file = SharedFile::query()->findOrFail((int) $request->get('file_id'));

        abort_if(Gate::forUser($this->user())->denies('download', $file), 403);
        AccessibleFiles::ensureVisible($file, $this->user());

        return $this->structured([
            'file' => [
                'id' => $file->id,
                'name' => SafeText::fileName($file->original_name),
                'size_bytes' => $file->size_bytes,
                'download_url' => route('mcp.files.download', ['sharedFile' => $file->id], true),
                'auth' => 'Authorization: Bearer <LANoMAT token>',
            ],
        ]);
    }
}
