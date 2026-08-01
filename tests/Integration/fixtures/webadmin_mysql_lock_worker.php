<?php

declare(strict_types=1);

use App\Core\Database\SharedPdoConnectionFactory;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationRepository;
use App\Core\WebAdmin\Authentication\WebAdminAuthenticationService;
use App\Core\WebAdmin\Configuration\WebAdminConfig;
use App\Core\WebAdmin\CredentialAction\CredentialActionRepository;
use App\Core\WebAdmin\CredentialAction\CredentialActionService;
use App\Core\WebAdmin\Persistence\WebAdminTableNames;
use App\Core\WebAdmin\Security\PasswordHasher;
use App\Core\WebAdmin\Security\SecureTokenGenerator;
use App\Core\WebAdmin\Security\SecurityKey;
use App\Core\WebAdmin\Support\RandomUuidV4Generator;
use App\Core\WebAdmin\Support\SystemClock;
use App\Core\WebAdmin\UserManagement\ActiveModuleSet;
use App\Core\WebAdmin\UserManagement\EditorInviteResult;
use App\Core\WebAdmin\UserManagement\EditorMutationResult;
use App\Core\WebAdmin\UserManagement\UserManagementRepository;
use App\Core\WebAdmin\UserManagement\UserManagementService;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('zend.exception_ignore_args', '1');

try {
    $autoload = getenv('LIQUIDSTACK_TEST_WORKER_AUTOLOAD');
    $marker = getenv('LIQUIDSTACK_TEST_WORKER_MARKER');
    $operation = getenv('LIQUIDSTACK_TEST_WORKER_OPERATION');
    $token = getenv('LIQUIDSTACK_TEST_WORKER_TOKEN');
    if (
        !is_string($autoload)
        || !is_file($autoload)
        || !is_string($marker)
        || $marker === ''
        || !is_string($operation)
        || !is_string($token)
    ) {
        exit(64);
    }
    require $autoload;

    $names = WebAdminConfig::SHARED_DATABASE_ENV;
    $connection = (new SharedPdoConnectionFactory([
        $names[0] => (string) getenv('LIQUIDSTACK_TEST_WORKER_HOST'),
        $names[1] => (string) getenv('LIQUIDSTACK_TEST_WORKER_USERNAME'),
        $names[2] => (string) getenv('LIQUIDSTACK_TEST_WORKER_PASSWORD'),
        $names[3] => (string) getenv('LIQUIDSTACK_TEST_WORKER_DATABASE'),
    ]))->connect();
    $tables = WebAdminTableNames::fromPdo(
        $connection,
        'ls_webadmin_'
    );
    if (file_put_contents($marker, 'ready', LOCK_EX) !== 5) {
        exit(65);
    }

    if ($operation === 'resolve_auth') {
        $service = new WebAdminAuthenticationService(
            new WebAdminAuthenticationRepository($connection, $tables),
            WebAdminConfig::defaults(),
            SecurityKey::fromRawBytes(str_repeat('I', 32)),
            new SystemClock(),
            new RandomUuidV4Generator(),
            PasswordHasher::productive(),
            new SecureTokenGenerator()
        );
        exit($service->resolveAuthenticatedSession($token) === null ? 3 : 0);
    }

    if ($operation === 'resolve_action') {
        $service = new CredentialActionService(
            new CredentialActionRepository($connection, $tables),
            WebAdminConfig::defaults(),
            SecurityKey::fromRawBytes(str_repeat('I', 32)),
            new SystemClock(),
            new RandomUuidV4Generator(),
            PasswordHasher::productive(),
            new SecureTokenGenerator()
        );
        exit($service->resolveBoundAction(
            $token,
            CredentialActionService::PASSWORD_RESET
        ) === null ? 3 : 0);
    }

    if (in_array($operation, ['invite_editor', 'suspend_editor'], true)) {
        $csrf = getenv('LIQUIDSTACK_TEST_WORKER_CSRF');
        if (!is_string($csrf) || $csrf === '') {
            exit(64);
        }
        $service = new UserManagementService(
            new UserManagementRepository($connection, $tables),
            new ActiveModuleSet(['webadmin']),
            WebAdminConfig::defaults(),
            SecurityKey::fromRawBytes(str_repeat('I', 32)),
            new SystemClock(),
            new RandomUuidV4Generator(),
            PasswordHasher::productive(),
            new SecureTokenGenerator()
        );

        if ($operation === 'invite_editor') {
            $email = getenv('LIQUIDSTACK_TEST_WORKER_EMAIL');
            $start = getenv('LIQUIDSTACK_TEST_WORKER_START');
            if (
                !is_string($email)
                || $email === ''
                || !is_string($start)
                || $start === ''
            ) {
                exit(64);
            }
            $deadline = microtime(true) + 8.0;
            while (!is_file($start) && microtime(true) < $deadline) {
                usleep(5_000);
            }
            if (!is_file($start)) {
                exit(66);
            }
            $result = $service->inviteEditor(
                $token,
                $csrf,
                'Concurrent integration editor',
                $email
            );
            exit(match ($result->status()) {
                EditorInviteResult::INVITED => 0,
                EditorInviteResult::CONFLICT => 4,
                default => 3,
            });
        }

        $target = getenv('LIQUIDSTACK_TEST_WORKER_TARGET');
        if (!is_string($target) || $target === '') {
            exit(64);
        }
        $result = $service->suspendEditor($token, $csrf, $target);
        exit($result->status() === EditorMutationResult::APPLIED ? 0 : 3);
    }

    exit(64);
} catch (Throwable) {
    exit(70);
}
