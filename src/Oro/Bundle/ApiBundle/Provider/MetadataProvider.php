<?php

namespace Oro\Bundle\ApiBundle\Provider;

use Oro\Bundle\ApiBundle\Metadata\EntityMetadata;
use Oro\Bundle\ApiBundle\Metadata\MetadataExtraInterface;
use Oro\Bundle\ApiBundle\Processor\GetMetadata\MetadataContext;
use Oro\Bundle\ApiBundle\Processor\MetadataProcessor;

class MetadataProvider
{
    /** @var MetadataProcessor */
    protected $processor;

    /**
     * @param MetadataProcessor $processor
     */
    public function __construct(MetadataProcessor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Gets metadata for the given version of an entity.
     *
     * @param string                   $className   The FQCN of an entity
     * @param string                   $version     The version of a config
     * @param string[]                 $requestType The type of API request, for example "rest", "soap", "odata", etc.
     * @param MetadataExtraInterface[] $extras      Additional metadata information
     * @param array|null               $config      The configuration of an entity
     *
     * @return EntityMetadata|null
     */
    public function getMetadata($className, $version, array $requestType = [], array $extras = [], $config = null)
    {
        if (empty($className)) {
            throw new \InvalidArgumentException('$className must not be empty.');
        }

        /** @var MetadataContext $context */
        $context = $this->processor->createContext();
        $context->setClassName($className);
        $context->setVersion($version);
        if (!empty($requestType)) {
            $context->setRequestType($requestType);
        }
        if (!empty($extras)) {
            $context->setExtras($extras);
        }
        if (!empty($config)) {
            $context->setConfig($config);
        }

        $this->processor->process($context);

        $result = null;
        if ($context->hasResult()) {
            $result = $context->getResult();
        }

        return $result;
    }
}
