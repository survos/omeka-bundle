<?php

declare(strict_types=1);

namespace Survos\OmekaBundle;

use Survos\OmekaBundle\Client\OmekaClient;
use Survos\OmekaBundle\Client\OmekaClientRegistry;
use Survos\OmekaBundle\Command\OmekaCreateSiteCommand;
use Survos\OmekaBundle\Command\OmekaCreateItemCommand;
use Survos\OmekaBundle\Command\OmekaCustomVocabTermsCommand;
use Survos\OmekaBundle\Command\OmekaListItemsCommand;
use Survos\OmekaBundle\Command\OmekaListPropertiesCommand;
use Survos\OmekaBundle\Command\OmekaListResourceTemplatesCommand;
use Survos\OmekaBundle\Command\OmekaListVocabulariesCommand;
use Survos\OmekaBundle\Command\OmekaSyncCommand;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function array_key_first;

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
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $clients = $config['clients'] ?? [];

        if ($clients === []) {
            $builder->autowire(OmekaClient::class)
                ->setAutoconfigured(true)
                ->addTag('omeka.client', ['name' => 'default']);
        }

        foreach ($clients as $name => $clientConfig) {
            $builder->register('omeka.' . $name, OmekaClient::class)
                ->setAutowired(true)
                ->setAutoconfigured(true)
                ->setArgument('$apiUrl', $clientConfig['api_url'] ?? '%env(OMEKA_API_URL)%')
                ->setArgument('$keyIdentity', $clientConfig['key_identity'] ?? '%env(default::OMEKA_KEY_IDENTITY)%')
                ->setArgument('$keyCredential', $clientConfig['key_credential'] ?? '%env(default::OMEKA_KEY_CREDENTIAL)%')
                ->addTag('omeka.client', ['name' => $name]);
        }

        if ($clients !== []) {
            $defaultName = $clients['default'] ?? null;
            if ($defaultName === null) {
                $defaultName = array_key_first($clients);
            }

            if ($defaultName !== null) {
                $builder->setAlias(OmekaClient::class, 'omeka.' . $defaultName)
                    ->setPublic(false);
            }
        }

        $builder->register(OmekaClientRegistry::class)
            ->setAutowired(true)
            ->setAutoconfigured(true)
            ->setArgument('$clients', new TaggedIteratorArgument('omeka.client', 'name'));

        foreach ([
            OmekaCreateItemCommand::class,
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
    }
}
