<?php

namespace Negromovich\DoctrineRelationsLoader;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManager;
use Doctrine\Common\Persistence\Proxy;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\PersistentCollection;

class RelationsLoader
{
    /** @var EntityManager */
    private $em;

    /** @var IdentifierHolder */
    private $identifierHolder;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
        $this->identifierHolder = new IdentifierHolder();
    }

    /**
     * @param array|object $data
     * @param array|string $relations
     * @return array|object
     */
    public function load($data, $relations)
    {
        if (is_array($data)) {
            $processData = $data;
        } elseif ($data instanceof Collection) {
            $processData = $data->toArray();
        } else {
            $processData = [$data];
        }

        $this->doLoad($processData, $relations);
        $this->loadEntities();
        return $data;
    }

    private function doLoad(array $data, $relations)
    {
        $relations = $this->prepareRelations($relations);

        foreach ($relations as $field => $relation) {
            if (is_array($relation)) {
                $relationData = $this->prepareData($data, $field);
                $class = $this->getDataClass($relationData);
                $this->loadEntitiesByClass($class);
                $this->doLoad($relationData, $relation);
            } else {
                $this->loadRelation($data, $relation);
            }
        }
    }

    private function prepareRelations($relations)
    {
        if (is_array($relations)) {
            foreach ($relations as $parent => $child) {
                if (is_array($child) && !in_array($parent, $relations, true)) {
                    $relations[] = $parent;
                }
            }

            uasort($relations, function($a, $b) {
                $value = ((int)is_array($a) << 1) + ((int)is_array($b));
                switch ($value) {
                    case 0b10:
                        return 1;
                    case 0b01:
                        return -1;
                    case 0b00:
                    case 0b11:
                    default:
                        return 0;
                }
            });
        } else {
            $relations = [$relations];
        }
        return $relations;
    }

    private function prepareData(array $data, $field)
    {
        $class = $this->getDataClass($data);
        $metadata = $this->em->getClassMetadata($class);

        $relationClass = $metadata->getAssociationMapping($field)['targetEntity'];
        $relationMapping = $this->em->getClassMetadata($relationClass);

        $result = [];
        foreach ($data as $row) {
            $relations = $metadata->reflFields[$field]->getValue($row);
            if (!(is_array($relations) || $relations instanceof Collection)) {
                $relations = [$relations];
            }
            foreach ($relations as $relation) {
                $key = implode('~', $relationMapping->getIdentifierValues($relation));
                $result[$key] = $relation;
            }
        }

        return array_values($result);
    }

    private function getDataClass(array $data)
    {
        $class = get_class(reset($data));
        return str_replace('Proxies\__CG__\\', '', $class);
    }

    private function loadRelation(array $data, $relation)
    {
        $class = $this->getDataClass($data);
        $metadata = $this->em->getClassMetadata($class);

        $relationMapping = $metadata->getAssociationMapping($relation);
        $relationClass = $relationMapping['targetEntity'];
        $relationMetadata = $this->em->getClassMetadata($relationClass);


        if ($relationMapping['type'] & ClassMetadataInfo::TO_ONE) {

            foreach ($data as $row) {
                if ($row instanceof Proxy) {
                    $row->__load();
                }
                $relationEntity = $metadata->reflFields[$relation]->getValue($row);
                if ($relationEntity instanceof Proxy && !$relationEntity->__isInitialized()) {
                    $identifier = $relationMetadata->getIdentifierValues($relationEntity);
                    $this->identifierHolder->addIdentifier($relationClass, $identifier);
                }
            }

        } else {

            foreach ($data as $row) {
                if ($row instanceof Proxy) {
                    $row->__load();
                }
                $rowIdentifier = $metadata->getIdentifierValues($row);
                $identifier = [$relationMapping['mappedBy'] => reset($rowIdentifier)];
                $this->identifierHolder->addIdentifier($relationClass, $identifier);
            }

            $orderBy = isset($relationMapping['orderBy']) ? $relationMapping['orderBy'] : null;
            $relationEntities = $this->loadEntitiesByClass($relationClass, $orderBy);
            foreach ($relationEntities as $relationEntity) {
                $parentEntity = $relationMetadata->reflFields[$relationMapping['mappedBy']]->getValue($relationEntity);
                $collection = $metadata->reflFields[$relation]->getValue($parentEntity);
                if ($collection instanceof PersistentCollection) {
                    $collection->unwrap()->add($relationEntity);
                }
            }

            foreach ($data as $row) {
                $collection = $metadata->reflFields[$relation]->getValue($row);
                if ($collection instanceof PersistentCollection) {
                    $collection->setInitialized(true);
                    $collection->takeSnapshot();
                }
            }

        }
    }

    private function loadEntities()
    {
        foreach ($this->identifierHolder->getEntityClasses() as $entityClass) {
            $this->loadEntitiesByClass($entityClass);
        }
    }

    private function loadEntitiesByClass($entityClass, array $orderBy = null)
    {
        $identifiers = $this->identifierHolder->getIdentifiers($entityClass);
        $countIdentifiers = count($identifiers);
        if ($countIdentifiers < 1) {
            return null;
        }
        if ($countIdentifiers === 1) {
            $key = key($identifiers);
            $identifiers[$key] = array_unique($identifiers[$key]);
        }

        $repository = $this->em->getRepository($entityClass);
        if (count($identifiers) > 1) {
            $i = 0;
            $qb = $repository->createQueryBuilder('e');
            foreach ($identifiers as $identifier) {
                $statements = [];
                foreach ($identifier as $field => $value) {
                    $statements[] = $qb->expr()->eq('e.' . $field, '?' . ++$i);
                    $qb->setParameter($i, $value);
                }
                $qb->orWhere(call_user_func_array([$qb->expr(), 'andX'], $statements));
            }
            $result = $qb->getQuery()->getResult();
        } elseif ($orderBy !== null) {
            $i = 0;
            $qb = $repository->createQueryBuilder('e');
            foreach ($identifiers as $field => $values) {
                $qb->andWhere($qb->expr()->in('e.' . $field, '?' . $i));
                $qb->setParameter($i, $values);
            }
            foreach ($orderBy as $field => $order) {
                $qb->addOrderBy('e.' . $field, $order);
            }
            $result = $qb->getQuery()->getResult();
        } else {
            $result = $repository->findBy($identifiers);
        }

        $this->identifierHolder->clearIdentifiers($entityClass);

        return $result;
    }
}
