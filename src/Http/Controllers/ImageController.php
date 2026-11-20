<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Http\Controllers;

use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Policies\WeathermapMapPolicy;
use BadRush\Weathermap\Services\MapRendererService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

final class ImageController extends Controller
{
    public function __construct(
        private readonly WeathermapMapPolicy $policy,
        private readonly MapRendererService $renderer,
    ) {}

    public function png(Request $request, WeathermapMap $map): Response
    {
        return $this->serve($request, $map, thumbnail: false);
    }

    public function thumb(Request $request, WeathermapMap $map): Response
    {
        return $this->serve($request, $map, thumbnail: true);
    }

    private function serve(Request $request, WeathermapMap $map, bool $thumbnail): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($this->policy->view($user, $map), 403);

        $path = $thumbnail ? $this->renderer->thumbnailPath($map) : $this->renderer->pngPath($map);

        if (! is_file($path)) {
            $ok = $this->renderer->renderPngToStorage($map);
            if (! $ok || ! is_file($path)) {
                abort(503, 'Map PNG is currently unavailable.');
            }
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            abort(500);
        }

        $etag = '"'.sha1($bytes).'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=30',
            ]);
        }

        return response($bytes, 200, [
            'Content-Type' => 'image/png',
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=30',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
