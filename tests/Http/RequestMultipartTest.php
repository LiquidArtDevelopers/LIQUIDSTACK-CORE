<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

final class RequestMultipartTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
    }

    public function testFlatMultipartUploadIsBoundedAndFilenameFree(): void
    {
        $path = $this->temporaryFile('not-an-image-yet');
        $request = Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/media/upload',
                'CONTENT_TYPE' => 'multipart/form-data; boundary=LiquidBoundary',
                'CONTENT_LENGTH' => '2048',
            ],
            [],
            ['csrf' => 'token', 'label' => 'Portada'],
            [],
            [],
            '',
            ['image' => [
                'name' => 'private-customer-name.png',
                'full_path' => 'secret/folder/private-customer-name.png',
                'type' => 'application/x-untrusted-browser-mime',
                'tmp_name' => $path,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($path),
            ]]
        );

        self::assertTrue($request->isValid());
        self::assertTrue($request->isMultipartFormData());
        self::assertTrue($request->hasValidUploadedFiles());
        self::assertSame(['image'], array_keys($request->uploadedFiles()));
        $upload = $request->uploadedFile('image');
        self::assertInstanceOf(UploadedFile::class, $upload);
        self::assertSame(filesize($path), $upload->size());

        $debug = print_r($upload, true);
        self::assertStringContainsString('[redacted]', $debug);
        self::assertStringNotContainsString($path, $debug);
        self::assertStringNotContainsString('private-customer-name', $debug);
        self::assertStringNotContainsString('x-untrusted', $debug);
        self::assertSame(
            ["\0App\\Core\\Http\\UploadedFile\0temporaryPath", "\0App\\Core\\Http\\UploadedFile\0size"],
            array_keys((array) $upload)
        );
    }

    public function testMultipartRejectsNestedMultipleAndCorruptFiles(): void
    {
        $path = $this->temporaryFile('contents');
        $server = $this->multipartServer();
        $valid = $this->entry($path);

        $nested = $valid;
        $nested['name'] = ['one.jpg'];
        self::assertFalse(Request::fromInput(
            $server,
            [],
            [],
            [],
            [],
            '',
            ['image' => $nested]
        )->hasValidUploadedFiles());

        self::assertFalse(Request::fromInput(
            $server,
            [],
            [],
            [],
            [],
            '',
            ['image' => $valid, 'second' => $valid]
        )->hasValidUploadedFiles());

        $corrupt = $valid;
        $corrupt['size'] = (int) $corrupt['size'] + 1;
        self::assertFalse(Request::fromInput(
            $server,
            [],
            [],
            [],
            [],
            '',
            ['image' => $corrupt]
        )->hasValidUploadedFiles());
    }

    public function testFilesRequireCanonicalMultipartContentTypeAndBoundary(): void
    {
        $path = $this->temporaryFile('contents');
        $files = ['image' => $this->entry($path)];

        foreach ([
            null,
            'multipart/form-data',
            'multipart/form-data; boundary=',
            'multipart/form-data; boundary=' . str_repeat('a', 71),
            'application/octet-stream',
        ] as $contentType) {
            $server = [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin/media/upload',
            ];
            if ($contentType !== null) {
                $server['CONTENT_TYPE'] = $contentType;
            }
            $request = Request::fromInput(
                $server,
                [],
                [],
                [],
                [],
                '',
                $files
            );
            self::assertFalse($request->isValid());
            self::assertFalse($request->hasValidUploadedFiles());
        }
    }

    public function testMultipartLimitDoesNotRaiseNormalBodyLimit(): void
    {
        $normal = Request::fromInput(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/admin',
                'CONTENT_LENGTH' => (string) (Request::MAX_BODY_BYTES + 1),
            ],
            [],
            [],
            [],
            [],
            str_repeat('a', Request::MAX_BODY_BYTES + 1)
        );
        self::assertFalse($normal->hasValidBody());

        $path = $this->temporaryFile('contents');
        $atLimit = Request::fromInput(
            $this->multipartServer(Request::MAX_MULTIPART_BODY_BYTES),
            [],
            [],
            [],
            [],
            '',
            ['image' => $this->entry($path)]
        );
        self::assertTrue($atLimit->hasValidBody());

        $tooLarge = Request::fromInput(
            $this->multipartServer(Request::MAX_MULTIPART_BODY_BYTES + 1),
            [],
            [],
            [],
            [],
            '',
            ['image' => $this->entry($path)]
        );
        self::assertFalse($tooLarge->hasValidBody());

        $withoutLength = $this->multipartServer();
        unset($withoutLength['CONTENT_LENGTH']);
        self::assertTrue(Request::fromInput(
            $withoutLength,
            [],
            [],
            [],
            [],
            '',
            ['image' => $this->entry($path)]
        )->isValid());
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ls-upload-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /** @return array<string, mixed> */
    private function entry(string $path): array
    {
        return [
            'name' => 'ignored.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];
    }

    /** @return array<string, mixed> */
    private function multipartServer(int $length = 2048): array
    {
        return [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/admin/media/upload',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=LiquidBoundary',
            'CONTENT_LENGTH' => (string) $length,
        ];
    }
}
