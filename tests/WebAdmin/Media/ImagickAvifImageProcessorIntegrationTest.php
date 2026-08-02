<?php

declare(strict_types=1);

namespace Tests\WebAdmin\Media;

use App\Core\Http\UploadedFile;
use App\Core\WebAdmin\Media\ImagickAvifImageProcessor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[Group('imagick-integration')]
final class ImagickAvifImageProcessorIntegrationTest extends TestCase
{
    private string $sandbox;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        if (getenv('LIQUIDSTACK_TEST_IMAGICK_AVIF') !== '1') {
            self::markTestSkipped(
                'Define LIQUIDSTACK_TEST_IMAGICK_AVIF=1 para probar el codec real.'
            );
        }
        if (!ImagickAvifImageProcessor::runtimeIsReady()) {
            self::markTestSkipped('Imagick con AVIF real no esta disponible.');
        }
        $this->filesystem = new Filesystem();
        $this->sandbox = sys_get_temp_dir() . '/liquidstack-imagick-avif-'
            . bin2hex(random_bytes(8));
        $this->filesystem->mkdir([$this->sandbox, $this->sandbox . '/staging']);
    }

    protected function tearDown(): void
    {
        if (isset($this->filesystem, $this->sandbox)) {
            $this->filesystem->remove($this->sandbox);
        }
    }

    public function testRealCodecGeneratesVerifiedMetadataFreeVariantsWithoutUpscale(): void
    {
        $source = $this->sandbox . '/source.png';
        $image = new \Imagick();
        $image->newImage(1200, 800, new \ImagickPixel('rgba(10,80,160,0.6)'));
        $image->setImageFormat('PNG');
        $image->setImageProperty('comment', 'private metadata must disappear');
        self::assertTrue($image->writeImage($source));
        $image->clear();
        $image->destroy();

        $upload = UploadedFile::fromTestInput([
            'name' => 'private-name.png',
            'type' => 'application/octet-stream',
            'tmp_name' => $source,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($source),
        ]);
        self::assertInstanceOf(UploadedFile::class, $upload);
        $processed = (new ImagickAvifImageProcessor())->process(
            $upload,
            $this->sandbox . '/staging'
        );

        self::assertSame('image/png', $processed->sourceMime());
        self::assertSame(1200, $processed->sourceWidth());
        self::assertSame(800, $processed->sourceHeight());
        self::assertSame([480, 900, 1200], array_map(
            static fn ($variant): int => $variant->width(),
            $processed->variants()
        ));
        foreach ($processed->variants() as $variant) {
            $path = $this->sandbox . '/staging/' . $variant->fileName();
            self::assertFileExists($path);
            self::assertSame('image/avif', (new \finfo(
                FILEINFO_MIME_TYPE
            ))->file($path));
            $probe = new \Imagick($path);
            self::assertSame([], $probe->getImageProfiles('*', false));
            self::assertSame([], $probe->getImageProperties('comment', false));
            self::assertLessThanOrEqual(1200, $probe->getImageWidth());
            $probe->clear();
            $probe->destroy();
        }
    }
}
