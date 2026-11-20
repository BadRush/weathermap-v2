<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Http\Controllers;

use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Policies\WeathermapMapPolicy;
use BadRush\Weathermap\Services\MapRendererService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class SceneController extends Controller
{
    public function __construct(
        private readonly WeathermapMapPolicy $policy,
        private readonly MapRendererService $renderer,
    ) {}

    public function show(Request $request, WeathermapMap $map): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        abort_unless($this->policy->view($user, $map), 403);

        $fresh = $request->boolean('fresh') && $this->policy->update($user, $map);
        $scene = $this->renderer->getScene($map, $fresh);

        return response()->json([
            'map' => [
                'id' => $map->id,
                'name' => $map->name,
                'slug' => $map->slug,
                'revision' => $map->revision,
            ],
            'scene' => $scene->toArray(),
        ]);
    }
}
