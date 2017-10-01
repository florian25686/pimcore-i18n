<?php

namespace I18nBundle\DependencyInjection\Compiler;

use I18nBundle\Registry\LanguageRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class LanguageAdapterPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        foreach ($container->findTaggedServiceIds('i18n.adapter.language', TRUE) as $id => $tags) {
            $definition = $container->getDefinition(LanguageRegistry::class);
            foreach ($tags as $attributes) {
                $definition->addMethodCall('register', [new Reference($id), $attributes['alias']]);
            }
        }
    }
}