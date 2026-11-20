<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Http\Controllers;

use BadRush\Weathermap\Support\AssetManifest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Serves pre-built Vue/Vite frontend assets and bundled fonts directly from the Composer package
 * with ETag and immutable caching — zero `vendor:publish` needed by LibreNMS operators.
 */
final class BundleController extends Controller
{
    /** @var array<string, string> */
    private const array MIME_MAP = [
        'js' => 'application/javascript; charset=utf-8',
        'mjs' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'ttf' => 'font/ttf',
        'woff2' => 'font/woff2',
        'map' => 'application/json; charset=utf-8',
    ];

    public function show(Request $request, string $path): Response
    {
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            abort(404);
        }

        $baseDir = str_starts_with($path, 'fonts/')
            ? realpath(dirname(__DIR__, 3).'/resources')
            : realpath(AssetManifest::distDir());

        if ($baseDir === false) {
            abort(404);
        }

        $full = realpath($baseDir.DIRECTORY_SEPARATOR.ltrim($path, '/'));
        if ($full === false || ! str_starts_with($full, $baseDir.DIRECTORY_SEPARATOR) || ! is_file($full)) {
            abort(404);
        }

        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mime = self::MIME_MAP[$ext] ?? null;
        if ($mime === null) {
            abort(404);
        }

        $bytes = file_get_contents($full);
        if (! is_string($bytes)) {
            abort(500);
        }

        $etag = '"'.sha1($bytes).'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
