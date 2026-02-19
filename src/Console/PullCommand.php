<?php

namespace MarceliTo\StatamicSync\Console;

use Illuminate\Console\Command;
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

        // If pulling both content and assets, do them separately to avoid timeout
        $keys = array_map('trim', explode(',', $paths));

        foreach ($keys as $key) {
            $result = $this->pullPath($remote, $token, $key);

            if ($result !== self::SUCCESS) {
                return $result;
            }
        }

        $this->newLine();
        $this->info('✓ Sync complete.');

        return self::SUCCESS;
    }

    private function pullPath(string $remote, string $token, string $key): int
    {
        $this->info("Pulling [{$key}] from {$remote}...");

        // Use cURL for streaming download with progress
        $tempFile = tempnam(sys_get_temp_dir(), 'statamic-sync-') . '.zip';
        $fp = fopen($tempFile, 'w+');

        $ch = curl_init("{$remote}/_sync/download?" . http_build_query(['paths' => $key]));
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 0, // No timeout
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_BUFFERSIZE => 2 * 1024 * 1024,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) {
                if ($dlTotal > 0) {
                    $pct = round(($dlNow / $dlTotal) * 100);
                    $downloaded = $this->formatBytes((int) $dlNow);
                    $total = $this->formatBytes((int) $dlTotal);
                    $this->output->write("\r  Downloading: {$downloaded} / {$total} ({$pct}%)");
                }

                return 0;
            },
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        $this->newLine();

        if (! $success || $httpCode !== 200) {
            $body = file_get_contents($tempFile);
            @unlink($tempFile);
            $this->error("  Failed: HTTP {$httpCode}" . ($error ? " ({$error})" : ''));
            return self::FAILURE;
        }

        // Extract
        $this->output->write('  Extracting... ');

        $zip = new ZipArchive();

        if ($zip->open($tempFile) !== true) {
            $this->error('Failed to open downloaded archive.');
            @unlink($tempFile);
            return self::FAILURE;
        }

        $configuredPaths = config('statamic-sync.paths', []);

        if (! isset($configuredPaths[$key])) {
            $this->error("Unknown path key: {$key}");
            $zip->close();
            @unlink($tempFile);
            return self::FAILURE;
        }

        $targetDir = base_path($configuredPaths[$key]);

        // Clean the target directory
        if (is_dir($targetDir)) {
            $this->deleteDirectory($targetDir);
        }

        mkdir($targetDir, 0755, true);

        // Extract files
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);

            if (str_starts_with($entryName, $key . '/')) {
                $relativePath = substr($entryName, strlen($key) + 1);

                if (empty($relativePath)) {
                    continue;
                }

                $targetPath = $targetDir . '/' . $relativePath;
                $targetDirPath = dirname($targetPath);

                if (! is_dir($targetDirPath)) {
                    mkdir($targetDirPath, 0755, true);
                }

                $content = $zip->getFromIndex($i);

                if ($content !== false) {
                    file_put_contents($targetPath, $content);
                }
            }
        }

        $zip->close();
        @unlink($tempFile);

        $this->line('✓');

        return self::SUCCESS;
    }

    private function dryRun(string $remote, string $token, string $paths): int
    {
        $this->info("Fetching manifest from {$remote}...");

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
            $this->error("Failed: HTTP {$httpCode} {$response}");
            return self::FAILURE;
        }

        $manifest = json_decode($response, true);

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
