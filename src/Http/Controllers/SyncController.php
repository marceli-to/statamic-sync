<?php

namespace MarceliTo\StatamicSync\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
     * Download a single file.
     *
     * GET /_sync/file?path=content/collections/pages/home.yaml
     * GET /_sync/file?path=assets/images/photo.jpg
     */
    public function file(Request $request): BinaryFileResponse
    {
        $requestedPath = $request->query('path', '');

        if (empty($requestedPath)) {
            abort(400, 'No path specified.');
        }

        // Determine which configured path this belongs to
        $configuredPaths = config('statamic-sync.paths', []);
        $resolvedPath = null;

        foreach ($configuredPaths as $key => $basePath) {
            if (str_starts_with($requestedPath, $key . '/')) {
                $relativePath = substr($requestedPath, strlen($key) + 1);
                $candidate = base_path($basePath . '/' . $relativePath);

                // Prevent directory traversal
                $realBase = realpath(base_path($basePath));
                $realCandidate = realpath($candidate);

                if ($realCandidate && $realBase && str_starts_with($realCandidate, $realBase) && is_file($realCandidate)) {
                    $resolvedPath = $realCandidate;
                }

                break;
            }
        }

        if (! $resolvedPath) {
            abort(404, 'File not found.');
        }

        return response()->file($resolvedPath);
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
