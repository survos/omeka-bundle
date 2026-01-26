<?php

declare(strict_types=1);

namespace Survos\OmekaBundle;

use Survos\OmekaBundle\Client\OmekaClient;
use Survos\OmekaBundle\Command\OmekaCreateItemCommand;
use Survos\OmekaBundle\Command\OmekaCustomVocabTermsCommand;
use Survos\OmekaBundle\Command\OmekaListItemsCommand;
use Survos\OmekaBundle\Command\OmekaListPropertiesCommand;
use Survos\OmekaBundle\Command\OmekaListResourceTemplatesCommand;
use Survos\OmekaBundle\Command\OmekaListVocabulariesCommand;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosOmekaBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure();

        $services->set(OmekaClient::class);
        $services->set(OmekaCreateItemCommand::class);
        $services->set(OmekaCustomVocabTermsCommand::class);
        $services->set(OmekaListItemsCommand::class);
        $services->set(OmekaListPropertiesCommand::class);
        $services->set(OmekaListResourceTemplatesCommand::class);
        $services->set(OmekaListVocabulariesCommand::class);
    }
}
