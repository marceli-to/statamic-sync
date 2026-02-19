<?php

namespace MarceliTo\StatamicSync\Console;

use Illuminate\Console\Command;

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

        // Fetch manifest
        $this->info("Fetching file list from {$remote}...");
        $manifest = $this->fetchManifest($remote, $token, $paths);

        if ($manifest === null) {
            return self::FAILURE;
        }

        // Show summary
        $totalFiles = 0;
        $totalSize = 0;

        foreach ($manifest as $key => $files) {
            $keySize = array_sum(array_column($files, 'size'));
            $totalFiles += count($files);
            $totalSize += $keySize;
            $this->line("  <info>{$key}</info>: " . count($files) . ' files (' . $this->formatBytes($keySize) . ')');
        }

        $this->line("  <comment>Total</comment>: {$totalFiles} files (" . $this->formatBytes($totalSize) . ')');

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        // Confirmation
        if (! $this->option('force') && ! $this->confirm('This will overwrite local files with remote data. Continue?')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $configuredPaths = config('statamic-sync.paths', []);

        // Clean target directories
        $requestedKeys = array_keys($manifest);

        foreach ($requestedKeys as $key) {
            if (! isset($configuredPaths[$key])) {
                continue;
            }

            $targetDir = base_path($configuredPaths[$key]);

            if (is_dir($targetDir)) {
                $this->output->write("  Cleaning {$key}... ");
                $this->deleteDirectory($targetDir);
                $this->line('✓');
            }

            mkdir($targetDir, 0755, true);
        }

        // Download files one by one
        $this->newLine();
        $bar = $this->output->createProgressBar($totalFiles);
        $bar->setFormat(" %current%/%max% [%bar%] %percent:3s%% %message%");
        $bar->setMessage('Starting...');
        $bar->start();

        $errors = [];

        foreach ($manifest as $key => $files) {
            if (! isset($configuredPaths[$key])) {
                continue;
            }

            $targetDir = base_path($configuredPaths[$key]);

            foreach ($files as $relativePath => $meta) {
                $bar->setMessage($key . '/' . $this->truncatePath($relativePath, 40));

                $remotePath = $key . '/' . $relativePath;
                $localPath = $targetDir . '/' . $relativePath;

                // Ensure directory exists
                $dir = dirname($localPath);

                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                // Download file
                $success = $this->downloadFile($remote, $token, $remotePath, $localPath);

                if (! $success) {
                    $errors[] = $remotePath;
                }

                $bar->advance();
            }
        }

        $bar->setMessage('Done!');
        $bar->finish();
        $this->newLine(2);

        if (! empty($errors)) {
            $this->warn(count($errors) . ' file(s) failed to download:');

            foreach (array_slice($errors, 0, 10) as $path) {
                $this->line("  - {$path}");
            }

            if (count($errors) > 10) {
                $this->line('  ... and ' . (count($errors) - 10) . ' more');
            }
        }

        $this->info('✓ Sync complete.');

        return self::SUCCESS;
    }

    private function fetchManifest(string $remote, string $token, string $paths): ?array
    {
        $ch = curl_init("{$remote}/_sync/manifest?" . http_build_query(['paths' => $paths]));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->error("Failed to fetch manifest: HTTP {$httpCode}");
            return null;
        }

        return json_decode($response, true);
    }

    private function downloadFile(string $remote, string $token, string $remotePath, string $localPath): bool
    {
        $url = "{$remote}/_sync/file?" . http_build_query(['path' => $remotePath]);

        $fp = fopen($localPath, 'w');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (! $success || $httpCode !== 200) {
            @unlink($localPath);
            return false;
        }

        return true;
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

    private function truncatePath(string $path, int $max): string
    {
        if (strlen($path) <= $max) {
            return $path;
        }

        return '...' . substr($path, -(max($max - 3, 10)));
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
