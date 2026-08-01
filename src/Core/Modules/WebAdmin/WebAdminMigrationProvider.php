<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

use App\Core\Modules\Migrations\MigrationDefinition;
use App\Core\Modules\Migrations\MigrationProviderInterface;

final class WebAdminMigrationProvider implements MigrationProviderInterface
{
    public static function moduleId(): string
    {
        return 'webadmin';
    }

    public static function migrations(): iterable
    {
        yield MigrationDefinition::sql(
            id: '0001_webadmin_identity_and_access',
            description: 'Crea el esquema inicial de identidad, acceso y auditoría de WebAdmin.',
            statementsByDriver: [
                'mysql' => self::mysqlStatements(),
                'sqlite' => self::sqliteStatements(),
            ],
            destructive: false,
            transactionalDrivers: ['sqlite'],
            retrySafe: true,
            postconditionVerifier: new WebAdminMigrationPostconditionVerifier(),
            preconditionVerifier: new WebAdminInitialNamespacePrecondition()
        );
    }

    /** @return list<string> */
    private static function mysqlStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:users}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `email_canonical` VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `display_name` VARCHAR(120) NULL,
    `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `auth_version` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `created_by_user_id` BIGINT UNSIGNED NULL,
    `invited_at` DATETIME(6) NULL,
    `activated_at` DATETIME(6) NULL,
    `suspended_at` DATETIME(6) NULL,
    `last_login_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wa_users_public` (`public_id`),
    UNIQUE KEY `uq_wa_users_email` (`email_canonical`),
    KEY `idx_wa_users_status` (`status`),
    KEY `idx_wa_users_creator` (`created_by_user_id`),
    CONSTRAINT {{table:f_us_creator}} FOREIGN KEY (`created_by_user_id`)
        REFERENCES {{table:users}} (`id`) ON DELETE SET NULL,
    CONSTRAINT {{table:c_us_status}} CHECK (`status` IN ('invited', 'active', 'suspended')),
    CONSTRAINT {{table:c_us_auth}} CHECK (`auth_version` > 0),
    CONSTRAINT {{table:c_us_email}} CHECK (`email_canonical` = LOWER(`email_canonical`)),
    CONSTRAINT {{table:c_us_public}} CHECK (CHAR_LENGTH(`public_id`) = 36)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:credentials}} (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `password_hash` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `password_set_at` DATETIME(6) NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`user_id`),
    CONSTRAINT {{table:f_cr_user}} FOREIGN KEY (`user_id`)
        REFERENCES {{table:users}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:c_cr_password}} CHECK (
        (`password_hash` IS NULL AND `password_set_at` IS NULL)
        OR (`password_hash` IS NOT NULL AND `password_set_at` IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:roles}} (
    `id` SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `label_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `is_protected` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `is_delegable` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wa_roles_code` (`code`),
    CONSTRAINT {{table:c_ro_protect}} CHECK (`is_protected` IN (0, 1)),
    CONSTRAINT {{table:c_ro_delegate}} CHECK (`is_delegable` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:capabilities}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `module_id` VARCHAR(63) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `code` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `label_key` VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `is_delegable` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wa_capabilities_code` (`code`),
    KEY `idx_wa_capabilities_module` (`module_id`),
    CONSTRAINT {{table:c_ca_delegate}} CHECK (`is_delegable` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:role_capabilities}} (
    `role_id` SMALLINT UNSIGNED NOT NULL,
    `capability_id` BIGINT UNSIGNED NOT NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`role_id`, `capability_id`),
    KEY `idx_wa_role_caps_capability` (`capability_id`),
    CONSTRAINT {{table:f_rc_role}} FOREIGN KEY (`role_id`)
        REFERENCES {{table:roles}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_rc_cap}} FOREIGN KEY (`capability_id`)
        REFERENCES {{table:capabilities}} (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:user_roles}} (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `role_id` SMALLINT UNSIGNED NOT NULL,
    `assigned_by_user_id` BIGINT UNSIGNED NULL,
    `source` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'manual',
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`user_id`, `role_id`),
    KEY `idx_wa_user_roles_role` (`role_id`),
    KEY `idx_wa_user_roles_assigner` (`assigned_by_user_id`),
    CONSTRAINT {{table:f_ur_user}} FOREIGN KEY (`user_id`)
        REFERENCES {{table:users}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_ur_role}} FOREIGN KEY (`role_id`)
        REFERENCES {{table:roles}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_ur_by}} FOREIGN KEY (`assigned_by_user_id`)
        REFERENCES {{table:users}} (`id`) ON DELETE SET NULL,
    CONSTRAINT {{table:c_ur_source}} CHECK (`source` IN ('bootstrap', 'manual', 'system'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:user_capabilities}} (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `capability_id` BIGINT UNSIGNED NOT NULL,
    `assigned_by_user_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`user_id`, `capability_id`),
    KEY `idx_wa_user_caps_capability` (`capability_id`),
    KEY `idx_wa_user_caps_assigner` (`assigned_by_user_id`),
    CONSTRAINT {{table:f_uc_user}} FOREIGN KEY (`user_id`)
        REFERENCES {{table:users}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_uc_cap}} FOREIGN KEY (`capability_id`)
        REFERENCES {{table:capabilities}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_uc_by}} FOREIGN KEY (`assigned_by_user_id`)
        REFERENCES {{table:users}} (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:action_tokens}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `purpose` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `auth_version` BIGINT UNSIGNED NOT NULL,
    `created_by_user_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `expires_at` DATETIME(6) NOT NULL,
    `delivered_at` DATETIME(6) NULL,
    `used_at` DATETIME(6) NULL,
    `revoked_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wa_action_tokens_hash` (`token_hash`),
    KEY `idx_wa_action_tokens_user` (`user_id`, `purpose`, `expires_at`),
    KEY `idx_wa_action_tokens_creator` (`created_by_user_id`),
    CONSTRAINT {{table:f_at_user}} FOREIGN KEY (`user_id`)
        REFERENCES {{table:users}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_at_by}} FOREIGN KEY (`created_by_user_id`)
        REFERENCES {{table:users}} (`id`) ON DELETE SET NULL,
    CONSTRAINT {{table:c_at_purpose}} CHECK (`purpose` IN ('invite', 'password_reset')),
    CONSTRAINT {{table:c_at_hash}} CHECK (CHAR_LENGTH(`token_hash`) = 64),
    CONSTRAINT {{table:c_at_version}} CHECK (`auth_version` > 0),
    CONSTRAINT {{table:c_at_expiry}} CHECK (`expires_at` > `created_at`),
    CONSTRAINT {{table:c_at_terminal}} CHECK (`used_at` IS NULL OR `revoked_at` IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:sessions}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` BIGINT UNSIGNED NULL,
    `session_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `csrf_token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `auth_version` BIGINT UNSIGNED NULL,
    `pending_action_token_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `last_seen_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `idle_expires_at` DATETIME(6) NOT NULL,
    `absolute_expires_at` DATETIME(6) NOT NULL,
    `revoked_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wa_sessions_public` (`public_id`),
    UNIQUE KEY `uq_wa_sessions_hash` (`token_hash`),
    KEY `idx_wa_sessions_user` (`user_id`, `revoked_at`),
    KEY `idx_wa_sessions_expiry` (`absolute_expires_at`),
    KEY `idx_wa_sessions_pending_token` (`pending_action_token_id`),
    CONSTRAINT {{table:f_se_user}} FOREIGN KEY (`user_id`)
        REFERENCES {{table:users}} (`id`) ON DELETE RESTRICT,
    CONSTRAINT {{table:f_se_token}} FOREIGN KEY (`pending_action_token_id`)
        REFERENCES {{table:action_tokens}} (`id`) ON DELETE SET NULL,
    CONSTRAINT {{table:c_se_type}} CHECK (`session_type` IN ('preauth', 'authenticated')),
    CONSTRAINT {{table:c_se_public}} CHECK (CHAR_LENGTH(`public_id`) = 36),
    CONSTRAINT {{table:c_se_hash}} CHECK (CHAR_LENGTH(`token_hash`) = 64),
    CONSTRAINT {{table:c_se_csrf}} CHECK (CHAR_LENGTH(`csrf_token_hash`) = 64),
    CONSTRAINT {{table:c_se_identity}} CHECK (
        (`session_type` = 'preauth' AND `user_id` IS NULL AND `auth_version` IS NULL)
        OR (`session_type` = 'authenticated' AND `user_id` IS NOT NULL AND `auth_version` IS NOT NULL)
    ),
    CONSTRAINT {{table:c_se_expiry}} CHECK (
        `idle_expires_at` > `created_at`
        AND `absolute_expires_at` >= `idle_expires_at`
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:rate_limits}} (
    `action` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `subject_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `window_started_at` DATETIME(6) NOT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `blocked_until` DATETIME(6) NULL,
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`action`, `subject_hash`),
    KEY `idx_wa_rate_limits_blocked` (`blocked_until`),
    CONSTRAINT {{table:c_rl_hash}} CHECK (CHAR_LENGTH(`subject_hash`) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:audit_log}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `actor_user_id` BIGINT UNSIGNED NULL,
    `actor_session_public_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `event_code` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `outcome` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `reason_code` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `target_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `target_public_id` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `metadata_json` LONGTEXT NULL,
    `ip_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `user_agent_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `occurred_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`id`),
    KEY `idx_wa_audit_request` (`request_id`),
    KEY `idx_wa_audit_actor_time` (`actor_user_id`, `occurred_at`),
    KEY `idx_wa_audit_event_time` (`event_code`, `occurred_at`),
    CONSTRAINT {{table:f_au_actor}} FOREIGN KEY (`actor_user_id`)
        REFERENCES {{table:users}} (`id`) ON DELETE SET NULL,
    CONSTRAINT {{table:c_au_outcome}} CHECK (`outcome` IN ('success', 'failure', 'denied')),
    CONSTRAINT {{table:c_au_request}} CHECK (CHAR_LENGTH(`request_id`) = 36),
    CONSTRAINT {{table:c_au_ip}} CHECK (`ip_hash` IS NULL OR CHAR_LENGTH(`ip_hash`) = 64),
    CONSTRAINT {{table:c_au_ua}} CHECK (`user_agent_hash` IS NULL OR CHAR_LENGTH(`user_agent_hash`) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:outbox}} (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kind` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `locale` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `available_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `locked_at` DATETIME(6) NULL,
    `lock_token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `action_token_id` BIGINT UNSIGNED NULL,
    `last_error_code` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    `sent_at` DATETIME(6) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wa_outbox_action_token` (`action_token_id`),
    KEY `idx_wa_outbox_delivery` (`status`, `available_at`),
    KEY `idx_wa_outbox_user` (`user_id`),
    CONSTRAINT {{table:f_ob_user}} FOREIGN KEY (`user_id`)
        REFERENCES {{table:users}} (`id`) ON DELETE CASCADE,
    CONSTRAINT {{table:f_ob_token}} FOREIGN KEY (`action_token_id`)
        REFERENCES {{table:action_tokens}} (`id`) ON DELETE SET NULL,
    CONSTRAINT {{table:c_ob_kind}} CHECK (`kind` IN ('invite', 'password_reset')),
    CONSTRAINT {{table:c_ob_status}} CHECK (`status` IN ('pending', 'processing', 'sent', 'failed')),
    CONSTRAINT {{table:c_ob_lock}} CHECK (
        (`status` = 'processing' AND `locked_at` IS NOT NULL AND `lock_token_hash` IS NOT NULL)
        OR (`status` <> 'processing' AND `locked_at` IS NULL AND `lock_token_hash` IS NULL)
    ),
    CONSTRAINT {{table:c_ob_sent}} CHECK (
        (`status` = 'sent' AND `sent_at` IS NOT NULL)
        OR (`status` <> 'sent' AND `sent_at` IS NULL)
    ),
    CONSTRAINT {{table:c_ob_lock_hash}} CHECK (`lock_token_hash` IS NULL OR CHAR_LENGTH(`lock_token_hash`) = 64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:state}} (
    `state_key` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `value_text` LONGTEXT NOT NULL,
    `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (`state_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
INSERT INTO {{table:roles}}
    (`code`, `label_key`, `is_protected`, `is_delegable`)
VALUES
    ('system_superadmin', 'webadmin.roles.system_superadmin', 1, 0),
    ('site_admin', 'webadmin.roles.site_admin', 1, 0),
    ('editor', 'webadmin.roles.editor', 0, 1)
ON DUPLICATE KEY UPDATE
    `label_key` = VALUES(`label_key`),
    `is_protected` = VALUES(`is_protected`),
    `is_delegable` = VALUES(`is_delegable`)
SQL,
            <<<'SQL'
INSERT INTO {{table:capabilities}}
    (`module_id`, `code`, `label_key`, `is_delegable`)
VALUES
    ('webadmin', 'webadmin.access', 'webadmin.capabilities.access', 0),
    ('webadmin', 'webadmin.profile.manage_self', 'webadmin.capabilities.profile_manage_self', 0),
    ('webadmin', 'webadmin.users.view', 'webadmin.capabilities.users_view', 1),
    ('webadmin', 'webadmin.users.invite', 'webadmin.capabilities.users_invite', 0),
    ('webadmin', 'webadmin.users.suspend', 'webadmin.capabilities.users_suspend', 0),
    ('webadmin', 'webadmin.users.capabilities.manage', 'webadmin.capabilities.users_capabilities_manage', 0),
    ('webadmin', 'webadmin.audit.view', 'webadmin.capabilities.audit_view', 0),
    ('webadmin', 'webadmin.system.diagnose', 'webadmin.capabilities.system_diagnose', 0)
ON DUPLICATE KEY UPDATE
    `module_id` = VALUES(`module_id`),
    `label_key` = VALUES(`label_key`),
    `is_delegable` = VALUES(`is_delegable`)
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} (`role_id`, `capability_id`)
SELECT `r`.`id`, `c`.`id`
FROM {{table:roles}} AS `r`
CROSS JOIN {{table:capabilities}} AS `c`
WHERE
    `r`.`code` = 'system_superadmin'
    OR (
        `r`.`code` = 'site_admin'
        AND `c`.`code` IN (
            'webadmin.access',
            'webadmin.profile.manage_self',
            'webadmin.users.view',
            'webadmin.users.invite',
            'webadmin.users.suspend',
            'webadmin.users.capabilities.manage',
            'webadmin.audit.view'
        )
    )
    OR (
        `r`.`code` = 'editor'
        AND `c`.`code` IN (
            'webadmin.access',
            'webadmin.profile.manage_self'
        )
    )
ON DUPLICATE KEY UPDATE
    `role_id` = VALUES(`role_id`)
SQL,
            <<<'SQL'
INSERT INTO {{table:state}} (`state_key`, `value_text`)
VALUES ('bootstrap.initial_accounts', 'pending')
ON DUPLICATE KEY UPDATE
    `state_key` = VALUES(`state_key`)
SQL,
        ];
    }

    /** @return list<string> */
    private static function sqliteStatements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:users}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("public_id") = 36),
    "email_canonical" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK ("email_canonical" = lower("email_canonical")),
    "display_name" TEXT NULL,
    "status" TEXT COLLATE BINARY NOT NULL CHECK ("status" IN ('invited', 'active', 'suspended')),
    "auth_version" INTEGER NOT NULL DEFAULT 1 CHECK ("auth_version" > 0),
    "created_by_user_id" INTEGER NULL REFERENCES {{table:users}} ("id") ON DELETE SET NULL,
    "invited_at" TEXT NULL,
    "activated_at" TEXT NULL,
    "suspended_at" TEXT NULL,
    "last_login_at" TEXT NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_us_status}} ON {{table:users}} ("status")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_us_creator}} ON {{table:users}} ("created_by_user_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:credentials}} (
    "user_id" INTEGER PRIMARY KEY REFERENCES {{table:users}} ("id") ON DELETE CASCADE,
    "password_hash" TEXT COLLATE BINARY NULL,
    "password_set_at" TEXT NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    CHECK (
        ("password_hash" IS NULL AND "password_set_at" IS NULL)
        OR ("password_hash" IS NOT NULL AND "password_set_at" IS NOT NULL)
    )
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:roles}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "code" TEXT COLLATE BINARY NOT NULL UNIQUE,
    "label_key" TEXT COLLATE BINARY NOT NULL,
    "is_protected" INTEGER NOT NULL DEFAULT 0 CHECK ("is_protected" IN (0, 1)),
    "is_delegable" INTEGER NOT NULL DEFAULT 0 CHECK ("is_delegable" IN (0, 1)),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:capabilities}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "module_id" TEXT COLLATE BINARY NOT NULL,
    "code" TEXT COLLATE BINARY NOT NULL UNIQUE,
    "label_key" TEXT COLLATE BINARY NOT NULL,
    "is_delegable" INTEGER NOT NULL DEFAULT 0 CHECK ("is_delegable" IN (0, 1)),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_ca_module}} ON {{table:capabilities}} ("module_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:role_capabilities}} (
    "role_id" INTEGER NOT NULL REFERENCES {{table:roles}} ("id") ON DELETE CASCADE,
    "capability_id" INTEGER NOT NULL REFERENCES {{table:capabilities}} ("id") ON DELETE CASCADE,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    PRIMARY KEY ("role_id", "capability_id")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_rc_cap}} ON {{table:role_capabilities}} ("capability_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:user_roles}} (
    "user_id" INTEGER NOT NULL REFERENCES {{table:users}} ("id") ON DELETE CASCADE,
    "role_id" INTEGER NOT NULL REFERENCES {{table:roles}} ("id") ON DELETE CASCADE,
    "assigned_by_user_id" INTEGER NULL REFERENCES {{table:users}} ("id") ON DELETE SET NULL,
    "source" TEXT COLLATE BINARY NOT NULL DEFAULT 'manual' CHECK ("source" IN ('bootstrap', 'manual', 'system')),
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    PRIMARY KEY ("user_id", "role_id")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_ur_role}} ON {{table:user_roles}} ("role_id")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_ur_by}} ON {{table:user_roles}} ("assigned_by_user_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:user_capabilities}} (
    "user_id" INTEGER NOT NULL REFERENCES {{table:users}} ("id") ON DELETE CASCADE,
    "capability_id" INTEGER NOT NULL REFERENCES {{table:capabilities}} ("id") ON DELETE CASCADE,
    "assigned_by_user_id" INTEGER NULL REFERENCES {{table:users}} ("id") ON DELETE SET NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    PRIMARY KEY ("user_id", "capability_id")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_uc_cap}} ON {{table:user_capabilities}} ("capability_id")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_uc_by}} ON {{table:user_capabilities}} ("assigned_by_user_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:action_tokens}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "user_id" INTEGER NOT NULL REFERENCES {{table:users}} ("id") ON DELETE CASCADE,
    "purpose" TEXT COLLATE BINARY NOT NULL CHECK ("purpose" IN ('invite', 'password_reset')),
    "token_hash" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("token_hash") = 64),
    "auth_version" INTEGER NOT NULL CHECK ("auth_version" > 0),
    "created_by_user_id" INTEGER NULL REFERENCES {{table:users}} ("id") ON DELETE SET NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "expires_at" TEXT NOT NULL,
    "delivered_at" TEXT NULL,
    "used_at" TEXT NULL,
    "revoked_at" TEXT NULL,
    CHECK ("expires_at" > "created_at"),
    CHECK ("used_at" IS NULL OR "revoked_at" IS NULL)
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_at_user}} ON {{table:action_tokens}} ("user_id", "purpose", "expires_at")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_at_by}} ON {{table:action_tokens}} ("created_by_user_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:sessions}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "public_id" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("public_id") = 36),
    "user_id" INTEGER NULL REFERENCES {{table:users}} ("id") ON DELETE RESTRICT,
    "session_type" TEXT COLLATE BINARY NOT NULL CHECK ("session_type" IN ('preauth', 'authenticated')),
    "token_hash" TEXT COLLATE BINARY NOT NULL UNIQUE CHECK (length("token_hash") = 64),
    "csrf_token_hash" TEXT COLLATE BINARY NOT NULL CHECK (length("csrf_token_hash") = 64),
    "auth_version" INTEGER NULL,
    "pending_action_token_id" INTEGER NULL REFERENCES {{table:action_tokens}} ("id") ON DELETE SET NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "last_seen_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "idle_expires_at" TEXT NOT NULL,
    "absolute_expires_at" TEXT NOT NULL,
    "revoked_at" TEXT NULL,
    CHECK (
        ("session_type" = 'preauth' AND "user_id" IS NULL AND "auth_version" IS NULL)
        OR ("session_type" = 'authenticated' AND "user_id" IS NOT NULL AND "auth_version" IS NOT NULL AND "pending_action_token_id" IS NULL)
    ),
    CHECK ("idle_expires_at" > "created_at" AND "absolute_expires_at" >= "idle_expires_at")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_se_user}} ON {{table:sessions}} ("user_id", "revoked_at")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_se_expiry}} ON {{table:sessions}} ("absolute_expires_at")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_se_token}} ON {{table:sessions}} ("pending_action_token_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:rate_limits}} (
    "action" TEXT COLLATE BINARY NOT NULL,
    "subject_hash" TEXT COLLATE BINARY NOT NULL CHECK (length("subject_hash") = 64),
    "window_started_at" TEXT NOT NULL,
    "attempts" INTEGER NOT NULL DEFAULT 0 CHECK ("attempts" >= 0),
    "blocked_until" TEXT NULL,
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    PRIMARY KEY ("action", "subject_hash")
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_rl_block}} ON {{table:rate_limits}} ("blocked_until")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:audit_log}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "request_id" TEXT COLLATE BINARY NOT NULL CHECK (length("request_id") = 36),
    "actor_user_id" INTEGER NULL REFERENCES {{table:users}} ("id") ON DELETE SET NULL,
    "actor_session_public_id" TEXT COLLATE BINARY NULL,
    "event_code" TEXT COLLATE BINARY NOT NULL,
    "outcome" TEXT COLLATE BINARY NOT NULL CHECK ("outcome" IN ('success', 'failure', 'denied')),
    "reason_code" TEXT COLLATE BINARY NULL,
    "target_type" TEXT COLLATE BINARY NULL,
    "target_public_id" TEXT COLLATE BINARY NULL,
    "metadata_json" TEXT NULL,
    "ip_hash" TEXT COLLATE BINARY NULL CHECK ("ip_hash" IS NULL OR length("ip_hash") = 64),
    "user_agent_hash" TEXT COLLATE BINARY NULL CHECK ("user_agent_hash" IS NULL OR length("user_agent_hash") = 64),
    "occurred_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_au_request}} ON {{table:audit_log}} ("request_id")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_au_actor}} ON {{table:audit_log}} ("actor_user_id", "occurred_at")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_au_event}} ON {{table:audit_log}} ("event_code", "occurred_at")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:outbox}} (
    "id" INTEGER PRIMARY KEY AUTOINCREMENT,
    "kind" TEXT COLLATE BINARY NOT NULL CHECK ("kind" IN ('invite', 'password_reset')),
    "user_id" INTEGER NOT NULL REFERENCES {{table:users}} ("id") ON DELETE CASCADE,
    "locale" TEXT COLLATE BINARY NOT NULL,
    "status" TEXT COLLATE BINARY NOT NULL DEFAULT 'pending' CHECK ("status" IN ('pending', 'processing', 'sent', 'failed')),
    "attempts" INTEGER NOT NULL DEFAULT 0 CHECK ("attempts" >= 0),
    "available_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "locked_at" TEXT NULL,
    "lock_token_hash" TEXT COLLATE BINARY NULL CHECK ("lock_token_hash" IS NULL OR length("lock_token_hash") = 64),
    "action_token_id" INTEGER NULL UNIQUE REFERENCES {{table:action_tokens}} ("id") ON DELETE SET NULL,
    "last_error_code" TEXT COLLATE BINARY NULL,
    "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now')),
    "sent_at" TEXT NULL,
    CHECK (
        ("status" = 'processing' AND "locked_at" IS NOT NULL AND "lock_token_hash" IS NOT NULL)
        OR ("status" <> 'processing' AND "locked_at" IS NULL AND "lock_token_hash" IS NULL)
    ),
    CHECK (
        ("status" = 'sent' AND "sent_at" IS NOT NULL)
        OR ("status" <> 'sent' AND "sent_at" IS NULL)
    )
)
SQL,
            'CREATE INDEX IF NOT EXISTS {{table:ix_ob_delivery}} ON {{table:outbox}} ("status", "available_at")',
            'CREATE INDEX IF NOT EXISTS {{table:ix_ob_user}} ON {{table:outbox}} ("user_id")',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS {{table:state}} (
    "state_key" TEXT COLLATE BINARY NOT NULL PRIMARY KEY,
    "value_text" TEXT NOT NULL,
    "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f000', 'now'))
) WITHOUT ROWID
SQL,
            <<<'SQL'
INSERT INTO {{table:roles}}
    ("code", "label_key", "is_protected", "is_delegable")
VALUES
    ('system_superadmin', 'webadmin.roles.system_superadmin', 1, 0),
    ('site_admin', 'webadmin.roles.site_admin', 1, 0),
    ('editor', 'webadmin.roles.editor', 0, 1)
ON CONFLICT("code") DO UPDATE SET
    "label_key" = excluded."label_key",
    "is_protected" = excluded."is_protected",
    "is_delegable" = excluded."is_delegable"
SQL,
            <<<'SQL'
INSERT INTO {{table:capabilities}}
    ("module_id", "code", "label_key", "is_delegable")
VALUES
    ('webadmin', 'webadmin.access', 'webadmin.capabilities.access', 0),
    ('webadmin', 'webadmin.profile.manage_self', 'webadmin.capabilities.profile_manage_self', 0),
    ('webadmin', 'webadmin.users.view', 'webadmin.capabilities.users_view', 1),
    ('webadmin', 'webadmin.users.invite', 'webadmin.capabilities.users_invite', 0),
    ('webadmin', 'webadmin.users.suspend', 'webadmin.capabilities.users_suspend', 0),
    ('webadmin', 'webadmin.users.capabilities.manage', 'webadmin.capabilities.users_capabilities_manage', 0),
    ('webadmin', 'webadmin.audit.view', 'webadmin.capabilities.audit_view', 0),
    ('webadmin', 'webadmin.system.diagnose', 'webadmin.capabilities.system_diagnose', 0)
ON CONFLICT("code") DO UPDATE SET
    "module_id" = excluded."module_id",
    "label_key" = excluded."label_key",
    "is_delegable" = excluded."is_delegable"
SQL,
            <<<'SQL'
INSERT INTO {{table:role_capabilities}} ("role_id", "capability_id")
SELECT "r"."id", "c"."id"
FROM {{table:roles}} AS "r"
CROSS JOIN {{table:capabilities}} AS "c"
WHERE
    "r"."code" = 'system_superadmin'
    OR (
        "r"."code" = 'site_admin'
        AND "c"."code" IN (
            'webadmin.access',
            'webadmin.profile.manage_self',
            'webadmin.users.view',
            'webadmin.users.invite',
            'webadmin.users.suspend',
            'webadmin.users.capabilities.manage',
            'webadmin.audit.view'
        )
    )
    OR (
        "r"."code" = 'editor'
        AND "c"."code" IN (
            'webadmin.access',
            'webadmin.profile.manage_self'
        )
    )
ON CONFLICT("role_id", "capability_id") DO NOTHING
SQL,
            <<<'SQL'
INSERT INTO {{table:state}} ("state_key", "value_text")
VALUES ('bootstrap.initial_accounts', 'pending')
ON CONFLICT("state_key") DO NOTHING
SQL,
        ];
    }
}
