<?php
/**
 * GD-based cropping for product images. Requires the gd extension (flagged
 * on the install requirements screen if missing).
 */

class ImageProcessor
{
    private const MIME_LOADERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/gif'  => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
    ];

    public static function isSupported(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Crop $sourcePath to the rectangle (x,y,w,h), resample to
     * $targetWidth x $targetHeight, and save as a new file in the same
     * directory. Returns the generated filename (including its extension,
     * which is chosen based on the actual encoder used - not necessarily
     * $outputBasename's original extension), or throws RuntimeException.
     */
    public static function cropAndSave(
        string $sourcePath,
        int $x,
        int $y,
        int $cropWidth,
        int $cropHeight,
        int $targetWidth,
        int $targetHeight,
        string $outputBasename
    ): string {
        if (!self::isSupported()) {
            throw new RuntimeException('The GD extension is not available on this server.');
        }
        if (!is_file($sourcePath)) {
            throw new RuntimeException('Source image not found.');
        }

        $info = getimagesize($sourcePath);
        if (!$info) {
            throw new RuntimeException('Could not read image dimensions.');
        }
        [$origWidth, $origHeight] = $info;
        $mime = $info['mime'];

        if (!isset(self::MIME_LOADERS[$mime])) {
            throw new RuntimeException('Unsupported image type: ' . $mime);
        }

        // Clamp the crop rectangle to the actual image bounds so a stale
        // client-side selection can never read/write out of range.
        $x = max(0, min($x, $origWidth - 1));
        $y = max(0, min($y, $origHeight - 1));
        $cropWidth = max(1, min($cropWidth, $origWidth - $x));
        $cropHeight = max(1, min($cropHeight, $origHeight - $y));
        $targetWidth = max(1, min($targetWidth, 4000));
        $targetHeight = max(1, min($targetHeight, 4000));

        $loader = self::MIME_LOADERS[$mime];
        $source = $loader($sourcePath);
        if (!$source) {
            throw new RuntimeException('Could not load source image.');
        }

        $dest = imagecreatetruecolor($targetWidth, $targetHeight);

        // Preserve transparency for PNG/GIF/WebP.
        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'], true)) {
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
            $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
            imagefilledrectangle($dest, 0, 0, $targetWidth, $targetHeight, $transparent);
        } else {
            $white = imagecolorallocate($dest, 255, 255, 255);
            imagefilledrectangle($dest, 0, 0, $targetWidth, $targetHeight, $white);
        }

        imagecopyresampled($dest, $source, 0, 0, $x, $y, $targetWidth, $targetHeight, $cropWidth, $cropHeight);

        // Extension always matches the encoder actually used, so a WebP
        // source whose server lacks imagewebp() still gets a correct
        // .jpg file rather than a JPEG-encoded file misleadingly named .webp.
        $canWebp = $mime === 'image/webp' && function_exists('imagewebp');
        $extension = match (true) {
            $mime === 'image/png' => 'png',
            $mime === 'image/gif' => 'gif',
            $canWebp => 'webp',
            default => 'jpg',
        };
        $outputFilename = $outputBasename . '.' . $extension;
        $destPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $outputFilename;

        $saved = match (true) {
            $extension === 'png' => imagepng($dest, $destPath, 6),
            $extension === 'gif' => imagegif($dest, $destPath),
            $extension === 'webp' => imagewebp($dest, $destPath, 90),
            default => imagejpeg($dest, $destPath, 90),
        };

        imagedestroy($source);
        imagedestroy($dest);

        if (!$saved) {
            throw new RuntimeException('Could not write cropped image to disk.');
        }

        return $outputFilename;
    }
}
