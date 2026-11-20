<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Services;

use BadRush\Weathermap\Models\WeathermapAsset;
use BadRush\Weathermap\Models\WeathermapMap;
use BadRush\Weathermap\Render\GdRenderer;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

final class AssetStorageService
{
    public const int MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    /** @var list<string> */
    private const array ALLOWED_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    /** @var list<string> */
    private const array ALLOWED_EXTENSIONS = [
        'png',
        'jpg',
        'jpeg',
        'webp',
    ];

    public function __construct(
        private readonly GdRenderer $gdRenderer,
        private readonly MapRendererService $rendererService,
    ) {}

    public function storeUpload(UploadedFile $file, string $kind, ?int $userId): WeathermapAsset
    {
        if (! in_array($kind, WeathermapAsset::ALLOWED_KINDS, true)) {
            throw new InvalidArgumentException('Invalid asset kind.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new InvalidArgumentException('Image exceeds maximum allowed size of 2 MB.');
        }

        $mime = (string) ($file->getMimeType() ?? '');
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('Only PNG, JPEG, and WebP images are allowed. SVG files are rejected.');
        }

        $realPath = $file->getRealPath();
        if (! is_string($realPath) || $realPath === '') {
            throw new InvalidArgumentException('Could not read uploaded image.');
        }

        $rawBytes = file_get_contents($realPath);
        if (! is_string($rawBytes) || $rawBytes === '') {
            throw new InvalidArgumentException('Could not read uploaded image.');
        }

        return $this->storeSanitizedBytes($rawBytes, $file->getClientOriginalName(), $kind, $userId);
    }

    /**
     * Absolute directory where admins can drop reusable vendor logo files.
     */
    public function vendorDropDir(): string
    {
        return $this->rendererService->storageBaseDir().'/assets/vendors';
    }

    /**
     * Scan storage/app/weathermap/assets/vendors/ and import any new raster images
     * into the centralized vendor icon library (idempotent via SHA-256 deduplication).
     *
     * @return array{imported: int, skipped: int, errors: list<string>, dir: string}
     */
    public function syncVendorFolder(?int $userId = null): array
    {
        $dir = $this->vendorDropDir();
        File::ensureDirectoryExists($dir, 0750);

        $entries = @scandir($dir);
        if (! is_array($entries)) {
            return [
                'imported' => 0,
                'skipped' => 0,
                'errors' => ['Unable to read vendor icons directory.'],
                'dir' => $dir,
            ];
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $finfo = extension_loaded('fileinfo') ? new finfo(FILEINFO_MIME_TYPE) : null;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $fullPath = $dir.'/'.$entry;
            if (! is_file($fullPath) || is_link($fullPath)) {
                continue;
            }

            // Skip canonical sanitized files that are already indexed in the database
            if (preg_match('/^([0-9a-f]{64})\.png$/i', $entry, $matches) === 1) {
                $existingHash = strtolower($matches[1]);
                if (WeathermapAsset::query()->where('sha256', $existingHash)->where('kind', WeathermapAsset::KIND_VENDOR)->exists()) {
                    continue;
                }
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if ($ext === 'svg' || $ext === 'svgz') {
                $errors[] = "{$entry}: SVG files are rejected for security.";

                continue;
            }
            if (! in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                $skipped++;

                continue;
            }

            $size = @filesize($fullPath);
            if ($size === false || $size <= 0 || $size > self::MAX_BYTES) {
                $errors[] = "{$entry}: File must be between 1 byte and 2 MB.";

                continue;
            }

            if ($finfo !== null) {
                $detectedMime = $finfo->file($fullPath);
                if (! is_string($detectedMime) || ! in_array($detectedMime, self::ALLOWED_MIMES, true)) {
                    $errors[] = "{$entry}: Unsupported image MIME type (only PNG, JPEG, WebP allowed).";

                    continue;
                }
            }

            $rawBytes = @file_get_contents($fullPath);
            if (! is_string($rawBytes) || $rawBytes === '') {
                $errors[] = "{$entry}: Could not read file bytes.";

                continue;
            }

            try {
                $sanitizedPng = $this->gdRenderer->sanitizeUploadedImage($rawBytes, 512);
                $sha256 = hash('sha256', $sanitizedPng);

                $alreadyExists = WeathermapAsset::query()
                    ->where('sha256', $sha256)
                    ->where('kind', WeathermapAsset::KIND_VENDOR)
                    ->exists();
                if ($alreadyExists) {
                    $skipped++;

                    continue;
                }

                $this->persistSanitizedPng($sanitizedPng, $sha256, $entry, WeathermapAsset::KIND_VENDOR, $userId);
                $imported++;
            } catch (Throwable $e) {
                $errors[] = "{$entry}: {$e->getMessage()}";
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'dir' => $dir,
        ];
    }

    /**
     * Find all map names currently referencing the given asset ID.
     *
     * @return list<string>
     */
    public function findAssetUsage(int $assetId): array
    {
        $usedBy = [];

        /** @var iterable<int, WeathermapMap> $maps */
        $maps = WeathermapMap::query()->select(['id', 'name', 'document'])->cursor();
        foreach ($maps as $map) {
            $doc = is_array($map->document) ? $map->document : [];
            if (self::documentUsesAsset($doc, $assetId)) {
                $usedBy[] = $map->name;
            }
        }

        return $usedBy;
    }

    /**
     * Safely delete an asset after verifying no map references it.
     *
     * @throws AssetInUseException
     */
    public function deleteAsset(WeathermapAsset $asset): void
    {
        $usedBy = $this->findAssetUsage($asset->id);
        if ($usedBy !== []) {
            throw new AssetInUseException($usedBy);
        }

        $relPath = ltrim($asset->path, '/');
        $asset->delete();

        if ($relPath === '' || str_contains($relPath, '..')) {
            return;
        }

        $stillReferenced = WeathermapAsset::query()->where('path', $asset->path)->exists();
        if (! $stillReferenced) {
            $absPath = $this->rendererService->storageBaseDir().'/'.$relPath;
            if (is_file($absPath)) {
                @unlink($absPath);
            }
        }
    }

    private function storeSanitizedBytes(string $rawBytes, string $originalName, string $kind, ?int $userId): WeathermapAsset
    {
        // Re-encode through GD to strip EXIF/metadata and neutralize embedded payloads
        $maxDim = ($kind === WeathermapAsset::KIND_ICON || $kind === WeathermapAsset::KIND_VENDOR) ? 512 : 4096;
        $sanitizedPng = $this->gdRenderer->sanitizeUploadedImage($rawBytes, $maxDim);
        $sha256 = hash('sha256', $sanitizedPng);

        $existing = WeathermapAsset::query()
            ->where('sha256', $sha256)
            ->where('kind', $kind)
            ->first();
        if ($existing !== null) {
            $existingAbs = $this->rendererService->storageBaseDir().'/'.ltrim($existing->path, '/');
            if (! is_file($existingAbs)) {
                File::ensureDirectoryExists(dirname($existingAbs), 0750);
                file_put_contents($existingAbs, $sanitizedPng);
            }

            return $existing;
        }

        return $this->persistSanitizedPng($sanitizedPng, $sha256, $originalName, $kind, $userId);
    }

    private function persistSanitizedPng(
        string $sanitizedPng,
        string $sha256,
        string $originalName,
        string $kind,
        ?int $userId,
    ): WeathermapAsset {
        $subDir = self::folderForKind($kind);
        $relPath = "assets/{$subDir}/{$sha256}.png";
        $absPath = $this->rendererService->storageBaseDir().'/'.$relPath;
        File::ensureDirectoryExists(dirname($absPath), 0750);
        if (! is_file($absPath)) {
            file_put_contents($absPath, $sanitizedPng);
        }

        return WeathermapAsset::query()->create([
            'kind' => $kind,
            'original_name' => mb_substr(basename($originalName), 0, 255),
            'path' => $relPath,
            'mime' => 'image/png',
            'size' => strlen($sanitizedPng),
            'sha256' => $sha256,
            'created_by' => $userId,
        ]);
    }

    private static function folderForKind(string $kind): string
    {
        return match ($kind) {
            WeathermapAsset::KIND_VENDOR => 'vendors',
            WeathermapAsset::KIND_ICON => 'icons',
            default => 'backgrounds',
        };
    }

    /**
     * @param  array<string, mixed>  $doc
     */
    private static function documentUsesAsset(array $doc, int $assetId): bool
    {
        if (is_array($doc['canvas'] ?? null) && (int) ($doc['canvas']['backgroundAssetId'] ?? 0) === $assetId) {
            return true;
        }

        if (is_array($doc['nodes'] ?? null)) {
            foreach ($doc['nodes'] as $node) {
                if (is_array($node) && (int) ($node['iconAssetId'] ?? 0) === $assetId) {
                    return true;
                }
            }
        }

        if (is_array($doc['links'] ?? null)) {
            foreach ($doc['links'] as $link) {
                if (is_array($link) && (int) ($link['vendorIconAssetId'] ?? 0) === $assetId) {
                    return true;
                }
            }
        }

        if (is_array($doc['annotations'] ?? null)) {
            foreach ($doc['annotations'] as $annotation) {
                if (is_array($annotation) && (int) ($annotation['iconAssetId'] ?? 0) === $assetId) {
                    return true;
                }
            }
        }

        return false;
    }
}
