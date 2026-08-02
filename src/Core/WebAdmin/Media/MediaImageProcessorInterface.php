<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Media;

use App\Core\Http\UploadedFile;

interface MediaImageProcessorInterface
{
    public function process(
        UploadedFile $upload,
        string $stagingDirectory
    ): ProcessedMediaUpload;
}
