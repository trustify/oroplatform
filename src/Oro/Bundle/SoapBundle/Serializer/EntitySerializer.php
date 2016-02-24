<?php

namespace Oro\Bundle\SoapBundle\Serializer;

use Symfony\Component\Security\Acl\Voter\FieldVote;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;

use Oro\Bundle\EntityBundle\ORM\QueryHintResolver;
use Oro\Bundle\EntityConfigBundle\Config\ConfigManager;
use Oro\Bundle\EntityConfigBundle\Config\ConfigModelManager;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;
use Oro\Bundle\EntityConfigBundle\Exception\RuntimeException;

/**
 * @todo: This is draft implementation of the entity serializer.
 *       It is expected that the full implementation will be done when new API component is implemented.
 * What need to do:
 *  * by default the value of identifier field should be used
 *    for related entities (now it should be configured manually in serialization rules)
 *  * add support for extended fields
 *
 * Example of serialization rules used in the $config parameter of
 * {@see serialize}, {@see serializeEntities} and {@see prepareQuery} methods:
 *
 *  [
 *      // exclude the 'email' field
 *      'excluded_fields' => ['email'],
 *      'fields' => [
 *          // serialize the 'status' many-to-one relation using the value of the 'name' field
 *          'status'       => ['fields' => 'name'],
 *          // order the 'phones' many-to-many relation by the 'primary' field and
 *          // serialize each phone as a pair of 'phone' and 'primary' field
 *          'phones'       => [
 *              'exclusion_policy' => 'all',
 *              'fields'           => [
 *                  'phone'   => null,
 *                  'primary' => [
 *                      // as example we can convert boolean to Yes/No string
 *                      // the data transformer must implement either
 *                      // Symfony\Component\Form\DataTransformerInterface
 *                      // or Oro\Bundle\SoapBundle\Serializer\DataTransformerInterface
 *                      // Also several data transformers can be specified, for example
 *                      // 'data_transformer' => ['first_transformer_service_id', 'second_transformer_service_id'],
 *                      'data_transformer' => 'boolean_to_string_transformer_service_id',
 *                      // the "primary" field should be named as "isPrimary" in the result
 *                      'result_name' => 'isPrimary'
 *                  ]
 *              ],
 *              'orderBy'          => [
 *                  'primary' => 'DESC'
 *              ]
 *          ],
 *          'addresses'    => [
 *              'excluded_fields' => ['owner'],
 *              'fields'          => [
 *                  'country' => ['fields' => 'name'],
 *                  'types'   => [
 *                      'fields' => 'name',
 *                      'orderBy' => [
 *                          'name' => 'ASC'
 *                      ]
 *                  ]
 *              ]
 *          ]
 *      ]
 *  ]
 *
 * Example of the serialization result by this config (it is supposed that the serializing entity has
 * the following fields:
 *  id
 *  name
 *  email
 *  status -> many-to-one
 *      name
 *      label
 *  phones -> many-to-many
 *      id
 *      phone
 *      primary
 *  addresses -> many-to-many
 *      id
 *      owner -> many-to-one
 *      country -> many-to-one
 *          code,
 *          name
 *      types -> many-to-many
 *          name
 *          label
 *  [
 *      'id'        => 123,
 *      'name'      => 'John Smith',
 *      'status'    => 'active',
 *      'phones'    => [
 *          ['phone' => '123-123', 'primary' => true],
 *          ['phone' => '456-456', 'primary' => false]
 *      ],
 *      'addresses' => [
 *          ['country' => 'USA', 'types' => ['billing', 'shipping']]
 *      ]
 *  ]
 *
 * Special attributes:
 * * 'disable_partial_load' - Disables using of Doctrine partial object.
 *                            It can be helpful for entities with SINGLE_TABLE inheritance mapping
 * * 'hints'                - The list of Doctrine query hints. Each item can be a string or name/value pair.
 *                            Example:
 *                            'hints' => [
 *                                  'HINT_TRANSLATABLE',
 *                                  ['name' => 'HINT_CUSTOM_OUTPUT_WALKER', 'value' => 'Acme\AST_Walker_Class']
 *                            ]
 *
 * Metadata fields:
 * * '__discriminator__' - The discriminator value an entity.
 * * '__class__'         - FQCN of an entity.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class EntitySerializer
{
    /**
     * A field which can be used to get the discriminator value an entity.
     * For example:
     *  'fields' => [
     *      '__discriminator__' => ['result_name' => 'type']
     *  ]
     */
    const DISCRIMINATOR_FIELD = '__discriminator__';

    /**
     * A field which can be used to get FQCN of an entity.
     * For example:
     *  'fields' => [
     *      '__class__' => ['result_name' => 'entity']
     *  ]
     */
    const CLASS_FIELD = '__class__';

    /** @var DoctrineHelper */
    protected $doctrineHelper;

    /** @var ConfigManager */
    protected $configManager;

    /** @var DataAccessorInterface */
    protected $dataAccessor;

    /** @var DataTransformerInterface */
    protected $dataTransformer;

    /** @var AuthorizationCheckerInterface Optional authentication checker */
    protected $authChecker;

    /** @var bool */
    protected $isFieldACLEnabled = false;

    /** @var bool */
    protected $showRestrictedFields = false;

    /**
     * @param ManagerRegistry          $doctrine
     * @param ConfigManager            $configManager
     * @param DataAccessorInterface    $dataAccessor
     * @param DataTransformerInterface $dataTransformer
     * @param QueryHintResolver        $queryHintResolver
     */
    public function __construct(
        ManagerRegistry $doctrine,
        ConfigManager $configManager,
        DataAccessorInterface $dataAccessor,
        DataTransformerInterface $dataTransformer,
        QueryHintResolver $queryHintResolver
    ) {
        $this->doctrineHelper    = new DoctrineHelper($doctrine);
        $this->configManager     = $configManager;
        $this->dataAccessor      = $dataAccessor;
        $this->dataTransformer   = $dataTransformer;
        $this->queryHintResolver = $queryHintResolver;
    }

    /**
     * Inject authentication check to check field ACL
     * if not injected - all fields are allowed
     *
     * @param AuthorizationCheckerInterface $authChecker
     */
    public function setAuthenticationChecker(AuthorizationCheckerInterface $authChecker)
    {
        $this->authChecker = $authChecker;
    }

    /**
     * @param QueryBuilder $qb     A query builder is used to get data
     * @param array        $config Serialization rules
     *
     * @return array
     */
    public function serialize(QueryBuilder $qb, $config)
    {
        $this->prepareQuery($qb, $config);
        $data = $this->getQuery($qb, $config)->getResult();

        return $this->serializeEntities((array)$data, $this->doctrineHelper->getRootEntityClass($qb), $config);
    }

    /**
     * @param object[] $entities    The list of entities to be serialized
     * @param string   $entityClass The entity class name
     * @param array    $config      Serialization rules
     * @param boolean  $useIdAsKey  Defines whether the entity id should be used as a key of the result array
     *
     * @return array
     */
    public function serializeEntities(array $entities, $entityClass, $config, $useIdAsKey = false)
    {
        if (empty($entities)) {
            return [];
        }

        try {
            $securityConfig  = $this->configManager->getProvider('security')->getConfig($entityClass);

            $this->isFieldACLEnabled = $securityConfig->get('field_acl_enabled');
            $this->showRestrictedFields = $securityConfig->get('show_restricted_fields');
        } catch (RuntimeException $e) {
            $this->isFieldACLEnabled = false;
            $this->showRestrictedFields = true;
        }

        $result = [];
        if ($useIdAsKey) {
            $idFieldName = $this->getEntityIdFieldName($entityClass);
            foreach ($entities as $entity) {
                // if AuthenticationChecker injected, check if entity allowed to be viewed
                if ($this->authChecker && !$this->authChecker->isGranted('VIEW', $entity)) {
                    continue;
                }

                $id          = $this->dataAccessor->getValue($entity, $idFieldName);
                $result[$id] = $this->serializeItem($entity, $entityClass, $config);
            }
        } else {
            foreach ($entities as $entity) {
                // if AuthenticationChecker injected, check if entity allowed to be viewed
                if ($this->authChecker && !$this->authChecker->isGranted('VIEW', $entity)) {
                    continue;
                }

                $result[] = $this->serializeItem($entity, $entityClass, $config);
            }
        }

        $entityIds = $this->getEntityIds($entities, $entityClass);
        $relatedData = $this->loadRelatedData($entityClass, $entityIds, $config);
        if (!empty($relatedData)) {
            $this->applyRelatedData($result, $relatedData, $config, $entityIds);
        }

        if (isset($config['post_serialize'])) {
            $postSerialize = $config['post_serialize'];
            foreach ($result as &$resultItem) {
                $postSerialize($resultItem);
            }
        }

        return $result;
    }

    /**
     * @param QueryBuilder $qb
     * @param array        $config
     */
    public function prepareQuery(QueryBuilder $qb, $config)
    {
        $rootAlias      = $this->doctrineHelper->getRootAlias($qb);
        $entityClass    = $this->doctrineHelper->getRootEntityClass($qb);
        $entityMetadata = $this->doctrineHelper->getEntityMetadata($entityClass);

        $qb->resetDQLPart('select');
        $this->updateSelectQueryPart($qb, $rootAlias, $entityClass, $config);

        $aliasCounter = 0;
        $fields       = $this->getFields($entityClass, $config);
        foreach ($fields as $field) {
            if (!$entityMetadata->isAssociation($field) || $entityMetadata->isCollectionValuedAssociation($field)) {
                continue;
            }

            $alias = 'a' . ++$aliasCounter;
            $qb->leftJoin(sprintf('%s.%s', $rootAlias, $field), $alias);
            $this->updateSelectQueryPart(
                $qb,
                $alias,
                $entityMetadata->getAssociationTargetClass($field),
                $this->getFieldConfig($config, $field),
                true
            );
        }
    }

    /**
     * @param mixed  $entity
     * @param string $entityClass
     * @param array  $config
     *
     * @return array
     */
    protected function serializeItem($entity, $entityClass, $config)
    {
        if (!$entity) {
            return [];
        }

        $result         = [];
        $entityMetadata = $this->doctrineHelper->getEntityMetadata($entityClass);
        $resultFields   = $this->getFieldsToSerialize($entityClass, $config);

        foreach ($resultFields as $field) {
            $isFieldAllowed = $this->isFieldACLEnabled ? $this->isAllowedField($entity, $entityClass, $field) : true;
            if (!$this->showRestrictedFields && !$isFieldAllowed) {
                continue;
            }

            $targetFieldConfig = $this->getFieldConfig($config, $field);

            $value = $isFieldAllowed ?
                $this->serializeItemField($entity, $field, $entityClass, $entityMetadata, $targetFieldConfig) :
                null;

            $resultFieldName = $this->getResultFieldName($field, $targetFieldConfig);
            $result[$resultFieldName] = $value;
        }
        return $result;
    }

    /**
     * @param array|object $entity
     * @param string       $entityClass
     * @param string       $field
     *
     * @return bool
     * @throws \Doctrine\ORM\ORMException
     */
    protected function isAllowedField($entity, $entityClass, $field)
    {
        if (!$this->authChecker) {
            return true;
        }
        $entityToCheck = $entity;
        if (is_array($entityToCheck) && !empty($entityToCheck['entityId'])) {
            $entityToCheck = $this->doctrineHelper->getEntityManager($entityClass)->getReference(
                $entityClass,
                $entity['entityId']
            );
        }
        return $this->authChecker->isGranted('VIEW', new FieldVote($entityToCheck, $field));
    }

    /**
     * @param object         $entity
     * @param string         $field
     * @param string         $entityClass
     * @param EntityMetadata $entityMetadata
     * @param array          $targetConfig
     *
     * @return array|mixed|null
     */
    protected function serializeItemField($entity, $field, $entityClass, EntityMetadata $entityMetadata, $targetConfig)
    {
        $isAssociation = $entityMetadata->isAssociation($field);
        $value         = null;
        if ($this->dataAccessor->tryGetValue($entity, $field, $value)) {
            if ($isAssociation && $value !== null) {
                if (!empty($targetConfig['fields'])) {
                    if (is_string($targetConfig['fields'])) {
                        $value = $this->dataAccessor->getValue($value, $targetConfig['fields']);
                        $value = $this->dataTransformer->transform(
                            $entityClass,
                            $field,
                            $value,
                            $targetConfig
                        );
                    } else {
                        $value = $this->serializeItem(
                            $value,
                            $entityMetadata->getAssociationTargetClass($field),
                            $targetConfig
                        );
                    }
                } else {
                    $value = $this->dataTransformer->transform(
                        $entityClass,
                        $field,
                        $value,
                        $targetConfig
                    );
                }
            } elseif (!$isAssociation) {
                $value = $this->dataTransformer->transform(
                    $entityClass,
                    $field,
                    $value,
                    $targetConfig
                );
            }
        } elseif ($this->isMetadataField($field)) {
            $value = $this->getMetadataFieldValue($entity, $field, $entityMetadata);
        }

        return $value;
    }

    /**
     * @param QueryBuilder $qb
     * @param string       $alias
     * @param string       $entityClass
     * @param array        $config
     * @param boolean      $withAssociations
     */
    public function updateSelectQueryPart(QueryBuilder $qb, $alias, $entityClass, $config, $withAssociations = false)
    {
        if ($this->isPartialAllowed($config)) {
            $entityMetadata = $this->doctrineHelper->getEntityMetadata($entityClass);
            $fields         = array_filter(
                $this->getFields($entityClass, $config),
                function ($field) use ($entityMetadata, $withAssociations) {
                    // skip metadata properties like '__class__' or '__discriminator__'
                    if ($this->isMetadataField($field)) {
                        return false;
                    }

                    return $withAssociations
                        ? !$entityMetadata->isCollectionValuedAssociation($field)
                        : !$entityMetadata->isAssociation($field);
                }
            );
            // make sure identifier fields are added
            foreach ($entityMetadata->getIdentifierFieldNames() as $field) {
                if (!in_array($field, $fields, true)) {
                    $fields[] = $field;
                }
            }
            $qb->addSelect(sprintf('partial %s.{%s}', $alias, implode(',', $fields)));
        } else {
            $qb->addSelect($alias);
        }
    }

    /**
     * @param array $entityIds
     * @param array $mapping
     *
     * @return QueryBuilder
     */
    protected function getToManyAssociationQueryBuilder($entityIds, $mapping)
    {
        $entityIdField = $this->getEntityIdFieldName($mapping['sourceEntity']);

        $qb = $this->doctrineHelper->getEntityRepository($mapping['targetEntity'])
            ->createQueryBuilder('r')
            ->select(sprintf('e.%s as entityId', $entityIdField))
            ->where(sprintf('e.%s IN (:ids)', $entityIdField))
            ->setParameter('ids', $entityIds);
        if ($mapping['mappedBy'] && $mapping['type'] === ClassMetadata::ONE_TO_MANY) {
            $qb->innerJoin($mapping['sourceEntity'], 'e', 'WITH', sprintf('r.%s = e', $mapping['mappedBy']));
        } else {
            $qb->innerJoin($mapping['sourceEntity'], 'e', 'WITH', sprintf('r MEMBER OF e.%s', $mapping['fieldName']));
        }

        return $qb;
    }

    /**
     * @param array $entityIds
     * @param array $mapping
     * @param array $config
     *
     * @return array
     */
    protected function getRelatedItemsBindings($entityIds, $mapping, $config)
    {
        $qb = $this->getToManyAssociationQueryBuilder($entityIds, $mapping)
            ->addSelect(sprintf('r.%s as relatedEntityId', $this->getEntityIdFieldName($mapping['targetEntity'])));

        $rows = $this->getQuery($qb, $config)->getScalarResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['entityId']][] = $row['relatedEntityId'];
        }

        return $result;
    }

    /**
     * @param array $bindings
     *
     * @return array
     */
    protected function getRelatedItemsIds($bindings)
    {
        $result = [];
        foreach ($bindings as $ids) {
            foreach ($ids as $id) {
                if (!isset($result[$id])) {
                    $result[$id] = $id;
                }
            }
        }

        return array_values($result);
    }

    /**
     * @param string $entityClass
     * @param array  $entityIds
     * @param array  $config
     *
     * @return array
     */
    protected function loadRelatedData($entityClass, $entityIds, $config)
    {
        $relatedData    = [];
        $entityMetadata = $this->doctrineHelper->getEntityMetadata($entityClass);
        $fields         = $this->getFields($entityClass, $config);
        foreach ($fields as $field) {
            if (!$entityMetadata->isCollectionValuedAssociation($field)) {
                continue;
            }

            $mapping      = $entityMetadata->getAssociationMapping($field);
            $targetConfig = $this->getFieldConfig($config, $field);

            $relatedData[$field] = $this->hasAssociations($mapping['targetEntity'], $targetConfig)
                ? $this->loadRelatedItems($entityIds, $mapping, $targetConfig)
                : $this->loadRelatedItemsForSimpleEntity($entityIds, $mapping, $targetConfig);
        }

        return $relatedData;
    }

    /**
     * @param array $entityIds
     * @param array $mapping
     * @param array $config
     *
     * @return array
     */
    protected function loadRelatedItems($entityIds, $mapping, $config)
    {
        $entityClass = $mapping['targetEntity'];
        $bindings    = $this->getRelatedItemsBindings($entityIds, $mapping, $config);
        $qb          = $this->doctrineHelper->getEntityRepository($entityClass)
            ->createQueryBuilder('r')
            ->where(sprintf('r.%s IN (:ids)', $this->getEntityIdFieldName($entityClass)))
            ->setParameter('ids', $this->getRelatedItemsIds($bindings));
        $this->prepareQuery($qb, $config);
        $data = $this->getQuery($qb, $config)->getResult();

        $result = [];
        if (!empty($data)) {
            $items = $this->serializeEntities((array)$data, $entityClass, $config, true);
            foreach ($bindings as $entityId => $relatedEntityIds) {
                foreach ($relatedEntityIds as $relatedEntityId) {
                    if (isset($items[$relatedEntityId])) {
                        $result[$entityId][] = $items[$relatedEntityId];
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param array $entityIds
     * @param array $mapping
     * @param array $config
     *
     * @return array
     */
    protected function loadRelatedItemsForSimpleEntity($entityIds, $mapping, $config)
    {
        $qb = $this->getToManyAssociationQueryBuilder($entityIds, $mapping);
        if (!empty($config['orderBy'])) {
            foreach ($config['orderBy'] as $field => $order) {
                $qb->addOrderBy(sprintf('r.%s', $field), $order);
            }
        }
        $fields = $this->getFieldsToSerialize($mapping['targetEntity'], $config);
        foreach ($fields as $field) {
            $qb->addSelect(sprintf('r.%s', $field));
        }

        $items = $this->getQuery($qb, $config)->getArrayResult();

        $result      = [];
        $entityClass = $mapping['targetEntity'];
        foreach ($items as $item) {
            $result[$item['entityId']][] = $this->serializeItem($item, $entityClass, $config);
        }

        return $result;
    }

    /**
     * @param array  $result
     * @param array  $relatedData
     * @param array  $config
     * @param array  $entityIds
     */
    protected function applyRelatedData(array &$result, $relatedData, $config, $entityIds)
    {
        foreach ($result as &$resultItem) {
            $entityId = array_shift($entityIds);

            foreach ($relatedData as $field => $relatedItems) {
                $targetConfig = $this->getFieldConfig($config, $field);
                $resultName   = $this->getResultFieldName($field, $targetConfig);

                $resultItem[$resultName] = [];
                if (empty($relatedItems[$entityId])) {
                    continue;
                }

                foreach ($relatedItems[$entityId] as $relatedItem) {
                    if (!empty($targetConfig['fields']) && is_string($targetConfig['fields'])) {
                        $resultItem[$resultName][] = $relatedItem[$targetConfig['fields']];
                    } else {
                        $resultItem[$resultName][] = $relatedItem;
                    }
                }
            }
        }
    }

    /**
     * @param array $config
     *
     * @return boolean
     */
    protected function isExcludeAll($config)
    {
        return
            (isset($config['exclusion_policy']) && $config['exclusion_policy'] === 'all')
            || (!empty($config['fields']) && is_string($config['fields']));
    }

    /**
     * @param array $config
     *
     * @return boolean
     */
    protected function isPartialAllowed($config)
    {
        return !isset($config['disable_partial_load']) || !$config['disable_partial_load'];
    }

    /**
     * @param array $config
     *
     * @return string[]
     */
    protected function getExcludedFields($config)
    {
        return !empty($config['excluded_fields'])
            ? $config['excluded_fields']
            : [];
    }

    /**
     * @param string $entityClass
     * @param array  $config
     * @param bool   $allowExtendedFields
     *
     * @return string[]
     */
    protected function getFields($entityClass, $config, $allowExtendedFields = false)
    {
        if ($this->isExcludeAll($config)) {
            $fields = $this->getConfigFields($config);
        } else {
            $entityMetadata = $this->doctrineHelper->getEntityMetadata($entityClass);
            $fields         = array_filter(
                array_merge($entityMetadata->getFieldNames(), $entityMetadata->getAssociationNames()),
                function ($field) use ($entityClass, $config, $allowExtendedFields) {
                    return
                        $this->hasFieldConfig($config, $field)
                        || $this->isApplicableField($entityClass, $field, $allowExtendedFields);
                }
            );
            $fields         = array_merge(
                $fields,
                array_filter(
                    $this->getConfigFields($config),
                    function ($field) {
                        return $this->isMetadataField($field);
                    }
                )
            );
        }
        $excludedFields = $this->getExcludedFields($config);
        if (!empty($excludedFields)) {
            $fields = array_diff($fields, $excludedFields);
        }

        return $fields;
    }

    /**
     * @param array $config
     *
     * @return string
     */
    protected function getConfigFields($config)
    {
        if (empty($config['fields'])) {
            return [];
        } elseif (is_string($config['fields'])) {
            return [$config['fields']];
        } else {
            return array_keys($config['fields']);
        }
    }

    /**
     * @param string $entityClass
     * @param string $field
     * @param bool   $allowExtendedFields
     *
     * @return bool
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function isApplicableField($entityClass, $field, $allowExtendedFields)
    {
        if (!$this->dataAccessor->hasGetter($entityClass, $field)) {
            return false;
        }

        $fieldModel = $this->configManager->getConfigFieldModel($entityClass, $field);
        if (!$fieldModel) {
            // this serializer works with non configurable entities as well
            return true;
        }

        if ($fieldModel->getMode() === ConfigModelManager::MODE_HIDDEN) {
            // exclude hidden fields
            return false;
        }

        $extendConfigProvider = $this->configManager->getProvider('extend');
        $extendConfig         = $extendConfigProvider->getConfig($entityClass, $field);

        if (!$allowExtendedFields && $extendConfig->is('is_extend')) {
            // exclude extended fields if it is requested
            return false;
        }

        if ($extendConfig->is('is_deleted') || $extendConfig->is('state', ExtendScope::STATE_NEW)) {
            // exclude deleted and not created yet fields
            return false;
        }

        if ($extendConfig->has('target_entity')
            && $extendConfigProvider->getConfig($extendConfig->get('target_entity'))->is('is_deleted')
        ) {
            // exclude associations with deleted custom entities
            return false;
        }

        return true;
    }

    /**
     * @param string $entityClass
     * @param array  $config
     *
     * @return string[]
     */
    protected function getFieldsToSerialize($entityClass, $config)
    {
        $entityMetadata = $this->doctrineHelper->getEntityMetadata($entityClass);

        return array_filter(
            $this->getFields($entityClass, $config),
            function ($field) use ($entityMetadata) {
                return !$entityMetadata->isCollectionValuedAssociation($field);
            }
        );
    }

    /**
     * @param string $entityClass
     * @param array  $config
     *
     * @return boolean
     */
    protected function hasAssociations($entityClass, $config)
    {
        $entityMetadata = $this->doctrineHelper->getEntityMetadata($entityClass);
        $fields         = $this->getFields($entityClass, $config);
        foreach ($fields as $field) {
            if ($entityMetadata->isAssociation($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param object[] $entities
     * @param string   $entityClass
     *
     * @return array
     */
    protected function getEntityIds($entities, $entityClass)
    {
        $ids         = [];
        $idFieldName = $this->getEntityIdFieldName($entityClass);
        foreach ($entities as $entity) {
            $id = $this->dataAccessor->getValue($entity, $idFieldName);
            if (!isset($ids[$id])) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * @param string $entityClass
     *
     * @return string[]
     *
     * @deprecated since 1.8
     */
    protected function getEntityIdFieldNames($entityClass)
    {
        return $this->doctrineHelper->getEntityMetadata($entityClass)->getIdentifierFieldNames();
    }

    /**
     * @param string $entityClass
     *
     * @return string
     */
    protected function getEntityIdFieldName($entityClass)
    {
        return $this->doctrineHelper->getEntityMetadata($entityClass)->getSingleIdentifierFieldName();
    }

    /**
     * @param string $entityClass
     *
     * @return string
     *
     * @deprecated since 1.8
     */
    protected function getEntityIdGetter($entityClass)
    {
        return 'get' . ucfirst($this->getEntityIdFieldName($entityClass));
    }

    /**
     * Checks if the specified field has some special configuration
     *
     * @param array  $config The config of an entity the specified field belongs
     * @param string $field  The name of the field
     *
     * @return array
     */
    protected function hasFieldConfig($config, $field)
    {
        return
            !empty($config['fields'])
            && (
                isset($config['fields'][$field])
                || array_key_exists($field, $config['fields'])
            );
    }

    /**
     * Returns the configuration of the specified field
     *
     * @param array  $config The config of an entity the specified field belongs
     * @param string $field  The name of the field
     *
     * @return array
     */
    protected function getFieldConfig($config, $field)
    {
        return isset($config['fields'][$field])
            ? $config['fields'][$field]
            : [];
    }

    /**
     * @param string $field  The name of the field
     * @param array  $config The config of the field
     *
     * @return mixed
     */
    protected function getResultFieldName($field, $config)
    {
        return isset($config['result_name'])
            ? $config['result_name']
            : $field;
    }

    /**
     * Checks whether a field represents some metadata property
     *
     * @param string $field
     *
     * @return bool
     */
    protected function isMetadataField($field)
    {
        return strpos($field, '__') === 0;
    }

    /**
     * Returns a value of a metadata property
     *
     * @param object         $entity
     * @param string         $field
     * @param EntityMetadata $entityMetadata
     *
     * @return mixed
     */
    protected function getMetadataFieldValue($entity, $field, $entityMetadata)
    {
        switch ($field) {
            case self::DISCRIMINATOR_FIELD:
                return $entityMetadata->getDiscriminatorValue(ClassUtils::getClass($entity));
            case self::CLASS_FIELD:
                return ClassUtils::getClass($entity);
            default:
                return null;
        }
    }

    /**
     * @param QueryBuilder $qb
     * @param array        $config
     *
     * @return Query
     */
    protected function getQuery(QueryBuilder $qb, $config)
    {
        $query = $qb->getQuery();
        $this->queryHintResolver->resolveHints(
            $query,
            isset($config['hints']) ? $config['hints'] : []
        );

        return $query;
    }
}
