<?php

namespace Oro\Bundle\EntityExtendBundle\Tests\Unit\Grid;

use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Tests\Unit\Datagrid\DatagridGuesserMock;
use Oro\Bundle\EntityBundle\EntityConfig\DatagridScope;
use Oro\Bundle\EntityConfigBundle\Config\Config;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;
use Oro\Bundle\EntityExtendBundle\Grid\DynamicFieldsExtension;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;

class DynamicFieldsExtensionTest extends \PHPUnit_Framework_TestCase
{
    const ENTITY_CLASS = 'Test\Entity';
    const ENTITY_NAME  = 'Test:Entity';
    const FIELD_NAME   = 'testField';

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $configManager;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $entityClassResolver;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $entityConfigProvider;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $extendConfigProvider;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $datagridConfigProvider;

    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $viewConfigProvider;

    /** @var DynamicFieldsExtension */
    protected $extension;

    protected function setUp()
    {
        $this->configManager       = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Config\ConfigManager')
            ->disableOriginalConstructor()
            ->getMock();
        $this->entityClassResolver = $this->getMockBuilder('Oro\Bundle\EntityBundle\ORM\EntityClassResolver')
            ->disableOriginalConstructor()
            ->getMock();

        $this->entityConfigProvider   = $this->getConfigProviderMock();
        $this->extendConfigProvider   = $this->getConfigProviderMock();
        $this->datagridConfigProvider = $this->getConfigProviderMock();
        $this->viewConfigProvider     = $this->getConfigProviderMock();

        $this->configManager->expects($this->any())
            ->method('getProvider')
            ->will(
                $this->returnValueMap(
                    [
                        ['entity', $this->entityConfigProvider],
                        ['extend', $this->extendConfigProvider],
                        ['datagrid', $this->datagridConfigProvider],
                        ['view', $this->viewConfigProvider],
                    ]
                )
            );

        $this->extension = new DynamicFieldsExtension(
            $this->configManager,
            $this->entityClassResolver,
            new DatagridGuesserMock()
        );
    }

    public function testIsApplicable()
    {
        $this->assertFalse(
            $this->extension->isApplicable(
                DatagridConfiguration::create(
                    [
                        'source' => [
                            'type' => 'orm'
                        ]
                    ]
                )
            )
        );
        $this->assertTrue(
            $this->extension->isApplicable(
                DatagridConfiguration::create(
                    [
                        'extended_entity_name' => 'entity',
                        'source'               => [
                            'type' => 'orm'
                        ]
                    ]
                )
            )
        );
        $this->assertFalse(
            $this->extension->isApplicable(
                DatagridConfiguration::create(
                    [
                        'extended_entity_name' => 'entity'
                    ]
                )
            )
        );
    }

    public function testGetPriority()
    {
        $this->assertEquals(
            300,
            $this->extension->getPriority()
        );
    }

    public function testProcessConfigs()
    {
        $fieldLabel = 'test.field.label';
        $fieldType  = 'string';

        $this->entityClassResolver->expects($this->once())
            ->method('getEntityClass')
            ->with(self::ENTITY_NAME)
            ->will($this->returnValue(self::ENTITY_CLASS));
        $this->setExpectationForGetFields(self::ENTITY_CLASS, self::FIELD_NAME, $fieldType);

        $entityFieldConfig = new Config(new FieldConfigId('entity', self::ENTITY_CLASS, self::FIELD_NAME, $fieldType));
        $entityFieldConfig->set('label', $fieldLabel);
        $this->entityConfigProvider->expects($this->once())
            ->method('getConfig')
            ->with(self::ENTITY_CLASS, self::FIELD_NAME)
            ->will($this->returnValue($entityFieldConfig));

        $datagridFieldConfig = new Config(
            new FieldConfigId('datagrid', self::ENTITY_CLASS, self::FIELD_NAME, $fieldType)
        );
        $this->datagridConfigProvider->expects($this->any())
            ->method('getConfig')
            ->with(self::ENTITY_CLASS, self::FIELD_NAME)
            ->will($this->returnValue($datagridFieldConfig));

        $viewFieldConfig = new Config(
            new FieldConfigId('view', self::ENTITY_CLASS, self::FIELD_NAME, $fieldType)
        );
        $this->viewConfigProvider->expects($this->any())
            ->method('getConfig')
            ->with(self::ENTITY_CLASS, self::FIELD_NAME)
            ->will($this->returnValue($viewFieldConfig));

        $config = $this->getDatagridConfiguration();
        $initialConfig = $config->toArray();
        $this->extension->processConfigs($config);
        $this->assertEquals(
            array_merge(
                $initialConfig,
                [
                    'columns'              => [
                        self::FIELD_NAME => [
                            'label'         => $fieldLabel,
                            'frontend_type' => 'string',
                            'renderable'    => false,
                            'required'      => false
                        ]
                    ],
                    'sorters'              => [
                        'columns' => [
                            self::FIELD_NAME => [
                                'data_name' => self::FIELD_NAME
                            ]
                        ]
                    ],
                    'filters'              => [
                        'columns' => [
                            self::FIELD_NAME => [
                                'type'      => 'string',
                                'data_name' => self::FIELD_NAME,
                                'enabled'   => false
                            ]
                        ]
                    ],
                ]
            ),
            $config->toArray()
        );
    }

    public function testProcessConfigsWithVisibleFilter()
    {
        $fieldLabel = 'test.field.label';
        $fieldType  = 'string';

        $this->entityClassResolver->expects($this->once())
            ->method('getEntityClass')
            ->with(self::ENTITY_NAME)
            ->will($this->returnValue(self::ENTITY_CLASS));
        $this->setExpectationForGetFields(self::ENTITY_CLASS, self::FIELD_NAME, $fieldType);

        $entityFieldConfig = new Config(new FieldConfigId('entity', self::ENTITY_CLASS, self::FIELD_NAME, $fieldType));
        $entityFieldConfig->set('label', $fieldLabel);
        $this->entityConfigProvider->expects($this->once())
            ->method('getConfig')
            ->with(self::ENTITY_CLASS, self::FIELD_NAME)
            ->will($this->returnValue($entityFieldConfig));

        $datagridFieldConfig = new Config(
            new FieldConfigId('datagrid', self::ENTITY_CLASS, self::FIELD_NAME, $fieldType)
        );
        $datagridFieldConfig->set('show_filter', true);
        $this->datagridConfigProvider->expects($this->any())
            ->method('getConfig')
            ->with(self::ENTITY_CLASS, self::FIELD_NAME)
            ->will($this->returnValue($datagridFieldConfig));

        $viewFieldConfig = new Config(
            new FieldConfigId('view', self::ENTITY_CLASS, self::FIELD_NAME, $fieldType)
        );
        $this->viewConfigProvider->expects($this->any())
            ->method('getConfig')
            ->with(self::ENTITY_CLASS, self::FIELD_NAME)
            ->will($this->returnValue($viewFieldConfig));

        $config = $this->getDatagridConfiguration();
        $initialConfig = $config->toArray();
        $this->extension->processConfigs($config);
        $this->assertEquals(
            array_merge(
                $initialConfig,
                [
                    'columns'              => [
                        self::FIELD_NAME => [
                            'label'         => $fieldLabel,
                            'frontend_type' => 'string',
                            'renderable'    => false,
                            'required'      => false
                        ]
                    ],
                    'sorters'              => [
                        'columns' => [
                            self::FIELD_NAME => [
                                'data_name' => self::FIELD_NAME
                            ]
                        ]
                    ],
                    'filters'              => [
                        'columns' => [
                            self::FIELD_NAME => [
                                'type'      => 'string',
                                'data_name' => self::FIELD_NAME,
                                'enabled'   => true
                            ]
                        ]
                    ],
                ]
            ),
            $config->toArray()
        );
    }

    public function testVisitDatasourceNoFields()
    {
        $this->entityClassResolver->expects($this->once())
            ->method('getEntityClass')
            ->with(self::ENTITY_NAME)
            ->will($this->returnValue(self::ENTITY_CLASS));

        $qb = $this->getMockBuilder('Doctrine\ORM\QueryBuilder')
            ->disableOriginalConstructor()
            ->getMock();
        $qb->expects($this->never())
            ->method('getDQLPart');

        $datasource = $this->getMockBuilder('Oro\\Bundle\\DataGridBundle\\Datasource\\Orm\\OrmDatasource')
            ->disableOriginalConstructor()
            ->getMock();
        $datasource->expects($this->never())->method('getQueryBuilder');

        $config = $this->getDatagridConfiguration();
        $this->extension->visitDatasource($config, $datasource);
    }

    public function testVisitDatasourceForNotConfigurableEntity()
    {
        $this->entityClassResolver->expects($this->once())
            ->method('getEntityClass')
            ->with(self::ENTITY_NAME)
            ->will($this->returnValue(self::ENTITY_CLASS));

        $this->configManager->expects($this->once())
            ->method('hasConfig')
            ->with(self::ENTITY_CLASS)
            ->will($this->returnValue(false));

        $qb = $this->getMockBuilder('Doctrine\ORM\QueryBuilder')
            ->disableOriginalConstructor()
            ->getMock();
        $qb->expects($this->never())
            ->method('getDQLPart');

        $datasource = $this->getMockBuilder('Oro\\Bundle\\DataGridBundle\\Datasource\\Orm\\OrmDatasource')
            ->disableOriginalConstructor()
            ->getMock();
        $datasource->expects($this->never())->method('getQueryBuilder');

        $config = $this->getDatagridConfiguration();
        $this->extension->visitDatasource($config, $datasource);
    }

    public function testVisitDatasource()
    {
        $fieldType  = 'string';
        $alias      = 'c';

        $this->entityClassResolver->expects($this->any())
            ->method('getEntityClass')
            ->with(self::ENTITY_NAME)
            ->will($this->returnValue(self::ENTITY_CLASS));
        $this->setExpectationForGetFields(self::ENTITY_CLASS, self::FIELD_NAME, $fieldType);

        $from = $this->getMockBuilder('Doctrine\ORM\Query\Expr\From')
            ->disableOriginalConstructor()
            ->getMock();
        $from->expects($this->once())
            ->method('getAlias')
            ->will($this->returnValue($alias));
        $from->expects($this->once())
            ->method('getFrom')
            ->will($this->returnValue(self::ENTITY_NAME));

        $qb = $this->getMockBuilder('Doctrine\ORM\QueryBuilder')
            ->disableOriginalConstructor()
            ->getMock();
        $qb->expects($this->once())
            ->method('getDQLPart')
            ->with('from')
            ->will($this->returnValue([$from]));
        $qb->expects($this->once())
            ->method('addSelect')
            ->with($alias . '.' . self::FIELD_NAME)
            ->will($this->returnSelf());

        $datasource = $this->getMockBuilder('Oro\\Bundle\\DataGridBundle\\Datasource\\Orm\\OrmDatasource')
            ->disableOriginalConstructor()
            ->getMock();
        $datasource->expects($this->once())
            ->method('getQueryBuilder')
            ->will($this->returnValue($qb));

        $viewFieldConfig = new Config(
            new FieldConfigId('view', self::ENTITY_CLASS, self::FIELD_NAME, $fieldType)
        );
        $this->viewConfigProvider->expects($this->any())
            ->method('getConfig')
            ->with(self::ENTITY_CLASS, self::FIELD_NAME)
            ->will($this->returnValue($viewFieldConfig));

        $config = $this->getDatagridConfiguration();
        $initialConfig = $config->toArray();
        $this->extension->visitDatasource($config, $datasource);
        $this->assertEquals(
            array_merge(
                $initialConfig,
                [
                    'columns'              => [
                        self::FIELD_NAME => [
                            'data_name' => self::FIELD_NAME
                        ]
                    ],
                    'sorters'              => [
                        'columns' => [
                            self::FIELD_NAME => [
                                'data_name' => $alias . '.' . self::FIELD_NAME
                            ]
                        ]
                    ],
                    'filters'              => [
                        'columns' => [
                            self::FIELD_NAME => [
                                'data_name' => $alias . '.' . self::FIELD_NAME
                            ]
                        ]
                    ],
                ]
            ),
            $config->toArray()
        );
    }

    public function testVisitDatasourceForEnum()
    {
        $fieldType  = 'enum';
        $alias      = 'c';

        $targetFieldName = 'testRel';
        $relAlias        = 'auto_rel_1';

        $this->entityClassResolver->expects($this->any())
            ->method('getEntityClass')
            ->with(self::ENTITY_NAME)
            ->will($this->returnValue(self::ENTITY_CLASS));
        $this->setExpectationForGetFields(self::ENTITY_CLASS, self::FIELD_NAME, $fieldType);

        $extendFieldConfig = new Config(new FieldConfigId('extend', self::ENTITY_CLASS, self::FIELD_NAME, $fieldType));
        $extendFieldConfig->set('target_field', $targetFieldName);
        $this->extendConfigProvider->expects($this->once())
            ->method('getConfig')
            ->with(self::ENTITY_CLASS, self::FIELD_NAME)
            ->will($this->returnValue($extendFieldConfig));

        $viewFieldConfig = new Config(
            new FieldConfigId('view', self::ENTITY_CLASS, self::FIELD_NAME, $fieldType)
        );
        $this->viewConfigProvider->expects($this->any())
            ->method('getConfig')
            ->with(self::ENTITY_CLASS, self::FIELD_NAME)
            ->will($this->returnValue($viewFieldConfig));

        $from = $this->getMockBuilder('Doctrine\ORM\Query\Expr\From')
            ->disableOriginalConstructor()
            ->getMock();
        $from->expects($this->once())
            ->method('getAlias')
            ->will($this->returnValue($alias));
        $from->expects($this->once())
            ->method('getFrom')
            ->will($this->returnValue(self::ENTITY_NAME));

        $qb = $this->getMockBuilder('Doctrine\ORM\QueryBuilder')
            ->disableOriginalConstructor()
            ->getMock();
        $qb->expects($this->once())
            ->method('getDQLPart')
            ->with('from')
            ->will($this->returnValue([$from]));
        $qb->expects($this->once())
            ->method('leftJoin')
            ->with($alias . '.' . self::FIELD_NAME, $relAlias)
            ->will($this->returnSelf());
        $qb->expects($this->once())
            ->method('addSelect')
            ->with(sprintf('IDENTITY(%s.%s) as %s', $alias, self::FIELD_NAME, self::FIELD_NAME))
            ->will($this->returnSelf());

        $datasource = $this->getMockBuilder('Oro\\Bundle\\DataGridBundle\\Datasource\\Orm\\OrmDatasource')
            ->disableOriginalConstructor()
            ->getMock();
        $datasource->expects($this->once())
            ->method('getQueryBuilder')
            ->will($this->returnValue($qb));

        $config = $this->getDatagridConfiguration();
        $initialConfig = $config->toArray();
        $this->extension->visitDatasource($config, $datasource);
        $this->assertEquals(
            array_merge(
                $initialConfig,
                [
                    'columns'              => [
                        self::FIELD_NAME => [
                            'data_name' => self::FIELD_NAME
                        ]
                    ],
                    'sorters'              => [
                        'columns' => [
                            self::FIELD_NAME => [
                                'data_name' => $relAlias . '.' . $targetFieldName
                            ]
                        ]
                    ],
                    'filters'              => [
                        'columns' => [
                            self::FIELD_NAME => [
                                'data_name' => $alias . '.' . self::FIELD_NAME
                            ]
                        ]
                    ],
                ]
            ),
            $config->toArray()
        );
    }

    public function testVisitDatasourceForMultiEnum()
    {
        $fieldType  = 'multiEnum';
        $alias      = 'c';

        $snapshotFieldName = ExtendHelper::getMultiEnumSnapshotFieldName(self::FIELD_NAME);

        $this->entityClassResolver->expects($this->any())
            ->method('getEntityClass')
            ->with(self::ENTITY_NAME)
            ->will($this->returnValue(self::ENTITY_CLASS));
        $this->setExpectationForGetFields(self::ENTITY_CLASS, self::FIELD_NAME, $fieldType);

        $viewFieldConfig = new Config(
            new FieldConfigId('view', self::ENTITY_CLASS, self::FIELD_NAME, $fieldType)
        );
        $this->viewConfigProvider->expects($this->any())
            ->method('getConfig')
            ->with(self::ENTITY_CLASS, self::FIELD_NAME)
            ->will($this->returnValue($viewFieldConfig));

        $from = $this->getMockBuilder('Doctrine\ORM\Query\Expr\From')
            ->disableOriginalConstructor()
            ->getMock();
        $from->expects($this->once())
            ->method('getAlias')
            ->will($this->returnValue($alias));
        $from->expects($this->once())
            ->method('getFrom')
            ->will($this->returnValue(self::ENTITY_NAME));

        $qb = $this->getMockBuilder('Doctrine\ORM\QueryBuilder')
            ->disableOriginalConstructor()
            ->getMock();
        $qb->expects($this->once())
            ->method('getDQLPart')
            ->with('from')
            ->will($this->returnValue([$from]));
        $qb->expects($this->once())
            ->method('addSelect')
            ->with($alias . '.' . $snapshotFieldName)
            ->will($this->returnSelf());

        $datasource = $this->getMockBuilder('Oro\\Bundle\\DataGridBundle\\Datasource\\Orm\\OrmDatasource')
            ->disableOriginalConstructor()
            ->getMock();
        $datasource->expects($this->once())
            ->method('getQueryBuilder')
            ->will($this->returnValue($qb));

        $config = $this->getDatagridConfiguration();
        $initialConfig = $config->toArray();
        $this->extension->visitDatasource($config, $datasource);
        $this->assertEquals(
            array_merge(
                $initialConfig,
                [
                    'columns'              => [
                        self::FIELD_NAME => [
                            'data_name' => $snapshotFieldName
                        ]
                    ],
                    'sorters'              => [
                        'columns' => [
                            self::FIELD_NAME => [
                                'data_name' => $alias . '.' . $snapshotFieldName
                            ]
                        ]
                    ],
                    'filters'              => [
                        'columns' => [
                            self::FIELD_NAME => [
                                'data_name' => $alias . '.' . self::FIELD_NAME
                            ]
                        ]
                    ],
                ]
            ),
            $config->toArray()
        );
    }

    protected function getDatagridConfiguration()
    {
        return DatagridConfiguration::create(['extended_entity_name' => self::ENTITY_NAME]);
    }

    protected function setExpectationForGetFields($className, $fieldName, $fieldType)
    {
        $fieldId = new FieldConfigId('entity', $className, $fieldName, $fieldType);

        $extendConfig = new Config(new FieldConfigId('extend', $className, $fieldName, $fieldType));
        $extendConfig->set('owner', ExtendScope::OWNER_CUSTOM);
        $extendConfig->set('state', ExtendScope::STATE_ACTIVE);
        $extendConfig->set('is_deleted', false);

        $datagridConfig = new Config(new FieldConfigId('datagrid', $className, $fieldName, $fieldType));
        $datagridConfig->set('is_visible', DatagridScope::IS_VISIBLE_TRUE);

        $this->configManager->expects($this->once())
            ->method('hasConfig')
            ->with($className)
            ->will($this->returnValue(true));

        $this->entityConfigProvider->expects($this->once())
            ->method('getIds')
            ->with($className)
            ->will($this->returnValue([$fieldId]));
        $this->extendConfigProvider->expects($this->once())
            ->method('getConfigById')
            ->with($this->identicalTo($fieldId))
            ->will($this->returnValue($extendConfig));
        $this->datagridConfigProvider->expects($this->once())
            ->method('getConfigById')
            ->with($this->identicalTo($fieldId))
            ->will($this->returnValue($datagridConfig));
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function getEventDispatcherMock()
    {
        return $this->getMockBuilder('Symfony\Component\EventDispatcher\EventDispatcherInterface')
            ->disableOriginalConstructor()
            ->getMock();
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function getConfigProviderMock()
    {
        return $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider')
            ->disableOriginalConstructor()
            ->getMock();
    }
}
