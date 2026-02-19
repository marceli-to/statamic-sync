<?php

namespace MarceliTo\StatamicSync\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SyncController extends Controller
{
    /**
     * Return a manifest of files with their sizes and hashes.
     *
     * GET /_sync/manifest?paths=content,assets
     */
    public function manifest(Request $request): array
    {
        $requestedPaths = $request->query('paths', 'content,assets');
        $keys = array_map('trim', explode(',', $requestedPaths));

        $configuredPaths = config('statamic-sync.paths', []);
        $manifest = [];

        foreach ($keys as $key) {
            if (isset($configuredPaths[$key])) {
                $fullPath = base_path($configuredPaths[$key]);

                if (is_dir($fullPath)) {
                    $manifest[$key] = $this->buildManifest($fullPath);
                }
            }
        }

        return $manifest;
    }

    /**
     * Stream a tar.gz of a full path.
     *
     * GET /_sync/archive?path=content
     */
    public function archive(Request $request): StreamedResponse
    {
        set_time_limit(0);

        $key = $request->query('path', '');
        $configuredPaths = config('statamic-sync.paths', []);

        if (empty($key) || ! isset($configuredPaths[$key])) {
            abort(400, 'Invalid path key.');
        }

        $fullPath = realpath(base_path($configuredPaths[$key]));

        if (! $fullPath || ! is_dir($fullPath)) {
            abort(404, 'Path not found.');
        }

        return new StreamedResponse(function () use ($fullPath) {
            $cmd = sprintf('tar czf - -C %s .', escapeshellarg($fullPath));
            $process = popen($cmd, 'r');

            if ($process) {
                while (! feof($process)) {
                    echo fread($process, 2 * 1024 * 1024);
                    flush();
                }

                pclose($process);
            }
        }, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => "attachment; filename=\"{$key}.tar.gz\"",
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Stream a tar.gz of specific files only (for delta sync).
     *
     * POST /_sync/archive-partial
     * Body: { "path": "assets", "files": ["images/photo.jpg", "documents/file.pdf"] }
     */
    public function archivePartial(Request $request): StreamedResponse
    {
        set_time_limit(0);

        $key = $request->input('path', '');
        $files = $request->input('files', []);
        $configuredPaths = config('statamic-sync.paths', []);

        if (empty($key) || ! isset($configuredPaths[$key])) {
            abort(400, 'Invalid path key.');
        }

        if (empty($files)) {
            abort(400, 'No files specified.');
        }

        $fullPath = realpath(base_path($configuredPaths[$key]));

        if (! $fullPath || ! is_dir($fullPath)) {
            abort(404, 'Path not found.');
        }

        // Validate all files exist and are within the base path
        $validFiles = [];

        foreach ($files as $file) {
            $filePath = realpath($fullPath . '/' . $file);

            if ($filePath && str_starts_with($filePath, $fullPath) && is_file($filePath)) {
                $validFiles[] = $file;
            }
        }

        if (empty($validFiles)) {
            abort(404, 'No valid files found.');
        }

        return new StreamedResponse(function () use ($fullPath, $validFiles) {
            // Write file list to temp file for tar --files-from
            $listFile = tempnam(sys_get_temp_dir(), 'statamic-sync-list-');
            file_put_contents($listFile, implode("\n", $validFiles));

            $cmd = sprintf(
                'tar czf - -C %s --files-from %s',
                escapeshellarg($fullPath),
                escapeshellarg($listFile)
            );

            $process = popen($cmd, 'r');

            if ($process) {
                while (! feof($process)) {
                    echo fread($process, 2 * 1024 * 1024);
                    flush();
                }

                pclose($process);
            }

            @unlink($listFile);
        }, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => "attachment; filename=\"{$key}-partial.tar.gz\"",
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function buildManifest(string $directory): array
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
}
