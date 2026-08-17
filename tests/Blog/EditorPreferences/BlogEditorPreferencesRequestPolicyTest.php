<?php

declare(strict_types=1);

namespace Tests\Blog\EditorPreferences;

use App\Core\Blog\EditorPreferences\Http\BlogEditorPreferencesRequestPolicy;
use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class BlogEditorPreferencesRequestPolicyTest extends TestCase
{
    public function testAcceptsOnlyEmptyGetAndHeadNavigation(): void
    {
        $policy = new BlogEditorPreferencesRequestPolicy();

        self::assertTrue($policy->acceptsIndex($this->request('GET')));
        self::assertTrue($policy->acceptsIndex($this->request('HEAD')));
        self::assertFalse($policy->acceptsIndex($this->request(
            'GET',
            ['saved' => '1']
        )));
        self::assertFalse($policy->acceptsIndex($this->request(
            'POST',
            [],
            $this->validForm()
        )));
    }

    public function testAcceptsExactAllowlistedFormIncludingFirstWriteLock(): void
    {
        $policy = new BlogEditorPreferencesRequestPolicy();
        $form = $this->validForm();

        self::assertTrue($policy->acceptsSave($this->request(
            'POST',
            [],
            $form
        )));
        $form['lock_version'] = '7';
        self::assertTrue($policy->acceptsSave($this->request(
            'POST',
            [],
            $form
        )));
        $preferences = $policy->preferences($this->request(
            'POST',
            [],
            $form
        ));
        self::assertSame(
            'accent-line',
            $preferences->headingDefault('h2')->preset()
        );
        self::assertSame(
            'color04',
            $preferences->headingDefault('h4')->textColor()
        );
        self::assertSame(
            'color05',
            $preferences->headingDefault('h5')->textColor()
        );
    }

    public function testRejectsUnknownKeysTokensAndMalformedLocks(): void
    {
        $policy = new BlogEditorPreferencesRequestPolicy();

        foreach (['-1', '01', '1.0', '92233720368547758070'] as $lock) {
            $form = $this->validForm();
            $form['lock_version'] = $lock;
            self::assertFalse($policy->acceptsSave($this->request(
                'POST',
                [],
                $form
            )));
        }

        $form = $this->validForm();
        $form['h3_preset'] = 'free-css';
        self::assertFalse($policy->acceptsSave($this->request(
            'POST',
            [],
            $form
        )));

        foreach (['#ff0000', 'color06', 'rgba(1, 2, 3, 0.5)'] as $color) {
            $form = $this->validForm();
            $form['h4_text_color'] = $color;
            self::assertFalse($policy->acceptsSave($this->request(
                'POST',
                [],
                $form
            )), $color);
        }

        $form = $this->validForm();
        $form['extra'] = '1';
        self::assertFalse($policy->acceptsSave($this->request(
            'POST',
            [],
            $form
        )));
    }

    /** @return array<string, string> */
    private function validForm(): array
    {
        $form = ['csrf' => 'csrf', 'lock_version' => '0'];
        foreach (['h2', 'h3', 'h4', 'h5', 'h6'] as $level) {
            $form[$level . '_preset'] = $level === 'h2'
                ? 'accent-line' : 'default';
            $form[$level . '_font_size'] = 'default';
            $form[$level . '_font_weight'] = 'default';
            $form[$level . '_text_color'] = match ($level) {
                'h4' => 'color04',
                'h5' => 'color05',
                default => 'default',
            };
            $form[$level . '_text_align'] = 'start';
        }

        return $form;
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
                'REQUEST_URI' => '/admin/blog/settings/presentation',
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
