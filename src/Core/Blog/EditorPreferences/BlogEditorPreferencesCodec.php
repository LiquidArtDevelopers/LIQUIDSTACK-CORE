<?php

declare(strict_types=1);

namespace App\Core\Blog\EditorPreferences;

use App\Core\Blog\StructuredContent\Presentation\BlogHeadingPresetCatalog;
use JsonException;
use Throwable;

/** Strict canonical JSON boundary for the persisted singleton. */
final class BlogEditorPreferencesCodec
{
    public const MAX_BYTES = 4096;

    public function __construct(
        private readonly BlogHeadingPresetCatalog $catalog =
            new BlogHeadingPresetCatalog()
    ) {
    }

    public function encode(BlogEditorPreferences $preferences): string
    {
        try {
            $json = json_encode(
                $preferences->toCanonicalArray(),
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            );
        } catch (JsonException) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::INVALID_INPUT
            );
        }
        if (strlen($json) < 1 || strlen($json) > self::MAX_BYTES) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::INVALID_INPUT
            );
        }

        return $json;
    }

    public function decode(string $json): BlogEditorPreferences
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::INVALID_INPUT
            );
        }
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($decoded)
                || array_keys($decoded) !== [
                    'schema',
                    'version',
                    'heading_defaults',
                ]
                || ($decoded['schema'] ?? null)
                    !== BlogEditorPreferences::SCHEMA
                || ($decoded['version'] ?? null)
                    !== BlogEditorPreferences::VERSION
                || !is_array($decoded['heading_defaults'] ?? null)
            ) {
                throw new BlogEditorPreferencesException(
                    BlogEditorPreferencesException::INVALID_INPUT
                );
            }
            $preferences = new BlogEditorPreferences(
                $decoded['heading_defaults'],
                $this->catalog
            );
            if (!hash_equals($this->encode($preferences), $json)) {
                throw new BlogEditorPreferencesException(
                    BlogEditorPreferencesException::INVALID_INPUT
                );
            }

            return $preferences;
        } catch (BlogEditorPreferencesException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new BlogEditorPreferencesException(
                BlogEditorPreferencesException::INVALID_INPUT
            );
        }
    }
}
