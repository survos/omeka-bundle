<?php

declare(strict_types=1);

namespace Survos\OmekaBundle;

use Survos\OmekaBundle\Client\OmekaClient;
use Survos\OmekaBundle\Client\OmekaClientRegistry;
use Survos\OmekaBundle\Command\OmekaCrawlCommand;
use Survos\OmekaBundle\Command\OmekaCreateItemCommand;
use Survos\OmekaBundle\Command\OmekaCreateResourcesCommand;
use Survos\OmekaBundle\Command\OmekaCreateSiteCommand;
use Survos\OmekaBundle\Command\OmekaCustomVocabTermsCommand;
use Survos\OmekaBundle\Command\OmekaDirectoryCommand;
use Survos\OmekaBundle\Command\OmekaListItemsCommand;
use Survos\OmekaBundle\Command\OmekaListPropertiesCommand;
use Survos\OmekaBundle\Command\OmekaListResourceTemplatesCommand;
use Survos\OmekaBundle\Command\OmekaListVocabulariesCommand;
use Survos\OmekaBundle\Command\OmekaSyncCommand;
use Survos\OmekaBundle\Crawler\OmekaDirectoryParser;
use Survos\OmekaBundle\Crawler\OmekaPublicCrawler;
use Survos\OmekaBundle\MessageHandler\OmekaCrawlMessageHandler;
use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\CachingHttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SurvosOmekaBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();

        $root
            ->children()
                ->arrayNode('clients')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('api_url')->defaultNull()->end()
                            ->scalarNode('key_identity')->defaultNull()->end()
                            ->scalarNode('key_credential')->defaultNull()->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('crawler_cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('directory')
                            ->defaultValue('%kernel.cache_dir%/omeka_http')
                            ->info('Filesystem path for cached Omeka HTTP responses')
                        ->end()
                        ->integerNode('default_ttl')
                            ->defaultValue(86400)
                            ->info('Default TTL in seconds for cached responses (default: 24h)')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('crawler_politeness')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('min_interval_ms')
                            ->defaultValue(250)
                            ->min(0)
                            ->info('Minimum gap between crawler requests; many public Omeka-S sites are small library-hosted boxes')
                        ->end()
                        ->integerNode('max_retries')
                            ->defaultValue(5)
                            ->min(0)
                            ->info('Retries on 429/5xx/transport errors, exponential backoff honoring Retry-After')
                        ->end()
                        ->integerNode('retry_delay_ms')
                            ->defaultValue(2000)
                            ->min(0)
                            ->info('First retry delay; doubles each attempt, capped at 60s')
                        ->end()
                        ->arrayNode('headers')
                            ->normalizeKeys(false)
                            ->useAttributeAsKey('name')
                            ->scalarPrototype()->end()
                            ->info('Default request headers for crawler requests, e.g. Accept-Encoding: identity for WAFs that 429 the bare "gzip" Symfony HttpClient sends')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $clients = $config['clients'] ?? [];

        if ([] === $clients) {
            $builder->autowire(OmekaClient::class)
                ->setAutoconfigured(true)
                ->setArgument('$apiUrl', '%env(OMEKA_API_URL)%')
                ->setArgument('$keyIdentity', '%env(default::OMEKA_KEY_IDENTITY)%')
                ->setArgument('$keyCredential', '%env(default::OMEKA_KEY_CREDENTIAL)%')
                ->addTag('omeka.client', ['name' => 'default']);
        }

        foreach ($clients as $name => $clientConfig) {
            $builder->register('omeka.'.$name, OmekaClient::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$apiUrl', $clientConfig['api_url'] ?? '%env(OMEKA_API_URL)%')
                ->setArgument('$keyIdentity', $clientConfig['key_identity'] ?? '%env(default::OMEKA_KEY_IDENTITY)%')
                ->setArgument('$keyCredential', $clientConfig['key_credential'] ?? '%env(default::OMEKA_KEY_CREDENTIAL)%')
                ->addTag('omeka.client', ['name' => $name]);
        }

        if ([] !== $clients) {
            $defaultName = $clients['default'] ?? null;
            if (null === $defaultName) {
                $defaultName = \array_key_first($clients);
            }

            if (null !== $defaultName) {
                $builder->setAlias(OmekaClient::class, 'omeka.'.$defaultName)
                    ->setPublic(false);
            }
        }

        $builder->register(OmekaClientRegistry::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$clients', new TaggedIteratorArgument('omeka.client', 'name'));

        foreach ([
            OmekaCrawlCommand::class,
            OmekaDirectoryCommand::class,
            OmekaCreateItemCommand::class,
            OmekaCreateResourcesCommand::class,
            OmekaCreateSiteCommand::class,
            OmekaCustomVocabTermsCommand::class,
            OmekaListItemsCommand::class,
            OmekaListPropertiesCommand::class,
            OmekaListResourceTemplatesCommand::class,
            OmekaListVocabulariesCommand::class,
            OmekaSyncCommand::class,
        ] as $commandClass) {
            $builder->autowire($commandClass)
                ->setAutoconfigured(true)
                ->addTag('console.command');
        }

        // ── HTTP cache for public crawling ────────────────────────────────────
        // A FilesystemTagAwareAdapter wraps the default HttpClient in a
        // CachingHttpClient. All requests made by OmekaPublicCrawler and
        // OmekaDirectoryParser go through this cache.
        //
        // Apps that want a different backend (Redis, etc.) can override the
        // 'omeka.http_cache' service or the 'omeka.http_client' service.
        $cacheConfig = $config['crawler_cache'];

        $builder->register('omeka.http_cache', FilesystemTagAwareAdapter::class)
            ->setArguments([
                'omeka_http',                      // namespace
                $cacheConfig['default_ttl'],       // default TTL
                $cacheConfig['directory'],         // cache directory
            ])
            ->setPublic(false);

        // Retries sit under the cache so only real network misses are retried.
        $politeness = $config['crawler_politeness'];
        $builder->register('omeka.http_client.base', HttpClientInterface::class)
            ->setFactory([new Reference('http_client'), 'withOptions'])
            ->setArguments([['headers' => $politeness['headers']]])
            ->setPublic(false);

        $builder->register('omeka.http_client.retrying', RetryableHttpClient::class)
            ->setArguments([
                new Reference('omeka.http_client.base'),
                (new Definition(GenericRetryStrategy::class))->setArguments([
                    GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES, // includes 429; Retry-After is honored
                    $politeness['retry_delay_ms'],
                    2.0,
                    60000,
                    0.2,
                ]),
                $politeness['max_retries'],
            ])
            ->setPublic(false);

        $builder->register('omeka.http_client', CachingHttpClient::class)
            ->setArguments([
                new Reference('omeka.http_client.retrying'), // decorated base client
                new Reference('omeka.http_cache'),  // PSR-6/TagAware cache pool
                [],                                 // defaultOptions (none needed)
                true,                               // sharedCache
                $cacheConfig['default_ttl'],        // maxTtl
            ])
            ->setPublic(false);

        // Public crawler — no API key needed, works against any Omeka-S site
        $builder->autowire(OmekaPublicCrawler::class)
            ->setAutoconfigured(true)
            ->setArgument('$httpClient', new Reference('omeka.http_client'))
            ->setArgument('$minRequestIntervalMs', $politeness['min_interval_ms']);

        // Directory parser — scrapes omeka.org HTML directory pages
        $builder->autowire(OmekaDirectoryParser::class)
            ->setAutoconfigured(true)
            ->setArgument('$httpClient', new Reference('omeka.http_client'));

        // Messenger handler — autoconfiguration picks up #[AsMessageHandler]
        $builder->autowire(OmekaCrawlMessageHandler::class)
            ->setAutoconfigured(true);
    }
}
