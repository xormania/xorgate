<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $environment = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
    if (!in_array($environment, ['dev', 'test'], true)) {
        throw new \InvalidArgumentException('Mate development diagnostics require APP_ENV=dev or test.');
    }

    $container->parameters()
        ->set('mate.invocation', './bin/mate')
        ->set('mate.php_version', '8.5')
        ->set('ai_mate_symfony.cache_dir', '%mate.root_dir%/var/cache/'.$environment)
        ->set('ai_mate_symfony.profiler_dir', '%mate.root_dir%/var/cache/'.$environment.'/profiler')
        ->set('ai_mate_monolog.log_dir', '%mate.root_dir%/var/log')
    ;

    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
    ;
};
