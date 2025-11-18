<?php

use App\Core\Application;
use App\Core\Support\Paths;

require_once __DIR__ . '/../vendor/autoload.php';

$projectRoot = dirname(__DIR__);
Paths::setProjectRoot($projectRoot);

$app = new Application($projectRoot);
$app->run();
