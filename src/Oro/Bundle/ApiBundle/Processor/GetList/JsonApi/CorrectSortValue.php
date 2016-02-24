<?php

namespace Oro\Bundle\ApiBundle\Processor\GetList\JsonApi;

use Oro\Component\ChainProcessor\ContextInterface;
use Oro\Component\ChainProcessor\ProcessorInterface;
use Oro\Bundle\ApiBundle\Processor\GetList\GetListContext;
use Oro\Bundle\ApiBundle\Util\DoctrineHelper;

/**
 * Replaces sorting by "id" field with sorting by real entity identifier field name.
 */
class CorrectSortValue implements ProcessorInterface
{
    const SORT_FILTER_KEY = 'sort';
    const ARRAY_DELIMITER = ',';

    /** @var DoctrineHelper */
    protected $doctrineHelper;

    /**
     * @param DoctrineHelper $doctrineHelper
     */
    public function __construct(DoctrineHelper $doctrineHelper)
    {
        $this->doctrineHelper = $doctrineHelper;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContextInterface $context)
    {
        /** @var GetListContext $context */

        if ($context->hasQuery()) {
            // a query is already built
            return;
        }

        $entityClass = $context->getClassName();
        if (!$this->doctrineHelper->isManageableEntityClass($entityClass)) {
            // only manageable entities are supported
            return;
        }

        $filterValues = $context->getFilterValues();
        if ($filterValues->has(self::SORT_FILTER_KEY)) {
            $filterValue = $filterValues->get(self::SORT_FILTER_KEY);
            $filterValue->setValue(
                $this->normalizeValue($filterValue->getValue(), $entityClass)
            );
        }
    }

    /**
     * @param mixed  $value
     * @param string $entityClass
     *
     * @return mixed
     */
    protected function normalizeValue($value, $entityClass)
    {
        if (empty($value) || !is_string($value)) {
            return $value;
        }

        $result = [];
        $items  = explode(self::ARRAY_DELIMITER, $value);
        foreach ($items as $item) {
            switch (trim($item)) {
                case 'id':
                    $this->addEntityIdentifierFieldNames($result, $entityClass);
                    break;
                case '-id':
                    $this->addEntityIdentifierFieldNames($result, $entityClass, true);
                    break;
                default:
                    $result[] = $item;
                    break;
            }
        }

        return implode(self::ARRAY_DELIMITER, $result);
    }

    /**
     * @param string[] $result
     * @param string   $entityClass
     * @param bool     $desc
     */
    protected function addEntityIdentifierFieldNames(array &$result, $entityClass, $desc = false)
    {
        $idFieldNames = $this->doctrineHelper->getEntityIdentifierFieldNamesForClass($entityClass);
        foreach ($idFieldNames as $fieldName) {
            $result[] = $desc ? '-' . $fieldName : $fieldName;
        }
    }
}
