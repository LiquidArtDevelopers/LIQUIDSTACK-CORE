<?php

declare(strict_types=1);

namespace App\Core\Modules\WebAdmin;

/**
 * Immutable, verifier-owned contract for migration 0001.
 *
 * It intentionally duplicates the observable database contract instead of
 * deriving it from the migration SQL. That independence is what lets the
 * postcondition reject a syntactically successful but incomplete migration.
 */
final class WebAdminInitialSchemaContract
{
    /** @return list<string> */
    public static function tableSuffixes(): array
    {
        return [
            'users',
            'credentials',
            'roles',
            'capabilities',
            'role_capabilities',
            'user_roles',
            'user_capabilities',
            'action_tokens',
            'sessions',
            'rate_limits',
            'audit_log',
            'outbox',
            'state',
        ];
    }

    /**
     * @return array<string, list<array{
     *     name: string,
     *     type: string,
     *     nullable: bool,
     *     primary_position: int,
     *     default?: string,
     *     autoincrement?: bool,
     *     binary_text?: bool
     * }>>
     */
    public static function sqliteColumns(): array
    {
        $now = "strftime('%Y-%m-%d %H:%M:%f000','now')";

        return [
            'users' => [
                self::sqliteColumn('id', 'INTEGER', true, 1, autoincrement: true),
                self::sqliteColumn('public_id', 'TEXT', false, binaryText: true),
                self::sqliteColumn('email_canonical', 'TEXT', false, binaryText: true),
                self::sqliteColumn('display_name', 'TEXT', true),
                self::sqliteColumn('status', 'TEXT', false, binaryText: true),
                self::sqliteColumn('auth_version', 'INTEGER', false, default: '1'),
                self::sqliteColumn('created_by_user_id', 'INTEGER', true),
                self::sqliteColumn('invited_at', 'TEXT', true),
                self::sqliteColumn('activated_at', 'TEXT', true),
                self::sqliteColumn('suspended_at', 'TEXT', true),
                self::sqliteColumn('last_login_at', 'TEXT', true),
                self::sqliteColumn('created_at', 'TEXT', false, default: $now),
                self::sqliteColumn('updated_at', 'TEXT', false, default: $now),
            ],
            'credentials' => [
                self::sqliteColumn('user_id', 'INTEGER', true, 1),
                self::sqliteColumn('password_hash', 'TEXT', true, binaryText: true),
                self::sqliteColumn('password_set_at', 'TEXT', true),
                self::sqliteColumn('created_at', 'TEXT', false, default: $now),
                self::sqliteColumn('updated_at', 'TEXT', false, default: $now),
            ],
            'roles' => [
                self::sqliteColumn('id', 'INTEGER', true, 1, autoincrement: true),
                self::sqliteColumn('code', 'TEXT', false, binaryText: true),
                self::sqliteColumn('label_key', 'TEXT', false, binaryText: true),
                self::sqliteColumn('is_protected', 'INTEGER', false, default: '0'),
                self::sqliteColumn('is_delegable', 'INTEGER', false, default: '0'),
                self::sqliteColumn('created_at', 'TEXT', false, default: $now),
            ],
            'capabilities' => [
                self::sqliteColumn('id', 'INTEGER', true, 1, autoincrement: true),
                self::sqliteColumn('module_id', 'TEXT', false, binaryText: true),
                self::sqliteColumn('code', 'TEXT', false, binaryText: true),
                self::sqliteColumn('label_key', 'TEXT', false, binaryText: true),
                self::sqliteColumn('is_delegable', 'INTEGER', false, default: '0'),
                self::sqliteColumn('created_at', 'TEXT', false, default: $now),
            ],
            'role_capabilities' => [
                self::sqliteColumn('role_id', 'INTEGER', false, 1),
                self::sqliteColumn('capability_id', 'INTEGER', false, 2),
                self::sqliteColumn('created_at', 'TEXT', false, default: $now),
            ],
            'user_roles' => [
                self::sqliteColumn('user_id', 'INTEGER', false, 1),
                self::sqliteColumn('role_id', 'INTEGER', false, 2),
                self::sqliteColumn('assigned_by_user_id', 'INTEGER', true),
                self::sqliteColumn('source', 'TEXT', false, default: "'manual'", binaryText: true),
                self::sqliteColumn('created_at', 'TEXT', false, default: $now),
            ],
            'user_capabilities' => [
                self::sqliteColumn('user_id', 'INTEGER', false, 1),
                self::sqliteColumn('capability_id', 'INTEGER', false, 2),
                self::sqliteColumn('assigned_by_user_id', 'INTEGER', true),
                self::sqliteColumn('created_at', 'TEXT', false, default: $now),
            ],
            'action_tokens' => [
                self::sqliteColumn('id', 'INTEGER', true, 1, autoincrement: true),
                self::sqliteColumn('user_id', 'INTEGER', false),
                self::sqliteColumn('purpose', 'TEXT', false, binaryText: true),
                self::sqliteColumn('token_hash', 'TEXT', false, binaryText: true),
                self::sqliteColumn('auth_version', 'INTEGER', false),
                self::sqliteColumn('created_by_user_id', 'INTEGER', true),
                self::sqliteColumn('created_at', 'TEXT', false, default: $now),
                self::sqliteColumn('expires_at', 'TEXT', false),
                self::sqliteColumn('delivered_at', 'TEXT', true),
                self::sqliteColumn('used_at', 'TEXT', true),
                self::sqliteColumn('revoked_at', 'TEXT', true),
            ],
            'sessions' => [
                self::sqliteColumn('id', 'INTEGER', true, 1, autoincrement: true),
                self::sqliteColumn('public_id', 'TEXT', false, binaryText: true),
                self::sqliteColumn('user_id', 'INTEGER', true),
                self::sqliteColumn('session_type', 'TEXT', false, binaryText: true),
                self::sqliteColumn('token_hash', 'TEXT', false, binaryText: true),
                self::sqliteColumn('csrf_token_hash', 'TEXT', false, binaryText: true),
                self::sqliteColumn('auth_version', 'INTEGER', true),
                self::sqliteColumn('pending_action_token_id', 'INTEGER', true),
                self::sqliteColumn('created_at', 'TEXT', false, default: $now),
                self::sqliteColumn('last_seen_at', 'TEXT', false, default: $now),
                self::sqliteColumn('idle_expires_at', 'TEXT', false),
                self::sqliteColumn('absolute_expires_at', 'TEXT', false),
                self::sqliteColumn('revoked_at', 'TEXT', true),
            ],
            'rate_limits' => [
                self::sqliteColumn('action', 'TEXT', false, 1, binaryText: true),
                self::sqliteColumn('subject_hash', 'TEXT', false, 2, binaryText: true),
                self::sqliteColumn('window_started_at', 'TEXT', false),
                self::sqliteColumn('attempts', 'INTEGER', false, default: '0'),
                self::sqliteColumn('blocked_until', 'TEXT', true),
                self::sqliteColumn('updated_at', 'TEXT', false, default: $now),
            ],
            'audit_log' => [
                self::sqliteColumn('id', 'INTEGER', true, 1, autoincrement: true),
                self::sqliteColumn('request_id', 'TEXT', false, binaryText: true),
                self::sqliteColumn('actor_user_id', 'INTEGER', true),
                self::sqliteColumn('actor_session_public_id', 'TEXT', true, binaryText: true),
                self::sqliteColumn('event_code', 'TEXT', false, binaryText: true),
                self::sqliteColumn('outcome', 'TEXT', false, binaryText: true),
                self::sqliteColumn('reason_code', 'TEXT', true, binaryText: true),
                self::sqliteColumn('target_type', 'TEXT', true, binaryText: true),
                self::sqliteColumn('target_public_id', 'TEXT', true, binaryText: true),
                self::sqliteColumn('metadata_json', 'TEXT', true),
                self::sqliteColumn('ip_hash', 'TEXT', true, binaryText: true),
                self::sqliteColumn('user_agent_hash', 'TEXT', true, binaryText: true),
                self::sqliteColumn('occurred_at', 'TEXT', false, default: $now),
            ],
            'outbox' => [
                self::sqliteColumn('id', 'INTEGER', true, 1, autoincrement: true),
                self::sqliteColumn('kind', 'TEXT', false, binaryText: true),
                self::sqliteColumn('user_id', 'INTEGER', false),
                self::sqliteColumn('locale', 'TEXT', false, binaryText: true),
                self::sqliteColumn('status', 'TEXT', false, default: "'pending'", binaryText: true),
                self::sqliteColumn('attempts', 'INTEGER', false, default: '0'),
                self::sqliteColumn('available_at', 'TEXT', false, default: $now),
                self::sqliteColumn('locked_at', 'TEXT', true),
                self::sqliteColumn('lock_token_hash', 'TEXT', true, binaryText: true),
                self::sqliteColumn('action_token_id', 'INTEGER', true),
                self::sqliteColumn('last_error_code', 'TEXT', true, binaryText: true),
                self::sqliteColumn('created_at', 'TEXT', false, default: $now),
                self::sqliteColumn('sent_at', 'TEXT', true),
            ],
            'state' => [
                self::sqliteColumn('state_key', 'TEXT', false, 1, binaryText: true),
                self::sqliteColumn('value_text', 'TEXT', false),
                self::sqliteColumn('updated_at', 'TEXT', false, default: $now),
            ],
        ];
    }

    /**
     * @return array<string, list<array{
     *     name: string,
     *     type: string,
     *     nullable: bool,
     *     unsigned?: bool,
     *     length?: int,
     *     datetime_precision?: int,
     *     charset?: string,
     *     collation?: string,
     *     default?: string,
     *     extra?: string
     * }>>
     */
    public static function mysqlColumns(): array
    {
        $id = static fn (string $name, string $type = 'bigint'): array =>
            self::mysqlColumn($name, $type, false, unsigned: true);
        $nullableId = static fn (string $name, string $type = 'bigint'): array =>
            self::mysqlColumn($name, $type, true, unsigned: true);
        $ascii = static fn (
            string $name,
            string $type,
            int $length,
            bool $nullable = false,
            ?string $default = null
        ): array => self::mysqlColumn(
            $name,
            $type,
            $nullable,
            length: $length,
            charset: 'ascii',
            collation: 'ascii_bin',
            default: $default
        );
        $date = static fn (
            string $name,
            bool $nullable,
            ?string $default = null
        ): array => self::mysqlColumn(
            $name,
            'datetime',
            $nullable,
            datetimePrecision: 6,
            default: $default
        );
        $now = 'current_timestamp(6)';

        return [
            'users' => [
                self::mysqlColumn('id', 'bigint', false, unsigned: true, extra: 'auto_increment'),
                $ascii('public_id', 'char', 36),
                $ascii('email_canonical', 'varchar', 254),
                self::mysqlColumn('display_name', 'varchar', true, length: 120, charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci'),
                $ascii('status', 'varchar', 16),
                self::mysqlColumn('auth_version', 'bigint', false, unsigned: true, default: "'1'"),
                $nullableId('created_by_user_id'),
                $date('invited_at', true),
                $date('activated_at', true),
                $date('suspended_at', true),
                $date('last_login_at', true),
                $date('created_at', false, $now),
                $date('updated_at', false, $now),
            ],
            'credentials' => [
                $id('user_id'),
                $ascii('password_hash', 'varchar', 255, true),
                $date('password_set_at', true),
                $date('created_at', false, $now),
                $date('updated_at', false, $now),
            ],
            'roles' => [
                self::mysqlColumn('id', 'smallint', false, unsigned: true, extra: 'auto_increment'),
                $ascii('code', 'varchar', 64),
                $ascii('label_key', 'varchar', 128),
                self::mysqlColumn('is_protected', 'tinyint', false, unsigned: true, default: "'0'"),
                self::mysqlColumn('is_delegable', 'tinyint', false, unsigned: true, default: "'0'"),
                $date('created_at', false, $now),
            ],
            'capabilities' => [
                self::mysqlColumn('id', 'bigint', false, unsigned: true, extra: 'auto_increment'),
                $ascii('module_id', 'varchar', 63),
                $ascii('code', 'varchar', 128),
                $ascii('label_key', 'varchar', 160),
                self::mysqlColumn('is_delegable', 'tinyint', false, unsigned: true, default: "'0'"),
                $date('created_at', false, $now),
            ],
            'role_capabilities' => [
                $id('role_id', 'smallint'),
                $id('capability_id'),
                $date('created_at', false, $now),
            ],
            'user_roles' => [
                $id('user_id'),
                $id('role_id', 'smallint'),
                $nullableId('assigned_by_user_id'),
                $ascii('source', 'varchar', 16, false, "'manual'"),
                $date('created_at', false, $now),
            ],
            'user_capabilities' => [
                $id('user_id'),
                $id('capability_id'),
                $nullableId('assigned_by_user_id'),
                $date('created_at', false, $now),
            ],
            'action_tokens' => [
                self::mysqlColumn('id', 'bigint', false, unsigned: true, extra: 'auto_increment'),
                $id('user_id'),
                $ascii('purpose', 'varchar', 32),
                $ascii('token_hash', 'char', 64),
                $id('auth_version'),
                $nullableId('created_by_user_id'),
                $date('created_at', false, $now),
                $date('expires_at', false),
                $date('delivered_at', true),
                $date('used_at', true),
                $date('revoked_at', true),
            ],
            'sessions' => [
                self::mysqlColumn('id', 'bigint', false, unsigned: true, extra: 'auto_increment'),
                $ascii('public_id', 'char', 36),
                $nullableId('user_id'),
                $ascii('session_type', 'varchar', 16),
                $ascii('token_hash', 'char', 64),
                $ascii('csrf_token_hash', 'char', 64),
                $nullableId('auth_version'),
                $nullableId('pending_action_token_id'),
                $date('created_at', false, $now),
                $date('last_seen_at', false, $now),
                $date('idle_expires_at', false),
                $date('absolute_expires_at', false),
                $date('revoked_at', true),
            ],
            'rate_limits' => [
                $ascii('action', 'varchar', 64),
                $ascii('subject_hash', 'char', 64),
                $date('window_started_at', false),
                self::mysqlColumn('attempts', 'int', false, unsigned: true, default: "'0'"),
                $date('blocked_until', true),
                $date('updated_at', false, $now),
            ],
            'audit_log' => [
                self::mysqlColumn('id', 'bigint', false, unsigned: true, extra: 'auto_increment'),
                $ascii('request_id', 'char', 36),
                $nullableId('actor_user_id'),
                $ascii('actor_session_public_id', 'char', 36, true),
                $ascii('event_code', 'varchar', 96),
                $ascii('outcome', 'varchar', 16),
                $ascii('reason_code', 'varchar', 96, true),
                $ascii('target_type', 'varchar', 64, true),
                $ascii('target_public_id', 'varchar', 64, true),
                self::mysqlColumn('metadata_json', 'longtext', true, charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci'),
                $ascii('ip_hash', 'char', 64, true),
                $ascii('user_agent_hash', 'char', 64, true),
                $date('occurred_at', false, $now),
            ],
            'outbox' => [
                self::mysqlColumn('id', 'bigint', false, unsigned: true, extra: 'auto_increment'),
                $ascii('kind', 'varchar', 32),
                $id('user_id'),
                $ascii('locale', 'varchar', 16),
                $ascii('status', 'varchar', 16, false, "'pending'"),
                self::mysqlColumn('attempts', 'int', false, unsigned: true, default: "'0'"),
                $date('available_at', false, $now),
                $date('locked_at', true),
                $ascii('lock_token_hash', 'char', 64, true),
                $nullableId('action_token_id'),
                $ascii('last_error_code', 'varchar', 96, true),
                $date('created_at', false, $now),
                $date('sent_at', true),
            ],
            'state' => [
                $ascii('state_key', 'varchar', 100),
                self::mysqlColumn('value_text', 'longtext', false, charset: 'utf8mb4', collation: 'utf8mb4_unicode_ci'),
                $date('updated_at', false, $now),
            ],
        ];
    }

    /**
     * Logical non-primary indexes. Runtime names are scope-derived; the
     * verifier compares their semantics rather than these descriptive keys.
     *
     * @return array<string, array<string, array{unique: bool, columns: list<string>}>>
     */
    public static function indexes(): array
    {
        return [
            'users' => [
                'uq_wa_users_public' => self::index(true, ['public_id']),
                'uq_wa_users_email' => self::index(true, ['email_canonical']),
                'idx_wa_users_status' => self::index(false, ['status']),
                'idx_wa_users_creator' => self::index(false, ['created_by_user_id']),
            ],
            'credentials' => [],
            'roles' => [
                'uq_wa_roles_code' => self::index(true, ['code']),
            ],
            'capabilities' => [
                'uq_wa_capabilities_code' => self::index(true, ['code']),
                'idx_wa_capabilities_module' => self::index(false, ['module_id']),
            ],
            'role_capabilities' => [
                'idx_wa_role_caps_capability' => self::index(false, ['capability_id']),
            ],
            'user_roles' => [
                'idx_wa_user_roles_role' => self::index(false, ['role_id']),
                'idx_wa_user_roles_assigner' => self::index(false, ['assigned_by_user_id']),
            ],
            'user_capabilities' => [
                'idx_wa_user_caps_capability' => self::index(false, ['capability_id']),
                'idx_wa_user_caps_assigner' => self::index(false, ['assigned_by_user_id']),
            ],
            'action_tokens' => [
                'uq_wa_action_tokens_hash' => self::index(true, ['token_hash']),
                'idx_wa_action_tokens_user' => self::index(false, ['user_id', 'purpose', 'expires_at']),
                'idx_wa_action_tokens_creator' => self::index(false, ['created_by_user_id']),
            ],
            'sessions' => [
                'uq_wa_sessions_public' => self::index(true, ['public_id']),
                'uq_wa_sessions_hash' => self::index(true, ['token_hash']),
                'idx_wa_sessions_user' => self::index(false, ['user_id', 'revoked_at']),
                'idx_wa_sessions_expiry' => self::index(false, ['absolute_expires_at']),
                'idx_wa_sessions_pending_token' => self::index(false, ['pending_action_token_id']),
            ],
            'rate_limits' => [
                'idx_wa_rate_limits_blocked' => self::index(false, ['blocked_until']),
            ],
            'audit_log' => [
                'idx_wa_audit_request' => self::index(false, ['request_id']),
                'idx_wa_audit_actor_time' => self::index(false, ['actor_user_id', 'occurred_at']),
                'idx_wa_audit_event_time' => self::index(false, ['event_code', 'occurred_at']),
            ],
            'outbox' => [
                'uq_wa_outbox_action_token' => self::index(true, ['action_token_id']),
                'idx_wa_outbox_delivery' => self::index(false, ['status', 'available_at']),
                'idx_wa_outbox_user' => self::index(false, ['user_id']),
            ],
            'state' => [],
        ];
    }

    /** @return array<string, list<string>> */
    public static function primaryKeys(): array
    {
        return [
            'users' => ['id'],
            'credentials' => ['user_id'],
            'roles' => ['id'],
            'capabilities' => ['id'],
            'role_capabilities' => ['role_id', 'capability_id'],
            'user_roles' => ['user_id', 'role_id'],
            'user_capabilities' => ['user_id', 'capability_id'],
            'action_tokens' => ['id'],
            'sessions' => ['id'],
            'rate_limits' => ['action', 'subject_hash'],
            'audit_log' => ['id'],
            'outbox' => ['id'],
            'state' => ['state_key'],
        ];
    }

    /**
     * @return array<string, list<array{
     *     name: string,
     *     from: string,
     *     target_suffix: string,
     *     target_column: string,
     *     on_update: string,
     *     on_delete: string,
     *     match: string
     * }>>
     */
    public static function foreignKeys(): array
    {
        $fk = static fn (
            string $name,
            string $from,
            string $target,
            string $delete
        ): array => [
            'name' => $name,
            'from' => $from,
            'target_suffix' => $target,
            'target_column' => 'id',
            'on_update' => 'NO ACTION',
            'on_delete' => $delete,
            'match' => 'NONE',
        ];

        return [
            'users' => [$fk('fk_wa_users_creator', 'created_by_user_id', 'users', 'SET NULL')],
            'credentials' => [$fk('fk_wa_credentials_user', 'user_id', 'users', 'CASCADE')],
            'roles' => [],
            'capabilities' => [],
            'role_capabilities' => [
                $fk('fk_wa_role_caps_role', 'role_id', 'roles', 'CASCADE'),
                $fk('fk_wa_role_caps_capability', 'capability_id', 'capabilities', 'CASCADE'),
            ],
            'user_roles' => [
                $fk('fk_wa_user_roles_user', 'user_id', 'users', 'CASCADE'),
                $fk('fk_wa_user_roles_role', 'role_id', 'roles', 'CASCADE'),
                $fk('fk_wa_user_roles_assigner', 'assigned_by_user_id', 'users', 'SET NULL'),
            ],
            'user_capabilities' => [
                $fk('fk_wa_user_caps_user', 'user_id', 'users', 'CASCADE'),
                $fk('fk_wa_user_caps_capability', 'capability_id', 'capabilities', 'CASCADE'),
                $fk('fk_wa_user_caps_assigner', 'assigned_by_user_id', 'users', 'SET NULL'),
            ],
            'action_tokens' => [
                $fk('fk_wa_action_tokens_user', 'user_id', 'users', 'CASCADE'),
                $fk('fk_wa_action_tokens_creator', 'created_by_user_id', 'users', 'SET NULL'),
            ],
            'sessions' => [
                $fk('fk_wa_sessions_user', 'user_id', 'users', 'RESTRICT'),
                $fk('fk_wa_sessions_pending_token', 'pending_action_token_id', 'action_tokens', 'SET NULL'),
            ],
            'rate_limits' => [],
            'audit_log' => [$fk('fk_wa_audit_actor', 'actor_user_id', 'users', 'SET NULL')],
            'outbox' => [
                $fk('fk_wa_outbox_user', 'user_id', 'users', 'CASCADE'),
                $fk('fk_wa_outbox_action_token', 'action_token_id', 'action_tokens', 'SET NULL'),
            ],
            'state' => [],
        ];
    }

    /** @return array<string, array<string, string>> */
    public static function mysqlChecks(): array
    {
        return [
            'users' => [
                'chk_wa_users_status' => "status in ('invited','active','suspended')",
                'chk_wa_users_auth_version' => 'auth_version>0',
                'chk_wa_users_email_lower' => 'email_canonical=lower(email_canonical)',
                'chk_wa_users_public_id' => 'char_length(public_id)=36',
            ],
            'credentials' => [
                'chk_wa_credentials_password' => '(password_hash is null and password_set_at is null)or(password_hash is not null and password_set_at is not null)',
            ],
            'roles' => [
                'chk_wa_roles_protected' => 'is_protected in (0,1)',
                'chk_wa_roles_delegable' => 'is_delegable in (0,1)',
            ],
            'capabilities' => [
                'chk_wa_capabilities_delegable' => 'is_delegable in (0,1)',
            ],
            'role_capabilities' => [],
            'user_roles' => [
                'chk_wa_user_roles_source' => "source in ('bootstrap','manual','system')",
            ],
            'user_capabilities' => [],
            'action_tokens' => [
                'chk_wa_action_tokens_purpose' => "purpose in ('invite','password_reset')",
                'chk_wa_action_tokens_hash' => 'char_length(token_hash)=64',
                'chk_wa_action_tokens_version' => 'auth_version>0',
                'chk_wa_action_tokens_expiry' => 'expires_at>created_at',
                'chk_wa_action_tokens_terminal' => 'used_at is null or revoked_at is null',
            ],
            'sessions' => [
                'chk_wa_sessions_type' => "session_type in ('preauth','authenticated')",
                'chk_wa_sessions_public' => 'char_length(public_id)=36',
                'chk_wa_sessions_hash' => 'char_length(token_hash)=64',
                'chk_wa_sessions_csrf' => 'char_length(csrf_token_hash)=64',
                // The user FK uses RESTRICT because MySQL prohibits CASCADE or
                // SET NULL on a column referenced by a CHECK. The pending-token
                // half is additionally audited against stored rows.
                'chk_wa_sessions_identity' => "(session_type='preauth' and user_id is null and auth_version is null)or(session_type='authenticated' and user_id is not null and auth_version is not null)",
                'chk_wa_sessions_expiry' => 'idle_expires_at>created_at and absolute_expires_at>=idle_expires_at',
            ],
            'rate_limits' => [
                'chk_wa_rate_limits_hash' => 'char_length(subject_hash)=64',
            ],
            'audit_log' => [
                'chk_wa_audit_outcome' => "outcome in ('success','failure','denied')",
                'chk_wa_audit_request' => 'char_length(request_id)=36',
                'chk_wa_audit_ip_hash' => 'ip_hash is null or char_length(ip_hash)=64',
                'chk_wa_audit_ua_hash' => 'user_agent_hash is null or char_length(user_agent_hash)=64',
            ],
            'outbox' => [
                'chk_wa_outbox_kind' => "kind in ('invite','password_reset')",
                'chk_wa_outbox_status' => "status in ('pending','processing','sent','failed')",
                'chk_wa_outbox_lock' => "(status='processing' and locked_at is not null and lock_token_hash is not null)or(status<>'processing' and locked_at is null and lock_token_hash is null)",
                'chk_wa_outbox_sent' => "(status='sent' and sent_at is not null)or(status<>'sent' and sent_at is null)",
                'chk_wa_outbox_lock_hash' => 'lock_token_hash is null or char_length(lock_token_hash)=64',
            ],
            'state' => [],
        ];
    }

    /** @return array<string, list<string>> */
    public static function sqliteChecks(): array
    {
        $checks = self::mysqlChecks();
        $checks['sessions']['chk_wa_sessions_identity'] =
            "(session_type='preauth' and user_id is null and auth_version is null)or(session_type='authenticated' and user_id is not null and auth_version is not null and pending_action_token_id is null)";
        foreach ($checks as &$tableChecks) {
            $tableChecks = array_values(array_map(
                static fn (string $expression): string => str_replace(
                    'char_length(',
                    'length(',
                    $expression
                ),
                $tableChecks
            ));
        }
        unset($tableChecks);
        $checks['rate_limits'][] = 'attempts>=0';
        $checks['outbox'][] = 'attempts>=0';

        return $checks;
    }

    /** @return array<string, array{label_key: string, is_protected: int, is_delegable: int}> */
    public static function roles(): array
    {
        return [
            'system_superadmin' => [
                'label_key' => 'webadmin.roles.system_superadmin',
                'is_protected' => 1,
                'is_delegable' => 0,
            ],
            'site_admin' => [
                'label_key' => 'webadmin.roles.site_admin',
                'is_protected' => 1,
                'is_delegable' => 0,
            ],
            'editor' => [
                'label_key' => 'webadmin.roles.editor',
                'is_protected' => 0,
                'is_delegable' => 1,
            ],
        ];
    }

    /** @return array<string, array{module_id: string, label_key: string, is_delegable: int}> */
    public static function capabilities(): array
    {
        return [
            'webadmin.access' => self::capability('access', 0),
            'webadmin.profile.manage_self' => self::capability('profile_manage_self', 0),
            'webadmin.users.view' => self::capability('users_view', 1),
            'webadmin.users.invite' => self::capability('users_invite', 0),
            'webadmin.users.suspend' => self::capability('users_suspend', 0),
            'webadmin.users.capabilities.manage' => self::capability('users_capabilities_manage', 0),
            'webadmin.audit.view' => self::capability('audit_view', 0),
            'webadmin.system.diagnose' => self::capability('system_diagnose', 0),
        ];
    }

    /** @return array<string, list<string>> */
    public static function roleCapabilities(): array
    {
        $all = array_keys(self::capabilities());

        return [
            'system_superadmin' => $all,
            'site_admin' => [
                'webadmin.access',
                'webadmin.profile.manage_self',
                'webadmin.users.view',
                'webadmin.users.invite',
                'webadmin.users.suspend',
                'webadmin.users.capabilities.manage',
                'webadmin.audit.view',
            ],
            'editor' => [
                'webadmin.access',
                'webadmin.profile.manage_self',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function sqliteColumn(
        string $name,
        string $type,
        bool $nullable,
        int $primaryPosition = 0,
        ?string $default = null,
        bool $autoincrement = false,
        bool $binaryText = false
    ): array {
        $column = [
            'name' => $name,
            'type' => $type,
            'nullable' => $nullable,
            'primary_position' => $primaryPosition,
        ];
        if ($default !== null) {
            $column['default'] = $default;
        }
        if ($autoincrement) {
            $column['autoincrement'] = true;
        }
        if ($binaryText) {
            $column['binary_text'] = true;
        }

        return $column;
    }

    /** @return array<string, mixed> */
    private static function mysqlColumn(
        string $name,
        string $type,
        bool $nullable,
        bool $unsigned = false,
        ?int $length = null,
        ?int $datetimePrecision = null,
        ?string $charset = null,
        ?string $collation = null,
        ?string $default = null,
        ?string $extra = null
    ): array {
        $column = [
            'name' => $name,
            'type' => $type,
            'nullable' => $nullable,
        ];
        if ($unsigned) {
            $column['unsigned'] = true;
        }
        if ($length !== null) {
            $column['length'] = $length;
        }
        if ($datetimePrecision !== null) {
            $column['datetime_precision'] = $datetimePrecision;
        }
        if ($charset !== null) {
            $column['charset'] = $charset;
        }
        if ($collation !== null) {
            $column['collation'] = $collation;
        }
        if ($default !== null) {
            $column['default'] = $default;
        }
        if ($extra !== null) {
            $column['extra'] = $extra;
        }

        return $column;
    }

    /** @param list<string> $columns @return array{unique: bool, columns: list<string>} */
    private static function index(bool $unique, array $columns): array
    {
        return ['unique' => $unique, 'columns' => $columns];
    }

    /** @return array{module_id: string, label_key: string, is_delegable: int} */
    private static function capability(string $labelSuffix, int $delegable): array
    {
        return [
            'module_id' => 'webadmin',
            'label_key' => 'webadmin.capabilities.' . $labelSuffix,
            'is_delegable' => $delegable,
        ];
    }
}
