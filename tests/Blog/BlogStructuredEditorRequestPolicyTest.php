<?php

declare(strict_types=1);

namespace Tests\Blog;

use App\Core\Blog\Http\BlogStructuredEditorRequestPolicy;
use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class BlogStructuredEditorRequestPolicyTest extends TestCase
{
    private const POST = '10000000-0000-4000-8000-000000000001';
    private const REVISION = '10000000-0000-4000-8000-000000000002';

    public function testAcceptsExactEditorAndRevisionNavigationForGetAndHead(): void
    {
        $policy = new BlogStructuredEditorRequestPolicy();
        foreach (['GET', 'HEAD'] as $method) {
            self::assertTrue($policy->acceptsEditor($this->request(
                $method,
                ['post' => self::POST, 'locale' => 'es']
            )));
            self::assertTrue($policy->acceptsRevisions($this->request(
                $method,
                [
                    'post' => self::POST,
                    'locale' => 'es',
                    'revision' => self::REVISION,
                ]
            )));
        }
        self::assertFalse($policy->acceptsEditor($this->request(
            'GET',
            ['post' => self::POST, 'locale' => 'es', 'extra' => '1']
        )));
    }

    public function testAcceptsOnlyExactBoundedStructuredSave(): void
    {
        $policy = new BlogStructuredEditorRequestPolicy();
        $form = $this->saveForm();
        self::assertTrue($policy->acceptsSave($this->request('POST', [], $form)));

        $form['document_json'] = str_repeat('x', 300_001);
        self::assertFalse($policy->acceptsSave($this->request('POST', [], $form)));

        $form = $this->saveForm();
        $form['body_text'] = 'Competing plain body';
        self::assertFalse($policy->acceptsSave($this->request('POST', [], $form)));
    }

    public function testAcceptsOnlyExactRestorePayload(): void
    {
        $policy = new BlogStructuredEditorRequestPolicy();
        $form = [
            'csrf' => 'csrf',
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '2',
            'revision' => self::REVISION,
        ];
        self::assertTrue($policy->acceptsRestore($this->request(
            'POST',
            [],
            $form
        )));
        $form['revision'] = '../revision';
        self::assertFalse($policy->acceptsRestore($this->request(
            'POST',
            [],
            $form
        )));
    }

    /** @return array<string, string> */
    private function saveForm(): array
    {
        return [
            'csrf' => 'csrf',
            'post' => self::POST,
            'locale' => 'es',
            'lock_version' => '1',
            'document_json' => '{}',
            'h1' => 'Matrix H1',
            'slug' => 'matrix',
            'seo_title' => 'Matrix SEO',
            'meta_description' => 'Matrix description',
            'excerpt' => 'Matrix excerpt',
        ];
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $form
     */
    private function request(
        string $method,
        array $query = [],
        array $form = []
    ): Request {
        return Request::fromInput(
            [
                'REQUEST_METHOD' => $method,
                'REQUEST_URI' => '/admin/blog/editor',
                'HTTPS' => 'on',
            ],
            $query,
            $form,
            [],
            $method === 'POST'
                ? ['Content-Type' => 'application/x-www-form-urlencoded']
                : []
        );
    }
}
