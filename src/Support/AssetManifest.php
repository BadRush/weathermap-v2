<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Support;

/**
 * Resolves built Vite assets from the package's bundled `dist/.vite/manifest.json`
 * so the plugin serves its Vue 3 frontend directly without requiring `vendor:publish`.
 */
final class AssetManifest
{
    /**
     * @return array{js: list<string>, css: list<string>}
     */
    public static function entry(string $entryName): array
    {
        $devServer = config('weathermap.vite_dev_server');
        if (is_string($devServer) && $devServer !== '') {
            $base = rtrim($devServer, '/');

            return [
                'js' => [
                    "{$base}/@vite/client",
                    "{$base}/resources/js/{$entryName}/main.ts",
                ],
                'css' => [],
            ];
        }

        $distDir = self::distDir();
        $manifestPath = $distDir.'/.vite/manifest.json';
        if (! is_file($manifestPath)) {
            return ['js' => [], 'css' => []];
        }

        $raw = file_get_contents($manifestPath);
        if (! is_string($raw)) {
            return ['js' => [], 'css' => []];
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return ['js' => [], 'css' => []];
        }

        $key = "resources/js/{$entryName}/main.ts";
        $record = $manifest[$key] ?? null;
        if (! is_array($record)) {
            return ['js' => [], 'css' => []];
        }

        $js = [];
        $css = [];

        if (is_string($record['file'] ?? null)) {
            $js[] = '/weathermap/assets/'.ltrim($record['file'], '/');
        }
        if (is_array($record['css'] ?? null)) {
            foreach ($record['css'] as $cssFile) {
                if (is_string($cssFile)) {
                    $css[] = '/weathermap/assets/'.ltrim($cssFile, '/');
                }
            }
        }
        if (is_array($record['imports'] ?? null)) {
            foreach ($record['imports'] as $importKey) {
                if (is_string($importKey) && is_array($manifest[$importKey]['css'] ?? null)) {
                    foreach ($manifest[$importKey]['css'] as $impCss) {
                        if (is_string($impCss)) {
                            $css[] = '/weathermap/assets/'.ltrim($impCss, '/');
                        }
                    }
                }
            }
        }

        return [
            'js' => array_values(array_unique($js)),
            'css' => array_values(array_unique($css)),
        ];
    }

    public static function distDir(): string
    {
        return dirname(__DIR__, 2).'/dist';
    }
}
