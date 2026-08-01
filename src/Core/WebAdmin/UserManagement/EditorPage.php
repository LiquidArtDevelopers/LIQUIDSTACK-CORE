<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\UserManagement;

use InvalidArgumentException;

final class EditorPage
{
    /** @var list<EditorSummary> */
    private array $editors;

    /** @param list<EditorSummary> $editors */
    public function __construct(array $editors, private readonly ?string $nextCursor)
    {
        foreach ($editors as $editor) {
            if (!$editor instanceof EditorSummary) {
                throw new InvalidArgumentException('Invalid editor page.');
            }
        }
        $this->editors = array_values($editors);
    }

    /** @return list<EditorSummary> */
    public function editors(): array
    {
        return $this->editors;
    }

    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }
}
