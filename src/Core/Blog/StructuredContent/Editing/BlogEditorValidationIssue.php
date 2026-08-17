<?php

declare(strict_types=1);

namespace App\Core\Blog\StructuredContent\Editing;

/** Stable, value-free diagnostic returned by asynchronous editor requests. */
final class BlogEditorValidationIssue
{
    public function __construct(
        private readonly string $scope,
        private readonly string $field,
        private readonly string $code,
        private readonly ?int $limit = null,
        private readonly ?string $blockId = null
    ) {
    }

    /** @return array{scope: string, field: string, block_id: ?string, code: string, limit: ?int} */
    public function toSafeArray(): array
    {
        return [
            'scope' => $this->scope,
            'field' => $this->field,
            'block_id' => $this->blockId,
            'code' => $this->code,
            'limit' => $this->limit,
        ];
    }
}
