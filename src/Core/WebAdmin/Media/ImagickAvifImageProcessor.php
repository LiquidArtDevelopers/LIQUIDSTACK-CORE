<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use App\Core\Http\UploadedFile;
use finfo;
use Throwable;

final class ImagickAvifImageProcessor implements MediaImageProcessorInterface
{
    public const QUALITY = 74;
    public const MASTER_LIMIT = 2560;
    private const MIME_FORMATS = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WEBP',
    ];

    public function process(
        UploadedFile $upload,
        string $stagingDirectory
    ): ProcessedMediaUpload {
        if (!self::runtimeIsReady()) {
            throw new MediaException('webadmin.media.imagick_avif_unavailable');
        }
        if (
            !is_dir($stagingDirectory)
            || is_link($stagingDirectory)
            || !is_writable($stagingDirectory)
        ) {
            throw new MediaException('webadmin.media.staging_invalid');
        }

        $path = $upload->temporaryPath();
        try {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!is_string($mime) || !isset(self::MIME_FORMATS[$mime])) {
                throw new MediaException('webadmin.media.source_type_rejected');
            }
            $this->assertCanonicalContainer($path, $mime, $upload->size());

            $probe = new \Imagick();
            $probe->pingImage($path);
            if ($probe->getNumberImages() !== 1) {
                throw new MediaException('webadmin.media.source_multiframe_rejected');
            }
            $decoderFormat = strtoupper($probe->getImageFormat());
            $width = $probe->getImageWidth();
            $height = $probe->getImageHeight();
            $probe->clear();
            $probe->destroy();
            if (
                $decoderFormat !== self::MIME_FORMATS[$mime]
                || $width < 1 || $width > 12_000
                || $height < 1 || $height > 12_000
                || ($width * $height) > 40_000_000
            ) {
                throw new MediaException('webadmin.media.source_contract_rejected');
            }

            $image = new \Imagick();
            $image->readImage($path);
            if ($image->getNumberImages() !== 1) {
                throw new MediaException('webadmin.media.source_multiframe_rejected');
            }
            $image->setIteratorIndex(0);
            if (method_exists($image, 'autoOrientImage')) {
                $image->autoOrientImage();
            }
            $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            if ($image->getImageColorspace() !== \Imagick::COLORSPACE_SRGB) {
                $image->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
            $image->setImageColorspace(\Imagick::COLORSPACE_SRGB);
            $image->stripImage();
            $image->setImagePage(0, 0, 0, 0);

            $masterWidth = $image->getImageWidth();
            $masterHeight = $image->getImageHeight();
            $scale = min(
                1.0,
                self::MASTER_LIMIT / $masterWidth,
                self::MASTER_LIMIT / $masterHeight
            );
            if ($scale < 1.0) {
                $masterWidth = max(1, (int) floor($masterWidth * $scale));
                $masterHeight = max(1, (int) floor($masterHeight * $scale));
                $image->resizeImage(
                    $masterWidth,
                    $masterHeight,
                    \Imagick::FILTER_LANCZOS,
                    1.0,
                    true
                );
            }
            $masterWidth = $image->getImageWidth();
            $masterHeight = $image->getImageHeight();

            $widths = [];
            foreach ([480, 900, 1800, $masterWidth] as $target) {
                $widths[min($target, $masterWidth)] = true;
            }
            $widths = array_keys($widths);
            sort($widths, SORT_NUMERIC);
            $variants = [];
            foreach ($widths as $targetWidth) {
                $variant = clone $image;
                if ($targetWidth < $masterWidth) {
                    $targetHeight = max(1, (int) round(
                        $masterHeight * ($targetWidth / $masterWidth)
                    ));
                    $variant->resizeImage(
                        $targetWidth,
                        $targetHeight,
                        \Imagick::FILTER_LANCZOS,
                        1.0,
                        true
                    );
                }
                $variant->stripImage();
                $variant->setImagePage(0, 0, 0, 0);
                $variant->setImageFormat('AVIF');
                $variant->setImageCompressionQuality(self::QUALITY);
                $variant->setOption('heic:speed', '5');
                $actualWidth = $variant->getImageWidth();
                $actualHeight = $variant->getImageHeight();
                $file = $stagingDirectory . DIRECTORY_SEPARATOR
                    . $actualWidth . '.avif';
                if (file_exists($file) || is_link($file)
                    || !$variant->writeImage($file)) {
                    throw new MediaException('webadmin.media.encode_failed');
                }
                $variant->clear();
                $variant->destroy();
                $variants[] = $this->verifiedVariant(
                    $file,
                    $actualWidth,
                    $actualHeight
                );
            }
            $image->clear();
            $image->destroy();

            return new ProcessedMediaUpload(
                $mime,
                $width,
                $height,
                $upload->size(),
                hash_file('sha256', $path),
                $variants
            );
        } catch (MediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new MediaException('webadmin.media.processing_failed');
        }
    }

    public static function runtimeIsReady(): bool
    {
        return self::runtimeDiagnostic()['ready'];
    }

    /**
     * Performs an in-memory AVIF encode/decode probe without touching project
     * files and exposes only capability booleans.
     *
     * @return array{
     *   ready: bool,fileinfo: bool,imagick: bool,
     *   jpeg_decode: bool,png_decode: bool,webp_decode: bool,
     *   avif_encode_decode: bool
     * }
     */
    public static function runtimeDiagnostic(): array
    {
        $fileinfo = extension_loaded('fileinfo') && class_exists(finfo::class);
        $imagick = extension_loaded('imagick') && class_exists('Imagick');
        $formats = [
            'JPEG' => false,
            'PNG' => false,
            'WEBP' => false,
        ];
        $avifRoundTrip = false;
        if (!$fileinfo || !$imagick) {
            return [
                'ready' => false,
                'fileinfo' => $fileinfo,
                'imagick' => $imagick,
                'jpeg_decode' => false,
                'png_decode' => false,
                'webp_decode' => false,
                'avif_encode_decode' => false,
            ];
        }
        try {
            foreach (array_keys($formats) as $format) {
                $formats[$format] = \Imagick::queryFormats($format) !== [];
            }
            if (\Imagick::queryFormats('AVIF') !== []) {
                $source = new \Imagick();
                $decoded = new \Imagick();
                try {
                    // Some libheif builds cannot reopen a 2x2 AVIF because
                    // their clean-aperture minimum is larger. A tiny 16x16
                    // opaque probe exercises the same codec path reliably.
                    $source->newImage(16, 16, new \ImagickPixel('white'));
                    $source->setImageColorspace(\Imagick::COLORSPACE_SRGB);
                    $source->setImageFormat('AVIF');
                    $source->setImageCompressionQuality(self::QUALITY);
                    $blob = $source->getImageBlob();
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($blob);
                    $decoded->readImageBlob($blob);
                    $avifRoundTrip = is_string($blob) && $blob !== ''
                        && $mime === 'image/avif'
                        && $decoded->getNumberImages() === 1
                        && strtoupper($decoded->getImageFormat()) === 'AVIF'
                        && $decoded->getImageWidth() === 16
                        && $decoded->getImageHeight() === 16;
                } finally {
                    $source->clear();
                    $source->destroy();
                    $decoded->clear();
                    $decoded->destroy();
                }
            }
        } catch (Throwable) {
            $avifRoundTrip = false;
        }

        return [
            'ready' => !in_array(false, $formats, true) && $avifRoundTrip,
            'fileinfo' => true,
            'imagick' => true,
            'jpeg_decode' => $formats['JPEG'],
            'png_decode' => $formats['PNG'],
            'webp_decode' => $formats['WEBP'],
            'avif_encode_decode' => $avifRoundTrip,
        ];
    }

    private function verifiedVariant(
        string $path,
        int $expectedWidth,
        int $expectedHeight
    ): ProcessedMediaVariant {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime !== 'image/avif') {
            throw new MediaException('webadmin.media.avif_verification_failed');
        }
        $this->assertAvifContainer($path);
        $image = new \Imagick();
        $image->pingImage($path);
        $profiles = $image->getImageProfiles('*', false);
        $sensitiveProperties = array_merge(
            $image->getImageProperties('exif:*', false),
            $image->getImageProperties('iptc:*', false),
            $image->getImageProperties('xmp:*', false),
            $image->getImageProperties('comment', false)
        );
        $valid = $image->getNumberImages() === 1
            && strtoupper($image->getImageFormat()) === 'AVIF'
            && $image->getImageWidth() === $expectedWidth
            && $image->getImageHeight() === $expectedHeight
            && $profiles === []
            && $sensitiveProperties === [];
        $image->clear();
        $image->destroy();
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if (!$valid || !is_int($bytes) || $bytes < 1 || !is_string($sha256)) {
            throw new MediaException('webadmin.media.avif_verification_failed');
        }

        return new ProcessedMediaVariant(
            $expectedWidth,
            $expectedHeight,
            $bytes,
            $sha256,
            $expectedWidth . '.avif'
        );
    }

    private function assertCanonicalContainer(
        string $path,
        string $mime,
        int $size
    ): void {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new MediaException('webadmin.media.source_unreadable');
        }
        try {
            $head = fread($handle, min($size, 32));
            if (!is_string($head)) {
                throw new MediaException('webadmin.media.source_unreadable');
            }
            if ($mime === 'image/jpeg') {
                if (!str_starts_with($head, "\xFF\xD8\xFF")) {
                    throw new MediaException('webadmin.media.source_signature_mismatch');
                }
                fseek($handle, -2, SEEK_END);
                if (fread($handle, 2) !== "\xFF\xD9") {
                    throw new MediaException('webadmin.media.source_polyglot_rejected');
                }
                return;
            }
            if ($mime === 'image/png') {
                if (!str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
                    throw new MediaException('webadmin.media.source_signature_mismatch');
                }
                $this->assertPngChunks($handle, $size);
                return;
            }
            if (
                substr($head, 0, 4) !== 'RIFF'
                || substr($head, 8, 4) !== 'WEBP'
                || strlen($head) < 12
            ) {
                throw new MediaException('webadmin.media.source_signature_mismatch');
            }
            $declared = unpack('Vsize', substr($head, 4, 4));
            if (!is_array($declared) || ($declared['size'] ?? -1) + 8 !== $size) {
                throw new MediaException('webadmin.media.source_polyglot_rejected');
            }
            $this->assertWebpChunks($handle, $size);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function assertWebpChunks($handle, int $size): void
    {
        fseek($handle, 12);
        $offset = 12;
        $sawImagePayload = false;
        while ($offset + 8 <= $size) {
            $header = fread($handle, 8);
            if (!is_string($header) || strlen($header) !== 8) {
                throw new MediaException('webadmin.media.source_container_invalid');
            }
            $type = substr($header, 0, 4);
            $parts = unpack('Vlength', substr($header, 4, 4));
            $length = is_array($parts) ? ($parts['length'] ?? -1) : -1;
            $paddedLength = is_int($length) ? $length + ($length % 2) : -1;
            if ($length < 0 || $paddedLength < 0
                || $offset + 8 + $paddedLength > $size) {
                throw new MediaException('webadmin.media.source_container_invalid');
            }
            if (in_array($type, ['ANIM', 'ANMF'], true)) {
                throw new MediaException('webadmin.media.source_animation_rejected');
            }
            if ($type === 'VP8X') {
                if ($length !== 10) {
                    throw new MediaException('webadmin.media.source_container_invalid');
                }
                $flags = fread($handle, 1);
                if (!is_string($flags) || strlen($flags) !== 1) {
                    throw new MediaException('webadmin.media.source_container_invalid');
                }
                if ((ord($flags) & 0x02) !== 0) {
                    throw new MediaException('webadmin.media.source_animation_rejected');
                }
                fseek($handle, $paddedLength - 1, SEEK_CUR);
            } else {
                if (in_array($type, ['VP8 ', 'VP8L'], true)) {
                    $sawImagePayload = true;
                }
                fseek($handle, $paddedLength, SEEK_CUR);
            }
            $offset += 8 + $paddedLength;
        }
        if ($offset !== $size || !$sawImagePayload) {
            throw new MediaException('webadmin.media.source_polyglot_rejected');
        }
    }

    /** @param resource $handle */
    private function assertPngChunks($handle, int $size): void
    {
        fseek($handle, 8);
        $offset = 8;
        $sawIend = false;
        while ($offset + 12 <= $size) {
            $header = fread($handle, 8);
            if (!is_string($header) || strlen($header) !== 8) {
                break;
            }
            $parts = unpack('Nlength/a4type', $header);
            $length = is_array($parts) ? ($parts['length'] ?? -1) : -1;
            $type = is_array($parts) ? ($parts['type'] ?? '') : '';
            if (!is_int($length) || $length < 0 || $offset + 12 + $length > $size) {
                throw new MediaException('webadmin.media.source_container_invalid');
            }
            if (in_array($type, ['acTL', 'fcTL', 'fdAT'], true)) {
                throw new MediaException('webadmin.media.source_animation_rejected');
            }
            fseek($handle, $length + 4, SEEK_CUR);
            $offset += 12 + $length;
            if ($type === 'IEND') {
                $sawIend = true;
                break;
            }
        }
        if (!$sawIend || $offset !== $size) {
            throw new MediaException('webadmin.media.source_polyglot_rejected');
        }
    }

    private function assertAvifContainer(string $path): void
    {
        $head = file_get_contents($path, false, null, 0, 32);
        if (!is_string($head) || strlen($head) < 16
            || substr($head, 4, 4) !== 'ftyp') {
            throw new MediaException('webadmin.media.avif_verification_failed');
        }
        $brands = substr($head, 8);
        if (!str_contains($brands, 'avif') && !str_contains($brands, 'avis')) {
            throw new MediaException('webadmin.media.avif_verification_failed');
        }
    }
}
