<?php

declare(strict_types=1);

require \dirname(__DIR__).'/vendor/autoload.php';

if (class_exists(\Symfony\Component\Dotenv\Dotenv::class)) {
    (new \Symfony\Component\Dotenv\Dotenv())->bootEnv(\dirname(__DIR__).'/.env');
}
