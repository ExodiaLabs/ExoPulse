<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$dotenv = new Symfony\Component\Dotenv\Dotenv();
$dotenv->bootEnv(dirname(__DIR__).'/.env');
// Synthetic authentication fixtures must also override the development container environment.
$dotenv->overload(dirname(__DIR__).'/.env.test');
