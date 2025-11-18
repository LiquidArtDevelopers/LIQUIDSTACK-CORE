<?php

namespace App\Core\Routing;

use App\Core\Support\Paths;

class UrlResolver
{
    public static function resolve(array $server, array $cookies, array $env): UrlContext
    {
        $langs = require Paths::appPath() . '/config/langs.php';
        if (!is_array($langs)) {
            $langs = [];
        }

        $langs       = array_values(array_unique(array_map('strtolower', $langs)));
        $defaultLang = strtolower((string) ($env['LANG_DEFAULT'] ?? ($langs[0] ?? 'es')));
        if (!in_array($defaultLang, $langs, true) && $langs !== []) {
            $defaultLang = $langs[0];
        }

        $multiLang      = trim((string) ($env['MULTILANG'] ?? '0'));
        $esSimplificado = trim((string) ($env['ES_SIMPLIFICADO'] ?? '0'));

        $lang = null;
        if (!empty($cookies['cookie_custom_lang'])) {
            $candidate = strtolower(trim((string) $cookies['cookie_custom_lang']));
            if (in_array($candidate, $langs, true)) {
                $lang = $candidate;
            }
        }

        if ($lang === null && isset($server['HTTP_ACCEPT_LANGUAGE'])) {
            $candidate = strtolower(substr((string) $server['HTTP_ACCEPT_LANGUAGE'], 0, 2));
            if (in_array($candidate, $langs, true)) {
                $lang = $candidate;
            }
        }

        if ($lang === null || !in_array($lang, $langs, true)) {
            $lang = $defaultLang;
        }

        $rawUri = $server['REQUEST_URI'] ?? '/';
        $path   = parse_url((string) $rawUri, PHP_URL_PATH);
        if ($path === false || $path === null || $path === '') {
            $path = '/';
        } else {
            $path = urldecode($path);
        }

        $url = ($path === '/') ? '/' : rtrim($path, '/');
        if ($url === '') {
            $url = '/';
        }

        $queryString = $server['QUERY_STRING'] ?? '';
        if (!is_string($queryString)) {
            $queryString = '';
        }

        $urlWithQuery = $url;
        if ($queryString !== '') {
            $urlWithQuery .= '?' . $queryString;
        }

        $urlLang = null;

        if ($multiLang === '1') {
            if ($url === '/') {
                if ($esSimplificado !== '1') {
                    $redirectLang = in_array($lang, $langs, true) ? $lang : $defaultLang;
                    $redirectPath = '/' . $redirectLang;

                    header('HTTP/1.1 301 Moved Permanently');
                    header('Location: ' . $redirectPath);
                    exit;
                }
                $urlLang = $defaultLang;
            } else {
                $segments = explode('/', ltrim($url, '/'));
                $urlLang  = strtolower($segments[0] ?? '');
            }

            if ($urlLang === '' || !in_array($urlLang, $langs, true)) {
                $redirectLang = in_array($lang, $langs, true) ? $lang : $defaultLang;
                $redirectPath = '/' . $redirectLang;

                header('HTTP/1.1 301 Moved Permanently');
                header('Location: ' . $redirectPath);
                exit;
            }

            $lang = $urlLang;

            if ($url === '/' . $defaultLang && $esSimplificado === '1') {
                header('HTTP/1.1 301 Moved Permanently');
                header('Location: /');
                exit;
            }
        }

        return new UrlContext($langs, $lang, $url, $urlWithQuery, $urlLang);
    }
}
