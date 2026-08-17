<?php

declare(strict_types=1);

use App\Core\Blog\BlogException;
use App\Core\Blog\BlogService;
use App\Core\Blog\Persistence\PdoBlogRepository;
use App\Core\Blog\StructuredContent\Media\PdoWebAdminMediaAvailabilityAdapter;
use App\Core\Blog\StructuredContent\Persistence\PdoBlogStructuredContentRepository;
use App\Core\Database\SharedPdoConnectionFactory;
use App\Core\Modules\Migrations\MigrationScope;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');

/** @return never */
function finishWorker(array $payload, int $exitCode): void
{
    $encoded = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    fwrite(STDOUT, $encoded . PHP_EOL);
    exit($exitCode);
}

/** @return string */
function requiredWorkerEnvironment(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Missing isolated worker input.');
    }

    return $value;
}

function assertTemporaryWorkerPath(string $path): void
{
    $temporaryRoot = realpath(sys_get_temp_dir());
    $parent = realpath(dirname($path));
    $basename = basename($path);
    if (
        $temporaryRoot === false
        || $parent !== $temporaryRoot
        || preg_match(
            '/\Als-blog-copy-(?:a|b|go)-[a-f0-9]{16}\.tmp\z/D',
            $basename
        ) !== 1
    ) {
        throw new RuntimeException('Unsafe isolated worker path.');
    }
}

try {
    $autoload = requiredWorkerEnvironment(
        'LIQUIDSTACK_TEST_WORKER_AUTOLOAD'
    );
    if (!is_file($autoload)) {
        throw new RuntimeException('Invalid worker autoload path.');
    }
    require $autoload;

    $marker = requiredWorkerEnvironment('LIQUIDSTACK_TEST_WORKER_MARKER');
    $start = requiredWorkerEnvironment('LIQUIDSTACK_TEST_WORKER_START');
    assertTemporaryWorkerPath($marker);
    assertTemporaryWorkerPath($start);

    $database = requiredWorkerEnvironment(
        'LIQUIDSTACK_TEST_WORKER_DATABASE'
    );
    if (
        preg_match(
            '/\Aliquidstack_core_test_[a-z0-9_]{1,32}\z/',
            $database
        ) !== 1
        || strlen($database) > 64
    ) {
        throw new RuntimeException('Unsafe isolated worker database.');
    }
    $blogPrefix = requiredWorkerEnvironment(
        'LIQUIDSTACK_TEST_WORKER_BLOG_PREFIX'
    );
    $webAdminPrefix = requiredWorkerEnvironment(
        'LIQUIDSTACK_TEST_WORKER_WEBADMIN_PREFIX'
    );
    if (
        preg_match('/\Alsit_blog_[a-f0-9]{16}_\z/', $blogPrefix) !== 1
        || preg_match('/\Alsit_web_[a-f0-9]{16}_\z/', $webAdminPrefix) !== 1
    ) {
        throw new RuntimeException('Unsafe isolated worker table prefix.');
    }

    $expectedLockValue = requiredWorkerEnvironment(
        'LIQUIDSTACK_TEST_WORKER_EXPECTED_LOCK'
    );
    if (preg_match('/\A[1-9][0-9]{0,8}\z/', $expectedLockValue) !== 1) {
        throw new RuntimeException('Invalid isolated worker lock version.');
    }
    $action = requiredWorkerEnvironment(
        'LIQUIDSTACK_TEST_WORKER_COPY_ACTION'
    );
    if (!in_array($action, ['duplicate_post', 'add_locale'], true)) {
        throw new RuntimeException('Invalid isolated worker copy action.');
    }

    $names = WebAdminConfig::SHARED_DATABASE_ENV;
    $connection = (new SharedPdoConnectionFactory([
        $names[0] => requiredWorkerEnvironment(
            'LIQUIDSTACK_TEST_WORKER_HOST'
        ),
        $names[1] => requiredWorkerEnvironment(
            'LIQUIDSTACK_TEST_WORKER_USERNAME'
        ),
        $names[2] => (string) getenv(
            'LIQUIDSTACK_TEST_WORKER_PASSWORD'
        ),
        $names[3] => $database,
    ]))->connect();
    if (
        $connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
        || $connection->query('SELECT DATABASE()')->fetchColumn() !== $database
    ) {
        throw new RuntimeException('Worker escaped its isolated database.');
    }

    $blogScope = MigrationScope::forTablePrefix('blog', $blogPrefix);
    $webAdminScope = MigrationScope::forTablePrefix(
        'webadmin',
        $webAdminPrefix
    );
    $content = new PdoBlogStructuredContentRepository(
        $connection,
        $blogScope
    );
    $service = new BlogService(
        new PdoBlogRepository($connection, $blogScope),
        new RandomUuidV4Generator(),
        structuredContentRepository: $content,
        mediaAvailability: new PdoWebAdminMediaAvailabilityAdapter(
            $connection,
            $webAdminScope
        )
    );

    if (file_put_contents($marker, 'ready', LOCK_EX) !== 5) {
        throw new RuntimeException('Could not signal the race barrier.');
    }
    $deadline = microtime(true) + 12.0;
    while (!is_file($start) && microtime(true) < $deadline) {
        usleep(5_000);
    }
    if (!is_file($start)) {
        finishWorker(['status' => 'barrier_timeout'], 66);
    }

    $actor = requiredWorkerEnvironment('LIQUIDSTACK_TEST_WORKER_ACTOR');
    $actorGate = static fn (PDO $pdo): string => $actor;
    $sourcePost = requiredWorkerEnvironment(
        'LIQUIDSTACK_TEST_WORKER_SOURCE_POST'
    );
    $sourceLocale = requiredWorkerEnvironment(
        'LIQUIDSTACK_TEST_WORKER_SOURCE_LOCALE'
    );
    $destinationLocale = requiredWorkerEnvironment(
        'LIQUIDSTACK_TEST_WORKER_DESTINATION_LOCALE'
    );
    $operationId = requiredWorkerEnvironment(
        'LIQUIDSTACK_TEST_WORKER_OPERATION_ID'
    );
    $expectedLockVersion = (int) $expectedLockValue;

    $result = $action === 'duplicate_post'
        ? $service->duplicatePost(
            $actorGate,
            $sourcePost,
            $sourceLocale,
            $expectedLockVersion,
            $operationId
        )
        : $service->addLocalizationCopy(
            $actorGate,
            $sourcePost,
            $sourceLocale,
            $destinationLocale,
            $expectedLockVersion,
            $operationId
        );
    finishWorker([
        'status' => 'success',
        'post_public_id' => $result->postPublicId(),
        'localization_public_id' => $result->localizationPublicId(),
        'locale' => $result->locale(),
    ], 0);
} catch (BlogException $exception) {
    finishWorker([
        'status' => 'error',
        'issue' => $exception->issueCode(),
    ], $exception->issueCode() === BlogException::IDEMPOTENCY_CONFLICT
        ? 4
        : 3);
} catch (Throwable) {
    finishWorker(['status' => 'worker_failure'], 70);
}
