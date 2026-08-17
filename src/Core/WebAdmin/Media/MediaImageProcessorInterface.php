<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use App\Core\Http\UploadedFile;

interface MediaImageProcessorInterface
{
    /**
     * The upload path is PHP-owned temporary input. Implementations may read
     * it, but must persist only sanitized derivatives inside $stagingDirectory.
     */
    public function process(
        UploadedFile $upload,
        string $stagingDirectory
    ): ProcessedMediaUpload;
}
