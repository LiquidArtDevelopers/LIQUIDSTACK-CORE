<?php

declare(strict_types=1);

use App\Core\WebAdmin\Http\PhpErrorLogWebAdminRuntimeIssueReporter;
use PHPUnit\Framework\TestCase;

final class PhpErrorLogWebAdminRuntimeIssueReporterTest extends TestCase
{
    private string $errorLog;
    private string $previousErrorLog;
    private string $previousLogErrors;

    protected function setUp(): void
    {
        $this->errorLog = sys_get_temp_dir()
            . '/liquidstack-webadmin-error-'
            . bin2hex(random_bytes(8)) . '.log';
        $this->previousErrorLog = (string) ini_get('error_log');
        $this->previousLogErrors = (string) ini_get('log_errors');
        self::assertNotFalse(ini_set('log_errors', '1'));
        self::assertNotFalse(ini_set('error_log', $this->errorLog));
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        ini_set('log_errors', $this->previousLogErrors);
        if (is_file($this->errorLog)) {
            unlink($this->errorLog);
        }
    }

    public function testOnlyStableIssueCodeIsWritten(): void
    {
        $reporter = new PhpErrorLogWebAdminRuntimeIssueReporter();
        $reporter->report('webadmin.schema_not_ready');

        $contents = (string) file_get_contents($this->errorLog);
        self::assertStringContainsString(
            '[LiquidStack WebAdmin] webadmin.schema_not_ready',
            $contents
        );
    }

    public function testUnexpectedTextIsRedactedBeforeLogging(): void
    {
        $reporter = new PhpErrorLogWebAdminRuntimeIssueReporter();
        $reporter->report('secret=value');

        $contents = (string) file_get_contents($this->errorLog);
        self::assertStringContainsString(
            '[LiquidStack WebAdmin] webadmin.runtime_unavailable',
            $contents
        );
        self::assertStringNotContainsString('secret=value', $contents);
    }
}
