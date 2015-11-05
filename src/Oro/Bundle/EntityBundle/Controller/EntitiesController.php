<?php

namespace Oro\Bundle\EntityBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use FOS\RestBundle\Util\Codes;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Oro\Bundle\EntityBundle\ORM\OroEntityManager;
use Oro\Bundle\EntityConfigBundle\Config\ConfigInterface;
use Oro\Bundle\EntityConfigBundle\Config\Id\FieldConfigId;
use Oro\Bundle\EntityConfigBundle\Provider\ConfigProvider;
use Oro\Bundle\EntityConfigBundle\Tools\FieldAccessor;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;
use Oro\Bundle\EntityExtendBundle\Entity\AbstractEnumValue;
use Oro\Bundle\EntityExtendBundle\Tools\ExtendHelper;
use Oro\Bundle\SecurityBundle\SecurityFacade;

/**
 * Entities controller.
 * @Route("/entity")
 * todo: Discuss ACL permissions for controller
 */
class EntitiesController extends Controller
{
    /**
     * Grid of Custom/Extend entity.
     *
     * @param string $entityName
     *
     * @return array
     *
     * @Route(
     *      "/{entityName}",
     *      name="oro_entity_index"
     * )
     * @Template()
     */
    public function indexAction($entityName)
    {
        $entityClass = $this->get('oro_entity.routing_helper')->resolveEntityClass($entityName);

        if (!class_exists($entityClass)) {
            throw $this->createNotFoundException();
        }

        $this->checkAccess('VIEW', $entityClass);

        /** @var ConfigProvider $entityConfigProvider */
        $entityConfigProvider = $this->get('oro_entity_config.provider.entity');

        if (!$entityConfigProvider->hasConfig($entityClass)) {
            throw $this->createNotFoundException();
        }

        $entityConfig = $entityConfigProvider->getConfig($entityClass);

        return [
            'entity_name'  => $entityName,
            'entity_class' => $entityClass,
            'label'        => $entityConfig->get('label'),
            'plural_label' => $entityConfig->get('plural_label')
        ];
    }

    /**
     * @param string $id
     * @param string $entityName
     * @param string $fieldName
     *
     * @return array
     *
     * @Route(
     *      "/detailed/{id}/{entityName}/{fieldName}",
     *      name="oro_entity_detailed",
     *      defaults={"id"=0, "fieldName"=""}
     * )
     * @Template
     */
    public function detailedAction($id, $entityName, $fieldName)
    {
        $entityClass = $this->get('oro_entity.routing_helper')->resolveEntityClass($entityName);

        if (!class_exists($entityClass)) {
            throw $this->createNotFoundException();
        }

        $this->checkAccess('VIEW', $entityClass);

        $entityProvider = $this->get('oro_entity_config.provider.entity');
        $extendProvider = $this->get('oro_entity_config.provider.extend');
        $relationConfig = $extendProvider->getConfig($entityClass, $fieldName);
        $relationTargetEntity = $relationConfig->get('target_entity');

        if (!class_exists($relationTargetEntity)) {
            throw $this->createNotFoundException();
        }

        /** @var ConfigInterface[] $fields */
        $fields = $extendProvider->filter(
            function (ConfigInterface $config) use ($relationConfig) {
                /** @var FieldConfigId $fieldConfigId */
                $fieldConfigId = $config->getId();

                return
                    !$config->is('state', ExtendScope::STATE_NEW)
                    && !$config->is('is_deleted')
                    && $fieldConfigId instanceof FieldConfigId
                    && in_array($fieldConfigId->getFieldName(), (array)$relationConfig->get('target_detailed'));
            },
            $relationConfig->get('target_entity')
        );

        $entity = $this->getDoctrine()->getRepository($relationTargetEntity)->find($id);

        if (!$entity) {
            return $this->createNotFoundException();
        }

        $dynamicRow = array();
        foreach ($fields as $field) {
            /** @var FieldConfigId $fieldConfigId */
            $fieldConfigId      = $field->getId();
            $fieldName          = $fieldConfigId->getFieldName();
            $label              = $entityProvider->getConfigById($fieldConfigId)->get('label') ?: $fieldName;
            $dynamicRow[$label] = FieldAccessor::getValue($entity, $fieldName);
        }

        return array(
            'dynamic' => $dynamicRow,
            'entity'  => $entity
        );
    }

    /**
     * Grid of Custom/Extend entity.
     *
     * @param string $id
     * @param string $entityName
     * @param string $fieldName
     *
     * @return array
     *
     * @Route(
     *      "/relation/{id}/{entityName}/{fieldName}",
     *      name="oro_entity_relation",
     *      defaults={"id"=0, "className"="", "fieldName"=""}
     * )
     * @Template()
     */
    public function relationAction($id, $entityName, $fieldName)
    {
        $entityClass = $this->get('oro_entity.routing_helper')->resolveEntityClass($entityName);

        if (!class_exists($entityClass)) {
            throw $this->createNotFoundException();
        }

        $this->checkAccess('VIEW', $entityClass);

        /** @var ConfigProvider $entityConfigProvider */
        $entityConfigProvider = $this->get('oro_entity_config.provider.entity');
        $extendConfigProvider = $this->get('oro_entity_config.provider.extend');

        if (!$entityConfigProvider->hasConfig($entityClass)) {
            throw $this->createNotFoundException();
        }

        $entityConfig = $entityConfigProvider->getConfig($entityClass);
        $fieldConfig  = $extendConfigProvider->getConfig($entityClass, $fieldName);

        return [
            'id'              => $id,
            'field_name'      => $fieldName,
            'entity_name'     => $entityName,
            'entity_class'    => $entityClass,
            'label'           => $entityConfig->get('label'),
            'entity_provider' => $entityConfigProvider,
            'extend_provider' => $extendConfigProvider,
            'relation'        => $fieldConfig
        ];
    }


    /**
     * View custom entity instance.
     *
     * @param string $entityName
     * @param string $id
     *
     * @return array
     *
     * @Route(
     *      "/view/{entityName}/item/{id}",
     *      name="oro_entity_view"
     * )
     * @Template()
     */
    public function viewAction($entityName, $id)
    {
        $entityClass = $this->get('oro_entity.routing_helper')->resolveEntityClass($entityName);

        if (!class_exists($entityClass)) {
            throw $this->createNotFoundException();
        }

        $this->checkAccess('VIEW', $entityClass);

        /** @var OroEntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $entityConfigProvider = $this->get('oro_entity_config.provider.entity');
        $record = $em->getRepository($entityClass)->find($id);

        if (!$record) {
            throw $this->createNotFoundException();
        }

        // in order to not override template, just add 'virtual' id column
        if (!property_exists($entityClass, 'id')) {
            $record->{'id'} = $this->get('oro_entity.doctrine_helper')
                ->getSingleEntityIdentifier($record);
        }

        return [
            'entity_name'   => $entityName,
            'entity'        => $record,
            'id'            => $id,
            'entity_config' => $entityConfigProvider->getConfig($entityClass),
            'entity_class'  => $entityClass,
        ];
    }

    /**
     * Update custom entity instance.
     *
     * @param Request $request
     * @param string $entityName
     * @param string $id
     *
     * @return array
     *
     * @Route(
     *      "/update/{entityName}/item/{id}",
     *      name="oro_entity_update",
     *      defaults={"id"=0}
     * )
     * @Template()
     */
    public function updateAction(Request $request, $entityName, $id)
    {
        $entityClass = $this->get('oro_entity.routing_helper')->resolveEntityClass($entityName);

        if (!class_exists($entityClass)) {
            throw $this->createNotFoundException();
        }

        $this->checkAccess(!$id ? 'CREATE' : 'EDIT', $entityClass);

        /** @var OroEntityManager $em */
        $em = $this->getDoctrine()->getManager();

        /** @var ConfigProvider $entityConfigProvider */
        $entityConfigProvider = $this->get('oro_entity_config.provider.entity');
        $entityConfig         = $entityConfigProvider->getConfig($entityClass);

        $record = !$id ? $this->createEntity($entityClass) : $em->getRepository($entityClass)->find($id);

        $form = $this->createForm(
            $this->isEnumClass($entityClass) ? 'custom_enum_type' : 'custom_entity_type',
            $record,
            array(
                'data_class'   => $entityClass,
                'block_config' => array(
                    'general' => array(
                        'title' => 'General'
                    )
                ),
            )
        );

        if ($request->getMethod() == 'POST') {
            $form->submit($request);

            if ($form->isValid()) {
                // form processing may change data
                $record = $form->getData();
                $em->persist($record);
                $em->flush();

                $id = $this->get('oro_entity.doctrine_helper')->getSingleEntityIdentifier($record);

                $this->get('session')->getFlashBag()->add(
                    'success',
                    $this->get('translator')->trans('oro.entity.controller.message.saved')
                );

                return $this->get('oro_ui.router')->redirectAfterSave(
                    ['route' => 'oro_entity_update', 'parameters' => ['entityName' => $entityName, 'id'=> $id]],
                    ['route' => 'oro_entity_view', 'parameters' => ['entityName' => $entityName, 'id' => $id]]
                );
            }
        }

        if (!property_exists($entityClass, 'id')) {
            $record->{'id'} = $this->get('oro_entity.doctrine_helper')->getSingleEntityIdentifier($record);
        }

        return [
            'entity'        => $record,
            'entity_name'   => $entityName,
            'entity_config' => $entityConfig,
            'entity_class'  => $entityClass,
            'form'          => $form->createView(),
        ];
    }

    /**
     * @param string $entityClass
     *
     * @return bool
     */
    protected function isEnumClass($entityClass)
    {
        return strpos($entityClass, ExtendHelper::ENTITY_NAMESPACE . 'EV_') !== false;
    }

    /**
     * Delete custom entity instance.
     *
     * @param string $entityName
     * @param string $id
     *
     * @return array
     *
     * @Route(
     *      "/delete/{entityName}/item/{id}",
     *      name="oro_entity_delete"
     * )
     */
    public function deleteAction($entityName, $id)
    {
        $entityClass = $this->get('oro_entity.routing_helper')->resolveEntityClass($entityName);

        if (!class_exists($entityClass)) {
            throw $this->createNotFoundException();
        }

        $this->checkAccess('DELETE', $entityClass);

        /** @var OroEntityManager $em */
        $em = $this->getDoctrine()->getManager();

        $entityRepository = $em->getRepository($entityClass);

        $record = $entityRepository->find($id);
        if (!$record) {
            return new JsonResponse('', Codes::HTTP_FORBIDDEN);
        }

        $em->remove($record);
        $em->flush();

        return new JsonResponse('', Codes::HTTP_OK);
    }

    /**
     * Checks if an access to the given entity is granted or not
     *
     * @param string $permission
     * @param string $entityName
     * @return bool
     * @throws AccessDeniedException
     */
    private function checkAccess($permission, $entityName)
    {
        /** @var SecurityFacade $securityFacade */
        $securityFacade = $this->get('oro_security.security_facade');
        $isGranted      = $securityFacade->isGranted($permission, 'entity:' . $entityName);
        if (!$isGranted) {
            throw new AccessDeniedException('Access denied.');
        }
    }

    /**
     * @param string $entityClass
     *
     * @return object|AbstractEnumValue
     */
    protected function createEntity($entityClass)
    {
        $reflClass = new \ReflectionClass($entityClass);
        $hasRequiredParams = $reflClass->getConstructor()->getNumberOfRequiredParameters() > 0;

        if ($this->isEnumClass($entityClass) || $hasRequiredParams) {
            return $reflClass->newInstanceWithoutConstructor();
        } else {
            return new $entityClass;
        }
    }
}
