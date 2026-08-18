<?php

declare(strict_types=1);

namespace App\Core\Blog\Tags;

final class BlogTagCapabilities
{
    public const VIEW = 'blog.tags.view';
    public const EDIT = 'blog.tags.edit';
    public const VIEW_LABEL = 'blog.capabilities.tags_view';
    public const EDIT_LABEL = 'blog.capabilities.tags_edit';

    private function __construct()
    {
    }
}
