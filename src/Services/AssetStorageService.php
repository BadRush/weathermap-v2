<?php

declare(strict_types=1);

namespace BadRush\Weathermap\Services;

use BadRush\Weathermap\Models\WeathermapAsset;
use BadRush\Weathermap\Render\GdRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

final class AssetStorageService
{
    public const int MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    /** @var list<string> */
    private const array ALLOWED_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
    ];

    public function __construct(
        private readonly GdRenderer $gdRenderer,
        private readonly MapRendererService $rendererService,
    ) {}

    public function storeUpload(UploadedFile $file, string $kind, ?int $userId): WeathermapAsset
    {
        if (! in_array($kind, [WeathermapAsset::KIND_BACKGROUND, WeathermapAsset::KIND_ICON], true)) {
            throw new InvalidArgumentException('Invalid asset kind.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new InvalidArgumentException('Image exceeds maximum allowed size of 2 MB.');
        }

        $mime = (string) ($file->getMimeType() ?? '');
        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw new InvalidArgumentException('Only PNG, JPEG, and WebP images are allowed. SVG files are rejected.');
        }

        $rawBytes = file_get_contents($file->getRealPath());
        if (! is_string($rawBytes) || $rawBytes === '') {
            throw new InvalidArgumentException('Could not read uploaded image.');
        }

        // Re-encode through GD to strip EXIF/metadata and neutralize embedded payloads
        $maxDim = $kind === WeathermapAsset::KIND_ICON ? 512 : 4096;
        $sanitizedPng = $this->gdRenderer->sanitizeUploadedImage($rawBytes, $maxDim);
        $sha256 = hash('sha256', $sanitizedPng);

        $relPath = "assets/{$sha256}.png";
        $absPath = $this->rendererService->storageBaseDir().'/'.$relPath;
        File::ensureDirectoryExists(dirname($absPath), 0750);
        if (! is_file($absPath)) {
            file_put_contents($absPath, $sanitizedPng);
        }

        $existing = WeathermapAsset::query()
            ->where('sha256', $sha256)
            ->where('kind', $kind)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return WeathermapAsset::query()->create([
            'kind' => $kind,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'path' => $relPath,
            'mime' => 'image/png',
            'size' => strlen($sanitizedPng),
            'sha256' => $sha256,
            'created_by' => $userId,
        ]);
    }
}
