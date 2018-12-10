<?php declare(strict_types=1);

namespace Shopware\Core\Framework\DataAbstractionLayer\Dbal;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\AssociationInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslatedField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldSerializer\FieldSerializerRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Flag\Extension;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Flag\Inherited;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Allows to hydrate database values into struct objects.
 */
class EntityHydrator
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var FieldSerializerRegistry
     */
    private $fieldHandler;

    private $objects = [];

    public function __construct(SerializerInterface $serializer, FieldSerializerRegistry $fieldHandler)
    {
        $this->serializer = $serializer;
        $this->fieldHandler = $fieldHandler;
    }

    public function hydrate(string $entity, string $definition, array $rows, string $root): array
    {
        /** @var EntityDefinition|string $definition */
        $collection = [];
        $this->objects = [];

        foreach ($rows as $row) {
            $collection[] = $this->hydrateEntity(new $entity(), $definition, $row, $root);
        }

        return $collection;
    }

    private function hydrateEntity(Entity $entity, string $definition, array $row, string $root): Entity
    {
        /** @var EntityDefinition $definition */
        $fields = $definition::getFields()->getElements();

        $idProperty = $root . '.id';

        $objectCacheKey = null;

        if (isset($row[$idProperty])) {
            $objectCacheKey = $definition::getEntityName() . '::' . bin2hex($row[$idProperty]);

            if (isset($this->objects[$objectCacheKey])) {
                return $this->objects[$objectCacheKey];
            }
        }

        $data = [];
        $associations = [];
        $inheritedFields = [];
        $inherited = new ArrayStruct();
        $translated = new ArrayStruct();

        /** @var Field $field */
        foreach ($fields as $field) {
            $propertyName = $field->getPropertyName();

            $originalKey = $root . '.' . $propertyName;

            $isInherited = $field->is(Inherited::class);

            if ($isInherited && $field instanceof AssociationInterface) {
                $inheritedFields[] = $field;
            }

            //collect to one associations, this will be hydrated after loop
            if ($field instanceof ManyToOneAssociationField) {
                $accessor = $root . '.' . $propertyName . '.id';

                if (isset($row[$accessor])) {
                    $associations[$propertyName] = $field;
                }

                continue;
            }

            if (!array_key_exists($originalKey, $row)) {
                continue;
            }

            $value = $row[$originalKey];
            //remove internal .inherited key which used to detect if a inherited field is selected by parent or child
            if ($isInherited) {
                $inheritedKey = '_' . $originalKey . '.inherited';

                if (isset($row[$inheritedKey])) {
                    $inherited->set($propertyName, (bool) $row[$inheritedKey]);
                }
            }

            //in case of a translated field, remove .translated element which is selected to detect if the value is translated in current language or contains the fallback
            if ($field instanceof TranslatedField) {
                $translationKey = '_' . $originalKey . '.translated';

                if (isset($row[$translationKey])) {
                    $translated->set($propertyName, (bool) $row[$translationKey]);
                }
            }

            //scalar data values can be casted directly
            if (!$field instanceof AssociationInterface) {
                //reduce data set for nested calls
                $data[$propertyName] = $this->fieldHandler->decode($field, $value);
                continue;
            }

            //many to many fields contains a group concat id value in the selection, this will be stored in an internal extension to collect them later
            if ($field instanceof ManyToManyAssociationField) {
                $property = $root . '.' . $propertyName;

                $ids = explode('||', (string) $row[$property]);
                $ids = array_filter($ids);
                $ids = array_map('strtolower', $ids);

                $extension = $entity->getExtension(EntityReader::MANY_TO_MANY_EXTENSION_STORAGE);
                if (!$extension) {
                    $extension = new ArrayStruct([]);
                    $entity->addExtension(EntityReader::MANY_TO_MANY_EXTENSION_STORAGE, $extension);
                }

                if ($extension instanceof ArrayStruct) {
                    $extension->set($propertyName, $ids);
                }
            }
        }

        /** @var AssociationInterface[] $associations */
        foreach ($associations as $property => $field) {
            $reference = $field->getReferenceClass();

            /** @var EntityDefinition $reference */
            $structClass = $reference::getStructClass();

            $hydrated = $this->hydrateEntity(
                new $structClass(),
                $field->getReferenceClass(),
                $row,
                $root . '.' . $property
            );

            /** @var Field $field */
            if ($field->is(Extension::class)) {
                $entity->addExtension($property, $hydrated);
            } else {
                $data[$property] = $hydrated;
            }
        }

        $entity->assign($data);

        //write object cache key to prevent multiple hydration for the same entity
        if ($objectCacheKey !== null) {
            $this->objects[$objectCacheKey] = $entity;
        }

        if ($definition::isInheritanceAware()) {
            /** @var Field $association */
            foreach ($inheritedFields as $association) {
                if (!$association instanceof ManyToManyAssociationField && !$association instanceof OneToManyAssociationField) {
                    continue;
                }

                $inherited->set($association->getPropertyName(), $this->isInherited($definition, $row, $association));
            }

            $entity->addExtension('inherited', $inherited);
        }

        if ($definition::getTranslationDefinitionClass()) {
            $entity->addExtension('translated', $translated);
        }

        return $entity;
    }

    private function isInherited(string $definition, array $row, AssociationInterface $association): bool
    {
        /* @var string|EntityDefinition $definition */
        $idField = 'id';
        if ($association instanceof ManyToOneAssociationField) {
            $idField = $association->getStorageName();
        }

        $idField = $definition::getFields()->getByStorageName($idField);

        $joinField = '_' . $definition::getEntityName() . '.' . $association->getPropertyName() . '.inherited';
        $idField = $definition::getEntityName() . '.' . $idField->getPropertyName();

        if (!isset($row[$joinField])) {
            return false;
        }

        $idValue = $row[$idField];
        $joinValue = $row[$joinField];

        return $idValue !== $joinValue;
    }
}
