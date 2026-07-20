<?php

declare(strict_types=1);

namespace App\Application\AccountProfiles;

use Illuminate\Support\Facades\Storage;

final readonly class AccountProfileMediaMutationBackup
{
    /**
     * @param  array<int, string>  $filenamePrefixes
     * @param  array<string, string>  $files
     */
    public function __construct(
        private string $directory,
        private array $filenamePrefixes,
        private array $files,
    ) {}

    public function restore(): void
    {
        $disk = Storage::disk('public');

        foreach ($disk->allFiles($this->directory) as $path) {
            if ($this->belongsToAffectedMutation($path)) {
                $disk->delete($path);
            }
        }

        foreach ($this->files as $path => $contents) {
            $disk->put($path, $contents);
        }
    }

    private function belongsToAffectedMutation(string $path): bool
    {
        $filename = basename($path);

        foreach ($this->filenamePrefixes as $prefix) {
            if (str_starts_with($filename, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
