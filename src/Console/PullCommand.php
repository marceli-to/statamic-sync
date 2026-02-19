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
        $keys = array_map('trim', explode(',', $paths));

        // Fetch manifest for summary
        $this->info("Fetching file list from {$remote}...");
        $manifest = $this->fetchManifest($remote, $token, $paths);

        if ($manifest === null) {
            return self::FAILURE;
        }

        // Show summary
        foreach ($manifest as $key => $files) {
            $keySize = array_sum(array_column($files, 'size'));
            $this->line("  <info>{$key}</info>: " . count($files) . ' files (' . $this->formatBytes($keySize) . ')');
        }

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        // Confirmation
        if (! $this->option('force') && ! $this->confirm('This will overwrite local files with remote data. Continue?')) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $configuredPaths = config('statamic-sync.paths', []);

        // Pull each path as a streamed tar.gz
        foreach ($keys as $key) {
            if (! isset($configuredPaths[$key]) || ! isset($manifest[$key])) {
                continue;
            }

            $result = $this->pullArchive($remote, $token, $key, $configuredPaths[$key]);

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        $this->newLine();
        $this->info('✓ Sync complete.');

        return self::SUCCESS;
    }

    private function pullArchive(string $remote, string $token, string $key, string $localPath): int
    {
        $targetDir = base_path($localPath);

        // Clean target directory
        if (is_dir($targetDir)) {
            $this->output->write("  Cleaning <info>{$key}</info>... ");
            $this->deleteDirectory($targetDir);
            $this->line('✓');
        }

        mkdir($targetDir, 0755, true);

        // Download tar.gz to temp file
        $this->output->write("  Downloading <info>{$key}</info>... ");

        $tempFile = tempnam(sys_get_temp_dir(), "statamic-sync-{$key}-") . '.tar.gz';
        $fp = fopen($tempFile, 'w');

        $url = "{$remote}/_sync/archive?" . http_build_query(['path' => $key]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $downloadSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($fp);

        if (! $success || $httpCode !== 200) {
            $this->error("Failed: HTTP {$httpCode}" . ($error ? " ({$error})" : ''));
            @unlink($tempFile);
            return self::FAILURE;
        }

        $this->line('✓ (' . $this->formatBytes((int) $downloadSize) . ')');

        // Extract tar.gz
        $this->output->write("  Extracting <info>{$key}</info>... ");

        $cmd = sprintf(
            'tar xzf %s -C %s',
            escapeshellarg($tempFile),
            escapeshellarg($targetDir)
        );

        exec($cmd, $output, $exitCode);
        @unlink($tempFile);

        if ($exitCode !== 0) {
            $this->error('Failed to extract archive.');
            return self::FAILURE;
        }

        $this->line('✓');

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
