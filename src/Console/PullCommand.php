<?php

namespace MarceliTo\StatamicSync\Console;

use Illuminate\Console\Command;

class PullCommand extends Command
{
    protected $signature = 'statamic:pull
        {--only= : Pull only specific paths (comma-separated: content,assets)}
        {--dry-run : Show what would be synced without making changes}
        {--force : Skip confirmation prompt}
        {--full : Force a full sync (skip delta comparison)}';

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

        // Fetch remote manifest
        $this->info("Fetching file list from {$remote}...");
        $remoteManifest = $this->fetchManifest($remote, $token, $paths);

        if ($remoteManifest === null) {
            return self::FAILURE;
        }

        $configuredPaths = config('statamic-sync.paths', []);
        $fullSync = $this->option('full');

        foreach ($keys as $key) {
            if (! isset($configuredPaths[$key]) || ! isset($remoteManifest[$key])) {
                continue;
            }

            $targetDir = base_path($configuredPaths[$key]);
            $remoteFiles = $remoteManifest[$key];

            // Build local manifest for comparison
            $localFiles = is_dir($targetDir) && ! $fullSync
                ? $this->buildLocalManifest($targetDir)
                : [];

            // Calculate diff
            $newFiles = [];
            $changedFiles = [];
            $deletedFiles = [];
            $unchangedCount = 0;

            foreach ($remoteFiles as $file => $meta) {
                if (! isset($localFiles[$file])) {
                    $newFiles[$file] = $meta;
                } elseif ($localFiles[$file]['hash'] !== $meta['hash']) {
                    $changedFiles[$file] = $meta;
                } else {
                    $unchangedCount++;
                }
            }

            foreach ($localFiles as $file => $meta) {
                if (! isset($remoteFiles[$file])) {
                    $deletedFiles[] = $file;
                }
            }

            $downloadFiles = array_merge($newFiles, $changedFiles);
            $downloadSize = array_sum(array_column($downloadFiles, 'size'));

            // Show summary
            $this->newLine();
            $this->line("  <info>{$key}</info>:");
            $this->line("    Unchanged: {$unchangedCount} files");

            if (count($newFiles) > 0) {
                $this->line("    New:       <comment>" . count($newFiles) . ' files</comment>');
            }

            if (count($changedFiles) > 0) {
                $this->line("    Changed:   <comment>" . count($changedFiles) . ' files</comment>');
            }

            if (count($deletedFiles) > 0) {
                $this->line("    Deleted:   <comment>" . count($deletedFiles) . ' files</comment>');
            }

            if (empty($downloadFiles) && empty($deletedFiles)) {
                $this->line('    <info>Already up to date.</info>');
                continue;
            }

            $this->line("    Download:  " . $this->formatBytes($downloadSize));

            if ($this->option('dry-run')) {
                continue;
            }

            // Confirmation
            if (! $this->option('force') && ! $this->confirm("    Apply changes to {$key}?")) {
                $this->info("    Skipped {$key}.");
                continue;
            }

            // Full sync if everything is new (no local files) or --full flag
            if ($fullSync || empty($localFiles)) {
                $result = $this->pullFullArchive($remote, $token, $key, $targetDir);

                if ($result !== self::SUCCESS) {
                    return $result;
                }

                continue;
            }

            // Delta sync: download only changed/new files
            if (! empty($downloadFiles)) {
                $result = $this->pullPartialArchive($remote, $token, $key, $targetDir, array_keys($downloadFiles));

                if ($result !== self::SUCCESS) {
                    return $result;
                }
            }

            // Delete removed files
            if (! empty($deletedFiles)) {
                $this->output->write("    Deleting " . count($deletedFiles) . ' files... ');

                foreach ($deletedFiles as $file) {
                    $filePath = $targetDir . '/' . $file;

                    if (is_file($filePath)) {
                        unlink($filePath);
                    }
                }

                // Clean up empty directories
                $this->cleanEmptyDirs($targetDir);
                $this->line('✓');
            }
        }

        if (! $this->option('dry-run')) {
            $this->newLine();
            $this->info('✓ Sync complete.');
        }

        return self::SUCCESS;
    }

    private function pullFullArchive(string $remote, string $token, string $key, string $targetDir): int
    {
        // Clean target directory
        if (is_dir($targetDir)) {
            $this->output->write("    Cleaning... ");
            $this->deleteDirectory($targetDir);
            $this->line('✓');
        }

        mkdir($targetDir, 0755, true);

        // Download full tar.gz
        $this->output->write("    Downloading... ");

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
        $downloadSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($fp);

        if (! $success || $httpCode !== 200) {
            $this->error("Failed: HTTP {$httpCode}");
            @unlink($tempFile);
            return self::FAILURE;
        }

        $this->line('✓ (' . $this->formatBytes((int) $downloadSize) . ')');

        // Extract
        $this->output->write("    Extracting... ");
        exec(sprintf('tar xzf %s -C %s', escapeshellarg($tempFile), escapeshellarg($targetDir)), $output, $exitCode);
        @unlink($tempFile);

        if ($exitCode !== 0) {
            $this->error('Failed to extract archive.');
            return self::FAILURE;
        }

        $this->line('✓');

        return self::SUCCESS;
    }

    private function pullPartialArchive(string $remote, string $token, string $key, string $targetDir, array $files): int
    {
        $this->output->write("    Downloading " . count($files) . ' files... ');

        $tempFile = tempnam(sys_get_temp_dir(), "statamic-sync-{$key}-partial-") . '.tar.gz';
        $fp = fopen($tempFile, 'w');

        $url = "{$remote}/_sync/archive-partial";
        $postData = json_encode(['path' => $key, 'files' => $files]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $downloadSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($fp);

        if (! $success || $httpCode !== 200) {
            $this->error("Failed: HTTP {$httpCode}");
            @unlink($tempFile);
            return self::FAILURE;
        }

        $this->line('✓ (' . $this->formatBytes((int) $downloadSize) . ')');

        // Extract (overwrites existing files)
        $this->output->write("    Extracting... ");
        exec(sprintf('tar xzf %s -C %s', escapeshellarg($tempFile), escapeshellarg($targetDir)), $output, $exitCode);
        @unlink($tempFile);

        if ($exitCode !== 0) {
            $this->error('Failed to extract archive.');
            return self::FAILURE;
        }

        $this->line('✓');

        return self::SUCCESS;
    }

    private function buildLocalManifest(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = substr($file->getPathname(), strlen($directory) + 1);
                $files[$relativePath] = [
                    'size' => $file->getSize(),
                    'hash' => md5_file($file->getPathname()),
                ];
            }
        }

        return $files;
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

    private function cleanEmptyDirs(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                $path = $file->getPathname();

                if (count(scandir($path)) === 2) { // Only . and ..
                    rmdir($path);
                }
            }
        }
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
