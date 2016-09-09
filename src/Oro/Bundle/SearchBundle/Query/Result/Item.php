<?php

namespace Oro\Bundle\SearchBundle\Query\Result;

use BeSimple\SoapBundle\ServiceDefinition\Annotation as Soap;

use Doctrine\Common\Persistence\ObjectManager;

use JMS\Serializer\Annotation\Type;
use JMS\Serializer\Annotation\Exclude;

class Item
{
    /**
     * @var string
     * @Type("string")
     * @Soap\ComplexType("string")
     */
    protected $entityName;

    /**
     * @var int
     * @Type("integer")
     * @Soap\ComplexType("int")
     */
    protected $recordId;

    /**
     * @Soap\ComplexType("string")
     * @var string
     */
    protected $recordTitle;

    /**
     * @Soap\ComplexType("string")
     * @var string
     */
    protected $recordUrl;

    /**
     * @var array
     */
    protected $entityConfig;

    /**
     * @var ObjectManager
     * @Exclude
     */
    protected $em;

    /** @var object */
    protected $entity;

    /**
     * @Soap\ComplexType("Oro\Bundle\SearchBundle\Soap\Type\SelectedValue[]")
     * @var string[]
     */
    protected $selectedData = [];

    /**
     * @param ObjectManager $em
     * @param string|null   $entityName
     * @param string|null   $recordId
     * @param string|null   $recordTitle
     * @param string|null   $recordUrl
     * @param array         $selectedData
     * @param array         $entityConfig
     */
    public function __construct(
        ObjectManager $em,
        $entityName = null,
        $recordId = null,
        $recordTitle = null,
        $recordUrl = null,
        $selectedData = [],
        $entityConfig = []
    ) {
        $this->em           = $em;
        $this->entityName   = $entityName;
        $this->recordId     = empty($recordId) ? 0 : $recordId;
        $this->recordTitle  = $recordTitle;
        $this->recordUrl    = $recordUrl;
        $this->entityConfig = empty($entityConfig) ? [] : $entityConfig;
        $this->selectedData = is_array($selectedData) ? $selectedData : [];
    }

    /**
     * Set entity name
     *
     * @param string $entityName
     * @return Item
     */
    public function setEntityName($entityName)
    {
        $this->entityName = $entityName;

        return $this;
    }

    /**
     * Set record id
     *
     * @param $recordId
     * @return Item
     */
    public function setRecordId($recordId)
    {
        $this->recordId = $recordId;

        return $this;
    }

    /**
     * Get entity name
     *
     * @return string
     */
    public function getEntityName()
    {
        return $this->entityName;
    }

    /**
     * Get record id
     *
     * @return int
     */
    public function getRecordId()
    {
        return $this->recordId;
    }

    /**
     * Load related object
     * @return object
     */
    public function getEntity()
    {
        return $this->entity ?: $this->em->getRepository($this->entityName)->find($this->recordId);
    }

    /**
     * @param object $entity
     *
     * @return Item
     */
    public function setEntity($entity)
    {
        $this->entity = $entity;

        return $this;
    }

    /**
     * Set record title
     *
     * @param string $recordTitle
     *
     * @return Item
     */
    public function setRecordTitle($recordTitle)
    {
        $this->recordTitle = $recordTitle;

        return $this;
    }

    /**
     * Get record string
     *
     * @return string
     */
    public function getRecordTitle()
    {
        return $this->recordTitle;
    }

    /**
     * Set record string
     *
     * @param string $recordUrl
     *
     * @return Item
     */
    public function setRecordUrl($recordUrl)
    {
        $this->recordUrl = $recordUrl;

        return $this;
    }

    /**
     * Get record url
     *
     * @return string
     */
    public function getRecordUrl()
    {
        return $this->recordUrl;
    }

    /**
     * Get entity mapping config array
     *
     * @return array
     */
    public function getEntityConfig()
    {
        return $this->entityConfig;
    }

    /**
     * @return array
     */
    public function getSelectedData()
    {
        return $this->selectedData;
    }

    /**
     * @param array $selectedData
     * @return $this
     */
    public function setSelectedData(array $selectedData)
    {
        $this->selectedData = $selectedData;

        return $this;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        $result = [
            'entity_name'   => $this->entityName,
            'record_id'     => $this->recordId,
            'record_string' => $this->recordTitle,
            'record_url'    => $this->recordUrl,
        ];

        if (count($this->selectedData) > 0) {
            $result['selected_data'] = $this->selectedData;
        }

        return $result;
    }
}
