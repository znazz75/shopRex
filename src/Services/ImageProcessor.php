<?php

namespace ShopRex\Services;

/**
 * GD-based cropping for product images - lets an admin pick a rectangle
 * out of an uploaded product photo (in Admin -> Products -> edit's image
 * cropper UI) and saves that rectangle as a new, resized image file.
 * Requires PHP's built-in gd extension (flagged on the install
 * requirements screen if missing, and cropAndSave() below throws if it's
 * unavailable at runtime).
 *
 * Static-methods-only by design (no per-request state) -
 * Controllers\Admin\ImageCropController calls isSupported()/cropAndSave()
 * directly.
 */
final class ImageProcessor
{
    // Maps an image MIME type to the specific GD "load from file" function
    // that can decode it - looked up by cropAndSave() below so the right
    // decoder is picked automatically based on what the file actually is.
    private const MIME_LOADERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/gif'  => 'imagecreatefromgif',
        'image/webp' => 'imagecreatefromwebp',
    ];

    /** Whether the server's PHP build has the gd extension loaded - cropping is impossible without it. */
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
            throw new \RuntimeException('The GD extension is not available on this server.');
        }
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Source image not found.');
        }

        // getimagesize() reads the file's actual header bytes (not its
        // filename/extension) to determine its true dimensions and MIME
        // type - this is what MIME_LOADERS is keyed on below, so a
        // mislabelled or renamed file still gets decoded correctly (or
        // safely rejected if it isn't really an image at all).
        $info = getimagesize($sourcePath);
        if (!$info) {
            throw new \RuntimeException('Could not read image dimensions.');
        }
        [$origWidth, $origHeight] = $info;
        $mime = $info['mime'];

        if (!isset(self::MIME_LOADERS[$mime])) {
            throw new \RuntimeException('Unsupported image type: ' . $mime);
        }

        // Clamp the crop rectangle to the actual image bounds so a stale
        // client-side selection can never read/write out of range.
        $x = max(0, min($x, $origWidth - 1));
        $y = max(0, min($y, $origHeight - 1));
        $cropWidth = max(1, min($cropWidth, $origWidth - $x));
        $cropHeight = max(1, min($cropHeight, $origHeight - $y));
        // Also cap the requested output size - an absurdly large target
        // (malicious or just a typo) could otherwise exhaust memory
        // building the destination canvas below.
        $targetWidth = max(1, min($targetWidth, 4000));
        $targetHeight = max(1, min($targetHeight, 4000));

        // Pick the GD decoder function matching this file's real MIME type
        // (see MIME_LOADERS above) and use it to load the source image into
        // memory as a GD image resource.
        $loader = self::MIME_LOADERS[$mime];
        $source = $loader($sourcePath);
        if (!$source) {
            throw new \RuntimeException('Could not load source image.');
        }

        // Blank canvas at the final (cropped+resized) dimensions - the
        // source is drawn onto this below via imagecopyresampled().
        $dest = imagecreatetruecolor($targetWidth, $targetHeight);

        // Preserve transparency for PNG/GIF/WebP: turn off GD's default
        // alpha-blending so transparent pixels are copied as-is rather than
        // blended, tell GD to actually save the alpha channel, and fill the
        // canvas with a fully-transparent color first. Formats without
        // transparency (JPEG) instead get a plain white background, since
        // JPEG has no alpha channel to lose data into.
        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'], true)) {
            imagealphablending($dest, false);
            imagesavealpha($dest, true);
            $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
            imagefilledrectangle($dest, 0, 0, $targetWidth, $targetHeight, $transparent);
        } else {
            $white = imagecolorallocate($dest, 255, 255, 255);
            imagefilledrectangle($dest, 0, 0, $targetWidth, $targetHeight, $white);
        }

        // The actual crop+resize: copies the ($x,$y,$cropWidth,$cropHeight)
        // rectangle out of $source and resamples (smoothly scales) it to
        // fill the $targetWidth x $targetHeight canvas.
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

        // Encode and write the file to disk using whichever GD function
        // matches the chosen extension - the second argument tunes
        // compression/quality per format (6 = mid-range PNG compression,
        // 90 = high-quality lossy JPEG/WebP).
        $saved = match (true) {
            $extension === 'png' => imagepng($dest, $destPath, 6),
            $extension === 'gif' => imagegif($dest, $destPath),
            $extension === 'webp' => imagewebp($dest, $destPath, 90),
            default => imagejpeg($dest, $destPath, 90),
        };

        // GD image resources are not garbage-collected automatically the
        // way plain PHP variables are - free the memory explicitly now that
        // both images have served their purpose.
        imagedestroy($source);
        imagedestroy($dest);

        if (!$saved) {
            throw new \RuntimeException('Could not write cropped image to disk.');
        }

        return $outputFilename;
    }
}
