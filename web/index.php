<?php

require_once __DIR__ . '/../vendor/autoload.php';

/* @var $app \Silex\Application */
$app = require_once __DIR__ . '/../app/app.php';
$app['http_cache']->run();