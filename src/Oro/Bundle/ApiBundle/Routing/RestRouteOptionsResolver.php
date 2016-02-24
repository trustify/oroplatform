<?php

namespace Oro\Bundle\ApiBundle\Routing;

use Symfony\Component\Routing\Route;

use Oro\Component\Routing\Resolver\RouteCollectionAccessor;
use Oro\Component\Routing\Resolver\RouteOptionsResolverInterface;

use Oro\Bundle\ApiBundle\Provider\PublicResourcesLoader;
use Oro\Bundle\ApiBundle\Request\RequestType;
use Oro\Bundle\ApiBundle\Request\RestRequest;
use Oro\Bundle\ApiBundle\Request\ValueNormalizer;
use Oro\Bundle\ApiBundle\Request\Version;
use Oro\Bundle\ApiBundle\Util\DoctrineHelper;
use Oro\Bundle\EntityBundle\ORM\EntityAliasResolver;

class RestRouteOptionsResolver implements RouteOptionsResolverInterface
{
    const ROUTE_GROUP        = 'rest_api';
    const ENTITY_ATTRIBUTE   = 'entity';
    const ENTITY_PLACEHOLDER = '{entity}';
    const ID_ATTRIBUTE       = 'id';
    const ID_PLACEHOLDER     = '{id}';
    const FORMAT_ATTRIBUTE   = '_format';

    /** @var bool */
    protected $isApplicationInstalled;

    /** @var PublicResourcesLoader */
    protected $resourcesLoader;

    /** @var EntityAliasResolver */
    protected $entityAliasResolver;

    /** @var DoctrineHelper */
    protected $doctrineHelper;

    /** @var ValueNormalizer */
    protected $valueNormalizer;

    /** @var string[] */
    protected $formats;

    /** @var string[] */
    protected $defaultFormat;

    /** @var array */
    private $supportedEntities;

    /**
     * @param bool|string|null      $isApplicationInstalled
     * @param PublicResourcesLoader $resourcesLoader
     * @param EntityAliasResolver   $entityAliasResolver
     * @param DoctrineHelper        $doctrineHelper
     * @param ValueNormalizer       $valueNormalizer
     * @param string                $formats
     * @param string                $defaultFormat
     */
    public function __construct(
        $isApplicationInstalled,
        PublicResourcesLoader $resourcesLoader,
        EntityAliasResolver $entityAliasResolver,
        DoctrineHelper $doctrineHelper,
        ValueNormalizer $valueNormalizer,
        $formats,
        $defaultFormat
    ) {
        $this->isApplicationInstalled = !empty($isApplicationInstalled);
        $this->resourcesLoader        = $resourcesLoader;
        $this->entityAliasResolver    = $entityAliasResolver;
        $this->doctrineHelper         = $doctrineHelper;
        $this->valueNormalizer        = $valueNormalizer;
        $this->formats                = $formats;
        $this->defaultFormat          = $defaultFormat;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve(Route $route, RouteCollectionAccessor $routes)
    {
        if (!$this->isApplicationInstalled || $route->getOption('group') !== self::ROUTE_GROUP) {
            return;
        }

        if ($this->hasAttribute($route, self::ENTITY_PLACEHOLDER)) {
            $this->setFormatAttribute($route);

            $entities = $this->getSupportedEntities();
            if (!empty($entities)) {
                $this->adjustRoutes($route, $routes, $entities);
            }
            $route->setRequirement(self::ENTITY_ATTRIBUTE, '\w+');

            $route->setOption('hidden', true);
        }
    }

    /**
     * @return array [[entity class, entity plural alias], ...]
     */
    protected function getSupportedEntities()
    {
        if (null === $this->supportedEntities) {
            $resources = $this->resourcesLoader->getResources(
                Version::LATEST,
                [RequestType::REST, RequestType::JSON_API]
            );

            $this->supportedEntities = [];
            foreach ($resources as $resource) {
                $className   = $resource->getEntityClass();
                $pluralAlias = $this->entityAliasResolver->getPluralAlias($className);
                if (!empty($pluralAlias)) {
                    $this->supportedEntities[] = [
                        $className,
                        $pluralAlias
                    ];
                }
            }
        }

        return $this->supportedEntities;
    }

    /**
     * @param Route                   $route
     * @param RouteCollectionAccessor $routes
     * @param array                   $entities [[entity class, entity plural alias], ...]
     */
    protected function adjustRoutes(Route $route, RouteCollectionAccessor $routes, $entities)
    {
        $routeName = $routes->getName($route);

        foreach ($entities as $entity) {
            list($className, $pluralAlias) = $entity;

            $existingRoute = $routes->getByPath(
                str_replace(self::ENTITY_PLACEHOLDER, $pluralAlias, $route->getPath()),
                $route->getMethods()
            );
            if ($existingRoute) {
                // move existing route before the current route
                $existingRouteName = $routes->getName($existingRoute);
                $routes->remove($existingRouteName);
                $routes->insert($existingRouteName, $existingRoute, $routeName, true);
            } else {
                // add an additional strict route based on the base route and current entity
                $strictRoute = $routes->cloneRoute($route);
                $strictRoute->setPath(str_replace(self::ENTITY_PLACEHOLDER, $pluralAlias, $strictRoute->getPath()));
                $strictRoute->setDefault(self::ENTITY_ATTRIBUTE, $pluralAlias);
                $requirements = $strictRoute->getRequirements();
                unset($requirements[self::ENTITY_ATTRIBUTE]);
                $strictRoute->setRequirements($requirements);
                if ($this->hasAttribute($route, self::ID_PLACEHOLDER)) {
                    $this->setIdRequirement($strictRoute, $className);
                }
                $routes->insert(
                    $routes->generateRouteName($routeName),
                    $strictRoute,
                    $routeName,
                    true
                );
            }
        }
    }

    /**
     * @param Route $route
     */
    protected function setFormatAttribute(Route $route)
    {
        $route->setRequirement(self::FORMAT_ATTRIBUTE, $this->formats);
        $route->setDefault(self::FORMAT_ATTRIBUTE, $this->defaultFormat);
    }

    /**
     * @param Route  $route
     * @param string $entityClass
     */
    protected function setIdRequirement(Route $route, $entityClass)
    {
        $metadata     = $this->doctrineHelper->getEntityMetadataForClass($entityClass);
        $idFields     = $metadata->getIdentifierFieldNames();
        $idFieldCount = count($idFields);
        if ($idFieldCount === 1) {
            // single identifier
            $route->setRequirement(
                self::ID_ATTRIBUTE,
                $this->getIdFieldRequirement($metadata->getTypeOfField(reset($idFields)))
            );
        } elseif ($idFieldCount > 1) {
            // combined identifier
            $requirements = [];
            foreach ($idFields as $field) {
                $requirements[] = $field . '=' . $this->getIdFieldRequirement($metadata->getTypeOfField($field));
            }
            $route->setRequirement(
                self::ID_ATTRIBUTE,
                implode(RestRequest::ARRAY_DELIMITER, $requirements)
            );
        }
    }

    /**
     * @param string $fieldType
     *
     * @return string
     */
    protected function getIdFieldRequirement($fieldType)
    {
        $result = $this->valueNormalizer->getRequirement(
            $fieldType,
            [RequestType::REST, RequestType::JSON_API]
        );

        if (ValueNormalizer::DEFAULT_REQUIREMENT === $result) {
            $result = '[^\.]+';
        }

        return $result;
    }

    /**
     * Checks if a route has the given placeholder in a path.
     *
     * @param Route  $route
     * @param string $placeholder
     *
     * @return bool
     */
    protected function hasAttribute(Route $route, $placeholder)
    {
        return false !== strpos($route->getPath(), $placeholder);
    }
}
