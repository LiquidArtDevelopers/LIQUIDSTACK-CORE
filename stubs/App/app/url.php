<?php

use App\Core\Routing\UrlResolver;
use App\Core\Support\Paths;

require_once __DIR__ . '/../../../vendor/autoload.php';

Paths::setProjectRoot(dirname(__DIR__, 2));

$context = UrlResolver::resolve($_SERVER, $_COOKIE, $_ENV);

$langs        = $context->langs;
$lang         = $context->lang;
$url          = $context->url;
$urlWithQuery = $context->urlWithQuery;
$urlLang      = $context->urlLang;
