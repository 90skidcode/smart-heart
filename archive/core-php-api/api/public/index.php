<?php

declare(strict_types=1);

// Front controller. Local: composer serve (php -S localhost:8080 public/index.php)

use SmartHeart\App;
use SmartHeart\Config;
use SmartHeart\Http\Request;
use SmartHeart\Infra\Secrets;

require dirname(__DIR__) . '/vendor/autoload.php';

$env = dirname(__DIR__) . '/.env';
App::kernel(Config::fromEnvironment($env), Secrets::fromEnvironment($env))
    ->handle(Request::fromGlobals(App::BASE_PATH))
    ->send();
