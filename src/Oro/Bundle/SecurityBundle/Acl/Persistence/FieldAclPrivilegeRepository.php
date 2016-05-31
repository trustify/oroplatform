<?php

namespace Oro\Bundle\SecurityBundle\Acl\Persistence;

use Doctrine\Common\Collections\ArrayCollection;

use Symfony\Component\Security\Acl\Model\SecurityIdentityInterface as SID;
use Symfony\Component\Security\Acl\Domain\ObjectIdentity as OID;
use Symfony\Component\Translation\TranslatorInterface;

use Oro\Bundle\SecurityBundle\Acl\Permission\MaskBuilder;
use Oro\Bundle\SecurityBundle\Acl\Extension\AclExtensionInterface;
use Oro\Bundle\SecurityBundle\Model\AclPrivilege;
use Oro\Bundle\SecurityBundle\Model\AclPrivilegeIdentity;
use Oro\Bundle\SecurityBundle\Metadata\EntitySecurityMetadata;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Model\AclPermission;
use Oro\Bundle\EntityBundle\Provider\EntityFieldProvider;

class FieldAclPrivilegeRepository extends AclPrivilegeRepository
{
    /** @var EntityFieldProvider */
    protected $fieldProvider;

    /**
     * @param AclManager          $manager
     * @param TranslatorInterface $translator
     * @param EntityFieldProvider $fieldProvider
     */
    public function __construct(
        AclManager $manager,
        TranslatorInterface $translator,
        EntityFieldProvider $fieldProvider
    ) {
        parent::__construct($manager, $translator);

        $this->fieldProvider = $fieldProvider;
    }

    /**
     * @param string                $className
     * @param AclExtensionInterface $extension
     *
     * @return EntitySecurityMetadata
     */
    protected function getClassMetadata($className, $extension)
    {
        $entityClasses = array_filter(
            $extension->getClasses(),
            function (EntitySecurityMetadata $entityMetadata) use ($className) {
                return $entityMetadata->getClassName() == $className;
            }
        );

        return reset($entityClasses);
    }

    /**
     * @param SID    $sid
     * @param string $className
     *
     * @return ArrayCollection|AclPrivilege[]
     */
    public function getFieldsPrivileges(SID $sid, $className)
    {
        $extensionKey = 'field';
        $extension = $this->manager->getExtensionSelector()->select(
            $extensionKey . ':' . $className
        );
        $entityClass = $this->getClassMetadata($className, $extension);

        $oids = [];
        $oids[] = $entityRootOid = $this->manager->getRootOid('entity');
        $oids[] = $fieldRootOid = new OID('entity', $className);

        $objectIdentity = new OID($extensionKey, $className);
        $oids[] = $objectIdentity;
        $acls = $this->findAcls($sid, $oids);

        // find ACL for the root object identity
        // root identify for field level ACL is corresponding class level entity ACL, or root entity OID
        $rootAcl = $this->findAclByOid($acls, $fieldRootOid);

        // check if there are any aces to fallback to
        $rootAces = $rootAcl ? $this->getFirstNotEmptyAce(
            $sid,
            $rootAcl,
            [
                [AclManager::OBJECT_ACE, null],
                [AclManager::CLASS_ACE, null],
            ]
        ) : [];

        // if no - use root entity identity (that is always exists)
        if (empty($rootAces)) {
            $rootAcl = $this->findAclByOid($acls, $entityRootOid);
        }

        // with relations, without virtual and unidirectional fields, without entity details and without exclusions
        // there could be ACL AclExclusionProvider to filter restricted fields, so for ACL UI it shouldn't be used
        $fieldsArray = $this->fieldProvider->getFields($className, true, false, false, false, false);
        $privileges = new ArrayCollection();
        foreach ($fieldsArray as $fieldInfo) {
            $privilege = new AclPrivilege();
            $privilege->setIdentity(
                new AclPrivilegeIdentity(
                    sprintf('%s+%s:%s', $objectIdentity->getIdentifier(), $fieldInfo['name'], $objectIdentity->getType()),
                    $fieldInfo['label']
                )
            )
                ->setGroup($entityClass->getGroup())
                ->setExtensionKey($extensionKey);

            $this->addPermissions($sid, $privilege, $objectIdentity, $acls, $extension, $rootAcl, $fieldInfo['name']);
            $privileges->add($privilege);
        }

        $this->sortPrivileges($privileges);

        return $privileges;
    }

    /**
     * @param SID             $sid
     * @param OID             $oid
     * @param ArrayCollection $privileges
     *
     * @throws \Exception
     */
    public function saveFieldPrivileges(SID $sid, OID $oid, ArrayCollection $privileges)
    {
        $extension = $this->manager->getExtensionSelector()->select('field:' . $oid->getType());

        /** @var MaskBuilder[] $maskBuilders */
        $maskBuilders = [];
        $this->prepareMaskBuilders($maskBuilders, $extension);

        /** @var AclPrivilege $privilege */
        foreach ($privileges as $privilege) {
            // compile masks
            $masks = $this->getPermissionMasks($privilege->getPermissions(), $extension, $maskBuilders);

            $fieldName = explode('+', explode(':', $privilege->getIdentity()->getId())[0])[1];

            foreach ($this->manager->getAces($sid, $oid, $fieldName) as $ace) {
                if (!$ace->isGranting()) {
                    // denying ACE is not supported
                    continue;
                }

                $mask = $this->findSimilarMask($masks, $ace->getMask(), $extension);

                // as we have already processed $mask, remove it from $masks collection
                if ($mask !== false) {
                    $this->manager->setFieldPermission($sid, $oid, $fieldName, $mask);
                    $this->removeMask($masks, $mask);
                }
            }

            // check if we have new masks so far, and process them if any
            foreach ($masks as $mask) {
                $this->manager->setFieldPermission($sid, $oid, $fieldName, $mask);
            }
        }

        $this->manager->flush();
    }

    /**
     * {@inheritdoc}
     */
    protected function getPermissionMasks($permissions, AclExtensionInterface $extension, array $maskBuilders)
    {
        // check if there are no full field permissions
        // and add missing to calculate correct masks
        $permissionNames = array_keys($maskBuilders);
        foreach ($permissionNames as $permissionName) {
            /** @var ArrayCollection $permissions */
            if (!$permissions->containsKey($permissionName)) {
                $permissions->add(new AclPermission($permissionName, AccessLevel::SYSTEM_LEVEL));
            }
        }

        return parent::getPermissionMasks($permissions, $extension, $maskBuilders);
    }
}
