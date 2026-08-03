<?php

declare(strict_types=1);

namespace Tests\Blog\StructuredContent;

use App\Core\Blog\Categories\BlogCategoryDraft;
use App\Core\Blog\Categories\BlogCategoryException;
use App\Core\Blog\Categories\BlogCategoryLocalization;
use App\Core\Blog\Categories\BlogCategoryService;
use App\Core\Blog\Categories\Persistence\BlogCategoryRepositoryInterface;
use App\Core\Blog\StructuredContent\Categories\BlogCategoryEditorCatalogAdapter;
use App\Core\Blog\StructuredContent\Rendering\BlogEditorCategoryOption;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BlogEditorCategoryCatalogTest extends TestCase
{
    public function testCatalogProjectsLocalizedChoicesAndAssignments(): void
    {
        $firstId = $this->id(1);
        $secondId = $this->id(2);
        $postId = $this->id(100);
        $repository = $this->createMock(BlogCategoryRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('assignedCategoryPublicIds')
            ->with($postId)
            ->willReturn([$secondId]);
        $repository->expects(self::once())
            ->method('listLocalizations')
            ->with(BlogCategoryService::MAX_ASSIGNMENTS, 0, 'es')
            ->willReturn([
                $this->localization($firstId, 11, 'es', 'Noticias'),
                $this->localization($secondId, 12, 'es', 'Fiscalidad'),
            ]);

        $options = (new BlogCategoryEditorCatalogAdapter(
            new BlogCategoryService($repository)
        ))->forPost($postId, 'es');

        self::assertCount(2, $options);
        self::assertSame($firstId, $options[0]->publicId());
        self::assertSame('Noticias', $options[0]->name());
        self::assertFalse($options[0]->assigned());
        self::assertSame($secondId, $options[1]->publicId());
        self::assertTrue($options[1]->assigned());
    }

    public function testCatalogRejectsCorruptedOrCrossLocaleStorageData(): void
    {
        $categoryId = $this->id(1);
        $postId = $this->id(100);
        $repository = $this->createMock(BlogCategoryRepositoryInterface::class);
        $repository->method('assignedCategoryPublicIds')->willReturn([]);
        $repository->method('listLocalizations')->willReturn([
            $this->localization($categoryId, 11, 'eu', 'Albisteak'),
        ]);

        try {
            (new BlogCategoryEditorCatalogAdapter(
                new BlogCategoryService($repository)
            ))->forPost($postId, 'es');
            self::fail('Cross-locale category data must fail closed.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }
    }

    public function testCatalogFailsClosedWhenAnAssignmentIsNotProjected(): void
    {
        $assignedId = $this->id(2);
        $postId = $this->id(100);
        $repository = $this->createMock(BlogCategoryRepositoryInterface::class);
        $repository->method('assignedCategoryPublicIds')
            ->willReturn([$assignedId]);
        $repository->method('listLocalizations')->willReturn([
            $this->localization($this->id(1), 11, 'es', 'Noticias'),
        ]);

        try {
            (new BlogCategoryEditorCatalogAdapter(
                new BlogCategoryService($repository)
            ))->forPost($postId, 'es');
            self::fail('A partial assignment projection must fail closed.');
        } catch (BlogCategoryException $exception) {
            self::assertSame(
                BlogCategoryException::STORAGE_UNAVAILABLE,
                $exception->issueCode()
            );
        }
    }

    public function testPresentationOptionRejectsUnsafeValues(): void
    {
        $option = new BlogEditorCategoryOption(
            $this->id(1),
            'Noticias & actualidad',
            true
        );
        self::assertSame('Noticias & actualidad', $option->name());
        self::assertTrue($option->assigned());

        foreach ([
            ['bad-id', 'Noticias'],
            [$this->id(1), ''],
            [$this->id(1), ' Noticias'],
            [$this->id(1), "Noticias\nlocales"],
        ] as [$publicId, $name]) {
            try {
                new BlogEditorCategoryOption($publicId, $name, false);
                self::fail('Unsafe category presentation must be rejected.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame(
                    'Invalid Blog editor category option.',
                    $exception->getMessage()
                );
            }
        }
    }

    private function localization(
        string $categoryId,
        int $localizationSeed,
        string $locale,
        string $name
    ): BlogCategoryLocalization {
        return new BlogCategoryLocalization(
            $categoryId,
            $this->id($localizationSeed),
            $locale,
            new BlogCategoryDraft($name, strtolower($name)),
            1,
            new DateTimeImmutable('2026-08-03T10:00:00Z')
        );
    }

    private function id(int $seed): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $seed);
    }
}
