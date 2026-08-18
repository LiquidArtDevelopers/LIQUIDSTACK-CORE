<?php

declare(strict_types=1);

use App\Core\Blog\EditorialWorkflow\BlogEditorialVariantState;
use App\Core\Blog\Tags\BlogTag;
use App\Core\Blog\Tags\BlogTagAssignmentWorkspaceState;
use App\Core\Blog\Tags\BlogTagException;
use App\Core\Blog\Tags\BlogTagService;
use App\Core\Blog\Tags\Persistence\BlogTagRepositoryInterface;
use App\Core\Blog\Tags\Persistence\PdoBlogTagRepository;
use App\Core\Database\SharedPdoConnectionFactory;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Configuration\WebAdminConfig;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');

/** @return never */
function finishTagWorker(array $payload, int $exitCode): void
{
    fwrite(STDOUT, json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit($exitCode);
}

function requiredTagWorkerEnvironment(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Missing isolated tag worker input.');
    }

    return $value;
}

function assertTagWorkerPath(string $path): void
{
    $temporaryRoot = realpath(sys_get_temp_dir());
    $parent = realpath(dirname($path));
    if (
        $temporaryRoot === false
        || $parent !== $temporaryRoot
        || preg_match(
            '/\Als-blog-tag-(?:(?:ready|insert)-(?:a|b)|start|insert-go)'
                . '-[a-f0-9]{16}\.tmp\z/D',
            basename($path)
        ) !== 1
    ) {
        throw new RuntimeException('Unsafe isolated tag worker path.');
    }
}

try {
    $autoload = requiredTagWorkerEnvironment(
        'LIQUIDSTACK_TEST_WORKER_AUTOLOAD'
    );
    if (!is_file($autoload)) {
        throw new RuntimeException('Invalid tag worker autoload path.');
    }
    require $autoload;
} catch (Throwable) {
    finishTagWorker(['status' => 'worker_failure'], 70);
}

/** Test-only decorator that releases both first INSERTs at the same instant. */
final class BlogMySqlTagBarrierRepository implements BlogTagRepositoryInterface
{
    private bool $insertBarrierUsed = false;

    public function __construct(
        private readonly BlogTagRepositoryInterface $delegate,
        private readonly string $insertMarker,
        private readonly string $insertStart
    ) {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->delegate->transactional($operation);
    }

    public function variantState(
        string $postPublicId,
        string $locale,
        bool $lock = false
    ): ?BlogEditorialVariantState {
        return $this->delegate->variantState($postPublicId, $locale, $lock);
    }

    public function tagByIdentity(
        string $locale,
        string $normalizedSha256,
        bool $lock = false
    ): ?BlogTag {
        return $this->delegate->tagByIdentity(
            $locale,
            $normalizedSha256,
            $lock
        );
    }

    public function tagBySlug(
        string $locale,
        string $slug,
        bool $lock = false
    ): ?BlogTag {
        return $this->delegate->tagBySlug($locale, $slug, $lock);
    }

    public function insertTag(
        string $publicId,
        string $locale,
        string $slug,
        string $name,
        string $normalizedSha256,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        if (!$this->insertBarrierUsed) {
            $this->insertBarrierUsed = true;
            if (file_put_contents($this->insertMarker, 'insert', LOCK_EX) !== 6) {
                throw new RuntimeException('Could not signal tag INSERT.');
            }
            $deadline = microtime(true) + 12.0;
            while (!is_file($this->insertStart) && microtime(true) < $deadline) {
                usleep(5_000);
            }
            if (!is_file($this->insertStart)) {
                throw new RuntimeException('Tag INSERT barrier timed out.');
            }
        }
        $this->delegate->insertTag(
            $publicId,
            $locale,
            $slug,
            $name,
            $normalizedSha256,
            $actorPublicId,
            $now
        );
    }

    public function liveTags(string $localizationPublicId): array
    {
        return $this->delegate->liveTags($localizationPublicId);
    }

    public function workspaceTags(string $localizationPublicId): ?array
    {
        return $this->delegate->workspaceTags($localizationPublicId);
    }

    public function assignmentVersion(
        string $localizationPublicId,
        bool $lock = false
    ): int {
        return $this->delegate->assignmentVersion(
            $localizationPublicId,
            $lock
        );
    }

    public function workspaceState(
        string $localizationPublicId,
        bool $lock = false
    ): ?BlogTagAssignmentWorkspaceState {
        return $this->delegate->workspaceState($localizationPublicId, $lock);
    }

    public function replaceWorkspace(
        string $localizationPublicId,
        array $tagPublicIds,
        int $expectedWorkspaceVersion,
        int $baseAssignmentVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int {
        return $this->delegate->replaceWorkspace(
            $localizationPublicId,
            $tagPublicIds,
            $expectedWorkspaceVersion,
            $baseAssignmentVersion,
            $actorPublicId,
            $now
        );
    }

    public function clearWorkspace(string $localizationPublicId): void
    {
        $this->delegate->clearWorkspace($localizationPublicId);
    }

    public function replaceLiveTags(
        string $localizationPublicId,
        array $tagPublicIds,
        string $actorPublicId,
        DateTimeImmutable $now
    ): void {
        $this->delegate->replaceLiveTags(
            $localizationPublicId,
            $tagPublicIds,
            $actorPublicId,
            $now
        );
    }

    public function promoteAssignments(
        string $localizationPublicId,
        int $expectedAssignmentVersion,
        string $actorPublicId,
        DateTimeImmutable $now
    ): int {
        return $this->delegate->promoteAssignments(
            $localizationPublicId,
            $expectedAssignmentVersion,
            $actorPublicId,
            $now
        );
    }
}

try {
    $ready = requiredTagWorkerEnvironment('LIQUIDSTACK_TEST_TAG_READY');
    $start = requiredTagWorkerEnvironment('LIQUIDSTACK_TEST_TAG_START');
    $insert = requiredTagWorkerEnvironment('LIQUIDSTACK_TEST_TAG_INSERT');
    $insertStart = requiredTagWorkerEnvironment(
        'LIQUIDSTACK_TEST_TAG_INSERT_START'
    );
    foreach ([$ready, $start, $insert, $insertStart] as $path) {
        assertTagWorkerPath($path);
    }

    $database = requiredTagWorkerEnvironment('LIQUIDSTACK_TEST_WORKER_DATABASE');
    if (
        preg_match('/\Aliquidstack_core_test_[a-z0-9_]{1,32}\z/', $database)
            !== 1
        || strlen($database) > 64
    ) {
        throw new RuntimeException('Unsafe isolated tag worker database.');
    }
    $prefix = requiredTagWorkerEnvironment('LIQUIDSTACK_TEST_WORKER_BLOG_PREFIX');
    if (preg_match('/\Alsit_blog_[a-f0-9]{16}_\z/', $prefix) !== 1) {
        throw new RuntimeException('Unsafe isolated tag worker prefix.');
    }

    $names = WebAdminConfig::SHARED_DATABASE_ENV;
    $connection = (new SharedPdoConnectionFactory([
        $names[0] => requiredTagWorkerEnvironment(
            'LIQUIDSTACK_TEST_WORKER_HOST'
        ),
        $names[1] => requiredTagWorkerEnvironment(
            'LIQUIDSTACK_TEST_WORKER_USERNAME'
        ),
        $names[2] => (string) getenv('LIQUIDSTACK_TEST_WORKER_PASSWORD'),
        $names[3] => $database,
    ]))->connect();
    if (
        $connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
        || $connection->query('SELECT DATABASE()')->fetchColumn() !== $database
    ) {
        throw new RuntimeException('Tag worker escaped its isolated database.');
    }

    $repository = new BlogMySqlTagBarrierRepository(
        new PdoBlogTagRepository(
            $connection,
            MigrationScope::forTablePrefix('blog', $prefix)
        ),
        $insert,
        $insertStart
    );
    $service = new BlogTagService($repository);
    if (file_put_contents($ready, 'ready', LOCK_EX) !== 5) {
        throw new RuntimeException('Could not signal tag worker readiness.');
    }
    $deadline = microtime(true) + 12.0;
    while (!is_file($start) && microtime(true) < $deadline) {
        usleep(5_000);
    }
    if (!is_file($start)) {
        finishTagWorker(['status' => 'barrier_timeout'], 66);
    }

    $actor = requiredTagWorkerEnvironment('LIQUIDSTACK_TEST_WORKER_ACTOR');
    $result = $service->assignToVariant(
        static fn (PDO $pdo): string => $actor,
        requiredTagWorkerEnvironment('LIQUIDSTACK_TEST_TAG_POST'),
        requiredTagWorkerEnvironment('LIQUIDSTACK_TEST_TAG_LOCALE'),
        (int) requiredTagWorkerEnvironment('LIQUIDSTACK_TEST_TAG_LOCK'),
        (int) requiredTagWorkerEnvironment('LIQUIDSTACK_TEST_TAG_WORKSPACE'),
        requiredTagWorkerEnvironment('LIQUIDSTACK_TEST_TAG_CSV')
    );
    finishTagWorker([
        'status' => 'success',
        'workspace_version' => $result->workspaceVersion(),
        'tags' => array_map(
            static fn (BlogTag $tag): array => $tag->toPresentationArray(),
            $result->tags()
        ),
        'tag_public_ids' => array_map(
            static fn (BlogTag $tag): string => $tag->publicId(),
            $result->tags()
        ),
    ], 0);
} catch (BlogTagException $exception) {
    finishTagWorker([
        'status' => 'error',
        'issue' => $exception->issueCode(),
    ], 3);
} catch (Throwable) {
    finishTagWorker(['status' => 'worker_failure'], 70);
}
