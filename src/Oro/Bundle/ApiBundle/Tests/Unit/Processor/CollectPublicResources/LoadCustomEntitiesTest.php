<?php

namespace Oro\Bundle\ApiBundle\Tests\Unit\Processor\CollectPublicResources;

use Oro\Bundle\ApiBundle\Processor\CollectPublicResources\CollectPublicResourcesContext;
use Oro\Bundle\ApiBundle\Processor\CollectPublicResources\LoadCustomEntities;
use Oro\Bundle\ApiBundle\Request\PublicResource;
use Oro\Bundle\EntityConfigBundle\Config\Config;
use Oro\Bundle\EntityConfigBundle\Config\Id\EntityConfigId;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;

class LoadCustomEntitiesTest extends \PHPUnit_Framework_TestCase
{
    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $configManager;

    /** @var LoadCustomEntities */
    protected $processor;

    protected function setUp()
    {
        $this->configManager = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Config\ConfigManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->processor = new LoadCustomEntities($this->configManager);
    }

    public function testProcess()
    {
        $context = new CollectPublicResourcesContext();

        $this->configManager->expects($this->once())
            ->method('getConfigs')
            ->with('extend', null, true)
            ->willReturn(
                [
                    $this->getEntityConfig('Test\Entity1', ['is_extend' => true, 'owner' => ExtendScope::OWNER_CUSTOM]),
                    $this->getEntityConfig('Test\Entity2', ['is_extend' => true, 'owner' => ExtendScope::OWNER_SYSTEM]),
                    $this->getEntityConfig('Test\Entity3'),
                ]
            );

        $this->processor->process($context);

        $this->assertEquals(
            [
                new PublicResource('Test\Entity1'),
            ],
            $context->getResult()->toArray()
        );
    }

    /**
     * @param string $className
     * @param array  $values
     *
     * @return Config
     */
    protected function getEntityConfig($className, $values = [])
    {
        $configId = new EntityConfigId('extend', $className);
        $config   = new Config($configId);
        $config->setValues($values);

        return $config;
    }
}
