<?php

namespace Negromovich\DoctrineRelationsLoader;

class IdentifierHolder
{
    protected $identifiers = [];

    public function getEntityClasses()
    {
        return array_keys($this->identifiers);
    }

    public function addIdentifier($entityClass, array $identifier)
    {
        if (!isset($this->identifiers[$entityClass])) {
            $this->identifiers[$entityClass] = [];
        }

        if (count($identifier) > 1) {
            $key = implode('|', $identifier);
            $this->identifiers[$entityClass][$key] = $identifier;
        } else {
            foreach ($identifier as $field => $value) {
                if (!isset($this->identifiers[$entityClass][$field])) {
                    $this->identifiers[$entityClass][$field] = [];
                }
                $this->identifiers[$entityClass][$field][] = $value;
            }
        }

        return $this;
    }

    public function getIdentifiers($entityClass = null)
    {
        if ($entityClass === null) {
            return $this->identifiers;
        } elseif (isset($this->identifiers[$entityClass])) {
            return $this->identifiers[$entityClass];
        } else {
            return [];
        }
    }

    public function clearIdentifiers($entityClass = null)
    {
        if ($entityClass === null) {
            $this->identifiers = [];
        } else {
            unset($this->identifiers[$entityClass]);
        }
        return $this;
    }
}
