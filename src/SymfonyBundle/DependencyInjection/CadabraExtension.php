<?php

declare(strict_types=1);

namespace Cadabra\SymfonyBundle\DependencyInjection;

use Cadabra\Client\CadabraClient;
use Cadabra\SymfonyBundle\Middleware\CadabraMiddleware;
use Cadabra\SymfonyBundle\Service\CacheStrategy;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Loads and manages bundle configuration.
 */
class CadabraExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Register CadabraClient
        $this->registerClient($container, $config);

        // Register CacheStrategy
        $this->registerCacheStrategy($container, $config);

        // Register Middleware
        $this->registerMiddleware($container, $config);
    }

    private function registerClient(ContainerBuilder $container, array $config): void
    {
        // Register HTTP client if not already defined
        if (!$container->has('cadabra.http_client')) {
            $httpClientDef = new Definition(Psr18Client::class);
            $container->setDefinition('cadabra.http_client', $httpClientDef);
        }

        // Register Cadabra client
        $clientDef = new Definition(CadabraClient::class, [
            new Reference('cadabra.http_client'),
            new Reference('cadabra.http_client'),
            new Reference('cadabra.http_client'),
            $config['service_url'],
            $config['prefix'],
            5, // timeout
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
        ]);
        $clientDef->setPublic(false);

        $container->setDefinition('cadabra.client', $clientDef);
    }

    private function registerCacheStrategy(ContainerBuilder $container, array $config): void
    {
        $strategyDef = new Definition(CacheStrategy::class, [[]]);
        $strategyDef->setPublic(false);

        $container->setDefinition('cadabra.cache_strategy', $strategyDef);
    }

    private function registerMiddleware(ContainerBuilder $container, array $config): void
    {
        $middlewareDef = new Definition(CadabraMiddleware::class, [
            new Reference('cadabra.client'),
            new Reference('cadabra.cache_strategy'),
            $config['prefix'],
            new Reference('logger', ContainerBuilder::IGNORE_ON_INVALID_REFERENCE),
        ]);
        $middlewareDef->addTag('doctrine.middleware');
        $middlewareDef->setPublic(false);

        $container->setDefinition('cadabra.middleware', $middlewareDef);
    }
}
