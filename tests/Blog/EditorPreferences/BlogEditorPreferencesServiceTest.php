<?php

declare(strict_types=1);

namespace Tests\Blog\EditorPreferences;

use App\Core\Blog\EditorPreferences\Audit\BlogEditorPreferencesAuditEvent;
use App\Core\Blog\EditorPreferences\Audit\BlogEditorPreferencesAuditPortInterface;
use App\Core\Blog\EditorPreferences\BlogEditorPreferences;
use App\Core\Blog\EditorPreferences\BlogEditorPreferencesCodec;
use App\Core\Blog\EditorPreferences\BlogEditorPreferencesException;
use App\Core\Blog\EditorPreferences\BlogEditorPreferencesService;
use App\Core\Blog\EditorPreferences\Audit\WebAdminBlogEditorPreferencesAuditAdapter;
use App\Core\Blog\EditorPreferences\Persistence\BlogEditorPreferencesPersistenceException;
use App\Core\Blog\EditorPreferences\Persistence\PdoBlogEditorPreferencesRepository;
use App\Core\Modules\Blog\BlogMigrationProvider;
use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\Modules\WebAdmin\WebAdminMigrationProvider;
use App\Core\WebAdmin\Support\ClockInterface;
use App\Core\WebAdmin\Support\UuidGeneratorInterface;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\TestCase;

final class BlogEditorPreferencesServiceTest extends TestCase
{
    private const ACTOR = '41000000-0000-4000-8000-000000000001';

    public function testCanonicalDefaultsCoverCompleteStyleForEveryLevel(): void
    {
        $preferences = BlogEditorPreferences::defaults();
        $codec = new BlogEditorPreferencesCodec();
        $expectedStyle = [
            'preset' => 'default',
            'font_size' => 'default',
            'font_weight' => 'default',
            'text_color' => 'default',
            'text_align' => 'start',
        ];
        self::assertSame([
            'h2' => $expectedStyle,
            'h3' => $expectedStyle,
            'h4' => $expectedStyle,
            'h5' => $expectedStyle,
            'h6' => $expectedStyle,
        ], $preferences->headingDefaults());

        $json = $codec->encode($preferences);
        self::assertSame($json, $codec->encode($codec->decode($json)));
        self::assertSame('default', $preferences->headingDefault('h2')->preset());
    }

    public function testClosedAllowlistsRejectUnknownStyleValues(): void
    {
        $styles = BlogEditorPreferences::defaults()->headingDefaults();
        $styles['h4']['font_weight'] = '900';

        $this->expectException(BlogEditorPreferencesException::class);
        new BlogEditorPreferences($styles);
    }

    public function testGlobalHeadingColorsRejectRgbaAndUnknownTokens(): void
    {
        foreach (['color06', 'rgba(1, 2, 3, 0.5)'] as $color) {
            $styles = BlogEditorPreferences::defaults()->headingDefaults();
            $styles['h4']['text_color'] = $color;

            try {
                new BlogEditorPreferences($styles);
                self::fail('Global heading defaults must reject ' . $color);
            } catch (BlogEditorPreferencesException $exception) {
                self::assertSame(
                    BlogEditorPreferencesException::INVALID_INPUT,
                    $exception->issueCode()
                );
            }
        }
    }

    public function testColorPaletteExtendsWithoutBreakingHistoricalJson(): void
    {
        $codec = new BlogEditorPreferencesCodec();
        $style = '{"preset":"default","font_size":"default",'
            . '"font_weight":"default","text_color":"color03",'
            . '"text_align":"start"}';
        $historicalJson = '{"schema":"liquidstack.blog.editor-preferences",'
            . '"version":1,"heading_defaults":{"h2":' . $style
            . ',"h3":' . $style . ',"h4":' . $style . ',"h5":'
            . $style . ',"h6":' . $style . '}}';

        $historical = $codec->decode($historicalJson);
        self::assertSame('color03', $historical->headingDefault('h6')->textColor());
        self::assertSame($historicalJson, $codec->encode($historical));

        $styles = $historical->headingDefaults();
        $styles['h2']['text_color'] = 'color04';
        $styles['h3']['text_color'] = 'color05';
        $extended = new BlogEditorPreferences($styles);
        $roundTrip = $codec->decode($codec->encode($extended));

        self::assertSame('color04', $roundTrip->headingDefault('h2')->textColor());
        self::assertSame('color05', $roundTrip->headingDefault('h3')->textColor());
    }

    public function testAbsenceFallsBackThenSaveUsesOptimisticLockAndAudit(): void
    {
        [$pdo, $repository] = $this->repository();
        $audit = new RecordingPreferencesAudit();
        $service = new BlogEditorPreferencesService(
            $repository,
            new FixedPreferencesClock($this->now()),
            $audit
        );

        $fallback = $service->current();
        self::assertFalse($fallback->isPersisted());
        self::assertSame(0, $fallback->lockVersion());

        $styles = $fallback->preferences()->headingDefaults();
        $styles['h2'] = [
            'preset' => 'accent-line',
            'font_size' => 'xlarge',
            'font_weight' => 'bold',
            'text_color' => 'color02',
            'text_align' => 'center',
        ];
        $updated = new BlogEditorPreferences($styles);
        $actorGate = static function (PDO $transaction) use ($pdo): string {
            self::assertSame($pdo, $transaction);
            self::assertTrue($transaction->inTransaction());

            return self::ACTOR;
        };
        $stored = $service->save($actorGate, 0, $updated);
        self::assertTrue($stored->isPersisted());
        self::assertSame(1, $stored->lockVersion());
        self::assertSame('accent-line', $stored->preferences()
            ->headingDefault('h2')->preset());
        self::assertCount(1, $audit->events);
        self::assertFalse($pdo->inTransaction());

        $same = $service->save($actorGate, 1, $updated);
        self::assertSame(1, $same->lockVersion());
        self::assertCount(1, $audit->events, 'No-op saves are not audited.');

        $styles['h3']['text_align'] = 'justify';
        $changed = $service->save(
            $actorGate,
            1,
            new BlogEditorPreferences($styles)
        );
        self::assertSame(2, $changed->lockVersion());
        self::assertCount(2, $audit->events);

        try {
            $service->save($actorGate, 1, $updated);
            self::fail('A stale lock must fail.');
        } catch (BlogEditorPreferencesException $exception) {
            self::assertSame(
                BlogEditorPreferencesException::LOCK_CONFLICT,
                $exception->issueCode()
            );
        }
        self::assertSame(2, $service->current()->lockVersion());
        self::assertSame(2, (int) $pdo->query(
            'SELECT lock_version FROM prefs_blog_editor_preferences'
        )->fetchColumn());
    }

    public function testActorGateFailureIsNotCollapsedIntoStorageFailure(): void
    {
        [, $repository] = $this->repository();
        $service = new BlogEditorPreferencesService(
            $repository,
            new FixedPreferencesClock($this->now())
        );

        try {
            $service->save(
                static fn (PDO $pdo): string => throw new \RuntimeException(),
                0,
                BlogEditorPreferences::defaults()
            );
            self::fail('The actor gate must fail closed.');
        } catch (BlogEditorPreferencesException $exception) {
            self::assertSame(
                BlogEditorPreferencesException::ACTOR_GATE_FAILED,
                $exception->issueCode()
            );
        }
        self::assertFalse($service->current()->isPersisted());
    }

    public function testRepositoryRejectsAnInvalidActorBeforeWriting(): void
    {
        [$pdo, $repository] = $this->repository();

        try {
            $repository->transactional(
                function () use ($repository): void {
                    $repository->insertGlobal(
                        BlogEditorPreferences::defaults(),
                        'not-a-public-uuid',
                        $this->now()
                    );
                }
            );
            self::fail('Invalid actor identifiers must fail closed.');
        } catch (BlogEditorPreferencesPersistenceException) {
            self::assertFalse($pdo->inTransaction());
        }

        self::assertSame(0, (int) $pdo->query(
            'SELECT COUNT(*) FROM prefs_blog_editor_preferences'
        )->fetchColumn());
    }

    public function testWebAdminAuditAdapterRecordsNoPreferenceContent(): void
    {
        [$pdo, $repository] = $this->repository();
        $scope = MigrationScope::forTablePrefix(
            'webadmin',
            'prefs_admin_'
        );
        $webAdmin = iterator_to_array(
            WebAdminMigrationProvider::migrations(),
            false
        )[0];
        foreach ($webAdmin->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($pdo->exec($sql));
        }
        self::assertSame(1, $pdo->exec(
            "INSERT INTO prefs_admin_users "
                . '(public_id, email_canonical, display_name, status, '
                . 'auth_version) VALUES '
                . "('" . self::ACTOR . "', 'editor@example.test', NULL, "
                . "'active', 1)"
        ));
        $audit = new WebAdminBlogEditorPreferencesAuditAdapter(
            $pdo,
            WebAdminTableNames::fromPdo($pdo, 'prefs_admin_'),
            new FixedPreferencesUuid(
                '42000000-0000-4000-8000-000000000002'
            )
        );
        $service = new BlogEditorPreferencesService(
            $repository,
            new FixedPreferencesClock($this->now()),
            $audit
        );
        $service->save(
            static fn (PDO $transaction): string => self::ACTOR,
            0,
            BlogEditorPreferences::defaults()
        );

        self::assertSame([
            'event_code' => 'blog.settings.updated',
            'target_type' => 'blog_editor_preferences',
            'target_public_id' => null,
            'metadata_json' => null,
        ], $pdo->query(
            'SELECT event_code, target_type, target_public_id, metadata_json '
                . 'FROM prefs_admin_audit_log'
        )->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array{PDO, PdoBlogEditorPreferencesRepository} */
    private function repository(): array
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $scope = MigrationScope::forTablePrefix('blog', 'prefs_blog_');
        $migration = $this->migration('0012_blog_editor_preferences');
        foreach ($migration->statementsFor('sqlite', $scope) as $sql) {
            self::assertNotFalse($pdo->exec($sql));
        }

        return [
            $pdo,
            new PdoBlogEditorPreferencesRepository($pdo, $scope),
        ];
    }

    private function migration(string $id): MigrationDefinition
    {
        foreach (BlogMigrationProvider::migrations() as $migration) {
            if ($migration->id() === $id) {
                return $migration;
            }
        }

        self::fail('Missing migration ' . $id);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            '2026-08-05 12:00:00.000000',
            new DateTimeZone('UTC')
        );
    }
}

final class FixedPreferencesClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class RecordingPreferencesAudit implements
    BlogEditorPreferencesAuditPortInterface
{
    /** @var list<BlogEditorPreferencesAuditEvent> */
    public array $events = [];

    public function record(
        PDO $pdo,
        BlogEditorPreferencesAuditEvent $event
    ): void {
        TestCase::assertTrue($pdo->inTransaction());
        $this->events[] = $event;
    }
}

final class FixedPreferencesUuid implements UuidGeneratorInterface
{
    public function __construct(private readonly string $uuid)
    {
    }

    public function generateV4(): string
    {
        return $this->uuid;
    }
}
