<?php

declare(strict_types=1);

namespace Tests\Commerce;

use App\Core\Commerce\Http\CommercePublicHttpRuntimeException;
use App\Core\Commerce\Http\CommercePublicShellRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CommercePublicShellRendererTest extends TestCase
{
    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir() . '/ls-commerce-shell-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->root . '/App/views');
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testRendersBothProjectOwnedShellsWithLocale(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/App/views/commerce.php',
            '<?php echo "catalog:" . htmlspecialchars($lang);'
        );
        $this->filesystem->dumpFile(
            $this->root . '/App/views/commerce-inquiry.php',
            '<?php echo "inquiry:" . htmlspecialchars($lang);'
        );

        $renderer = new CommercePublicShellRenderer($this->root);

        self::assertSame('catalog:es', $renderer->renderCatalog('es'));
        self::assertSame('inquiry:eu', $renderer->renderInquiry('eu'));
    }

    public function testFailsClosedWhenEitherManagedShellIsMissing(): void
    {
        $this->filesystem->dumpFile(
            $this->root . '/App/views/commerce.php',
            '<?php echo "catalog";'
        );

        $this->expectException(CommercePublicHttpRuntimeException::class);
        new CommercePublicShellRenderer($this->root);
    }
}
