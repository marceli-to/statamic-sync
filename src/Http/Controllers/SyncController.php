<?php

namespace MarceliTo\StatamicSync\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class SyncController extends Controller
{
    /**
     * Stream a zip of the requested paths.
     *
     * GET /_sync/download?paths=content,assets
     */
    public function download(Request $request): StreamedResponse
    {
        $requestedPaths = $request->query('paths', 'content,assets');
        $keys = array_map('trim', explode(',', $requestedPaths));

        $configuredPaths = config('statamic-sync.paths', []);
        $pathsToZip = [];

        foreach ($keys as $key) {
            if (isset($configuredPaths[$key])) {
                $fullPath = base_path($configuredPaths[$key]);

                if (is_dir($fullPath)) {
                    $pathsToZip[$key] = $fullPath;
                }
            }
        }

        if (empty($pathsToZip)) {
            abort(404, 'No valid paths found to sync.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'statamic-sync-') . '.zip';

        $zip = new ZipArchive();
        $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($pathsToZip as $key => $fullPath) {
            $this->addDirectoryToZip($zip, $fullPath, $key);
        }

        $zip->close();

        return response()->streamDownload(function () use ($tempFile) {
            $stream = fopen($tempFile, 'rb');
            fpassthru($stream);
            fclose($stream);
            @unlink($tempFile);
        }, 'statamic-sync.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

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

    private function addDirectoryToZip(ZipArchive $zip, string $directory, string $prefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = $prefix . '/' . substr($file->getPathname(), strlen($directory) + 1);
                $zip->addFile($file->getPathname(), $relativePath);
            }
        }
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
