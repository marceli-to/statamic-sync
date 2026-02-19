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
     * Stream a tar.gz of a single path directly to the response.
     *
     * GET /_sync/archive?path=content
     * GET /_sync/archive?path=assets
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
            // Stream tar.gz directly to output — nothing written to disk
            $cmd = sprintf(
                'tar czf - -C %s .',
                escapeshellarg($fullPath)
            );

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
