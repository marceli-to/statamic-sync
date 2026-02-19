<?php

namespace MarceliTo\StatamicSync\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use ZipArchive;

class PullCommand extends Command
{
    protected $signature = 'statamic:pull
        {--only= : Pull only specific paths (comma-separated: content,assets)}
        {--dry-run : Show what would be synced without making changes}
        {--force : Skip confirmation prompt}';

    protected $description = 'Pull content and assets from a remote Statamic environment';

    public function handle(): int
    {
        $remote = rtrim(config('statamic-sync.remote'), '/');
        $token = config('statamic-sync.token');

        if (empty($remote)) {
            $this->error('No remote URL configured. Set STATAMIC_SYNC_REMOTE in your .env file.');
            return self::FAILURE;
        }

        if (empty($token)) {
            $this->error('No sync token configured. Set STATAMIC_SYNC_TOKEN in your .env file.');
            return self::FAILURE;
        }

        $paths = $this->option('only') ?: 'content,assets';

        // Dry run — fetch manifest and show file counts
        if ($this->option('dry-run')) {
            return $this->dryRun($remote, $token, $paths);
        }

        // Confirmation
        if (! $this->option('force') && ! $this->confirm("This will overwrite local [{$paths}] with remote data. Continue?")) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $this->info("Pulling [{$paths}] from {$remote}...");

        // Download zip
        $this->output->write('  Downloading... ');

        $response = Http::withToken($token)
            ->timeout(300)
            ->get("{$remote}/_sync/download", ['paths' => $paths]);

        if (! $response->successful()) {
            $this->error('Failed: ' . $response->status() . ' ' . $response->body());
            return self::FAILURE;
        }

        $this->line('✓');

        // Save to temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'statamic-sync-') . '.zip';
        file_put_contents($tempFile, $response->body());

        // Extract
        $this->output->write('  Extracting... ');

        $zip = new ZipArchive();

        if ($zip->open($tempFile) !== true) {
            $this->error('Failed to open downloaded archive.');
            @unlink($tempFile);
            return self::FAILURE;
        }

        $configuredPaths = config('statamic-sync.paths', []);
        $requestedKeys = array_map('trim', explode(',', $paths));

        foreach ($requestedKeys as $key) {
            if (! isset($configuredPaths[$key])) {
                continue;
            }

            $targetDir = base_path($configuredPaths[$key]);

            // Clean the target directory
            if (is_dir($targetDir)) {
                $this->deleteDirectory($targetDir);
            }

            mkdir($targetDir, 0755, true);
        }

        // Extract files to their correct locations
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);

            // Determine which key this belongs to
            foreach ($requestedKeys as $key) {
                if (! isset($configuredPaths[$key])) {
                    continue;
                }

                if (str_starts_with($entryName, $key . '/')) {
                    $relativePath = substr($entryName, strlen($key) + 1);

                    if (empty($relativePath)) {
                        continue;
                    }

                    $targetPath = base_path($configuredPaths[$key]) . '/' . $relativePath;
                    $targetDirPath = dirname($targetPath);

                    if (! is_dir($targetDirPath)) {
                        mkdir($targetDirPath, 0755, true);
                    }

                    $content = $zip->getFromIndex($i);

                    if ($content !== false) {
                        file_put_contents($targetPath, $content);
                    }

                    break;
                }
            }
        }

        $zip->close();
        @unlink($tempFile);

        $this->line('✓');
        $this->info('Sync complete.');

        return self::SUCCESS;
    }

    private function dryRun(string $remote, string $token, string $paths): int
    {
        $this->info("Fetching manifest from {$remote}...");

        $response = Http::withToken($token)
            ->timeout(30)
            ->get("{$remote}/_sync/manifest", ['paths' => $paths]);

        if (! $response->successful()) {
            $this->error('Failed: ' . $response->status() . ' ' . $response->body());
            return self::FAILURE;
        }

        $manifest = $response->json();

        foreach ($manifest as $key => $files) {
            $totalSize = array_sum(array_column($files, 'size'));
            $this->line("  <info>{$key}</info>: " . count($files) . ' files (' . $this->formatBytes($totalSize) . ')');
        }

        return self::SUCCESS;
    }

    private function deleteDirectory(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
