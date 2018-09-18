<?php

namespace Phalcon\Mvc\Model\Behavior;

use Phalcon\Mvc\Model;
use Phalcon\Mvc\ModelInterface;
use Phalcon\Mvc\Model\Behavior;
use Phalcon\Mvc\Model\Exception;
use Phalcon\Db\AdapterInterface;
use Phalcon\Mvc\Model\BehaviorInterface;
use Phalcon\Mvc\Model\ResultsetInterface;
use Phalcon\Paginator\Adapter\QueryBuilder;
use Phalcon\Traits\EventManagerAwareTrait;

class NestedSet extends Behavior implements BehaviorInterface
{
    use EventManagerAwareTrait;

    const EVT_TYPE_QUERY = 'nestedset';

    const EVT_DESCENDANTS = 'Descendants';
    const EVT_ANCESTORS = 'Ancestors';
    const EVT_PARENT = 'Parent';
    const EVT_PREV = 'Prev';
    const EVT_NEXT = 'Next';
    const EVT_ROOTS = 'Roots';

    /**
     * @var AdapterInterface|null
     */
    private $db;

    /**
     * @var ModelInterface|null
     */
    private $owner;

    private $hasManyRoots = false;
    private $rootAttribute = 'root';
    private $leftAttribute = 'lft';
    private $rightAttribute = 'rgt';
    private $levelAttribute = 'level';
    private $primaryKey = 'id';
    private $ignoreEvent = false;
    private $deleted = false;

    /**
     * Binding query params
     * @var array $bind
     */
    private $bind;

    /**
     * Hierarchical array
     * @var array $resultSet
     */
    private $resultSet;

    public function __construct($options = null)
    {
        if (isset($options['db']) && $options['db'] instanceof AdapterInterface) {
            $this->db = $options['db'];
        }

        if (isset($options['hasManyRoots'])) {
            $this->hasManyRoots = (bool) $options['hasManyRoots'];
        }

        if (isset($options['rootAttribute'])) {
            $this->rootAttribute = $options['rootAttribute'];
        }

        if (isset($options['leftAttribute'])) {
            $this->leftAttribute = $options['leftAttribute'];
        }

        if (isset($options['rightAttribute'])) {
            $this->rightAttribute = $options['rightAttribute'];
        }

        if (isset($options['levelAttribute'])) {
            $this->levelAttribute = $options['levelAttribute'];
        }

        if (isset($options['primaryKey'])) {
            $this->primaryKey = $options['primaryKey'];
        }

        $this->bind = [];
    }

    /**
     * @param string $eventType
     * @param ModelInterface $model
     * @throws Exception
     */
    public function notify($eventType, ModelInterface $model)
    {
        switch ($eventType) {
            case 'beforeCreate':
            case 'beforeDelete':
            case 'beforeUpdate':
                if (!$this->ignoreEvent) {
                    throw new Exception(
                        sprintf(
                            'You should not use %s:%s when %s attached. Use the methods of behavior.',
                            get_class($model),
                            $eventType,
                            __CLASS__
                        )
                    );
                }
                break;
        }
    }

    /**
     * Calls a method when it's missing in the model
     *
     * @param ModelInterface $model
     * @param string $method
     * @param null $arguments
     * @return mixed|null|string
     * @throws Exception
     */
    public function missingMethod(ModelInterface $model, $method, $arguments = null)
    {
        if (!method_exists($this, $method)) {
            return null;
        }

        $this->getDbHandler($model);
        $this->setOwner($model);

        return call_user_func_array([$this, $method], $arguments);
    }

    /**
     * @return ModelInterface
     */
    public function getOwner()
    {
        if (!$this->owner instanceof ModelInterface) {
            trigger_error("Owner isn't a valid ModelInterface instance.", E_USER_WARNING);
        }

        return $this->owner;
    }

    public function setOwner(ModelInterface $owner)
    {
        $this->owner = $owner;
    }

    public function getIsNewRecord()
    {
        return $this->getOwner()->getDirtyState() == Model::DIRTY_STATE_TRANSIENT;
    }

    /**
     * Returns if the current node is deleted.
     *
     * @return boolean whether the node is deleted.
     */
    public function getIsDeletedRecord()
    {
        return $this->deleted;
    }

    /**
     * Sets if the current node is deleted.
     *
     * @param boolean $value whether the node is deleted.
     */
    public function setIsDeletedRecord($value)
    {
        $this->deleted = $value;
    }

    /**
     * Determines if node is leaf.
     *
     * @return boolean whether the node is leaf.
     */
    public function isLeaf()
    {
        $owner = $this->getOwner();

        return $owner->{$this->rightAttribute} - $owner->{$this->leftAttribute} === 1;
    }

    /**
     * Determines if node is root.
     *
     * @return boolean whether the node is root.
     */
    public function isRoot()
    {
        return $this->getOwner()->{$this->leftAttribute} == 1;
    }

    /**
     * Determines if node is descendant of subject node.
     *
     * @param  \Phalcon\Mvc\ModelInterface $subj the subject node.
     *
     * @return boolean                     whether the node is descendant of subject node.
     */
    public function isDescendantOf($subj)
    {
        $owner = $this->getOwner();
        $result = ($owner->{$this->leftAttribute} > $subj->{$this->leftAttribute})
            && ($owner->{$this->rightAttribute} < $subj->{$this->rightAttribute});

        if ($this->hasManyRoots) {
            $result = $result && ($owner->{$this->rootAttribute} === $subj->{$this->rootAttribute});
        }

        return $result;
    }

    /**
     * Named scope. Gets descendants for node.
     *
     * @param int $depth the depth.
     * @param boolean $addSelf If TRUE - parent node will be added to result set.
     * @return NestedSet
     */
    public function descendants($depth = null, $addSelf = false)
    {
        $owner = $this->getOwner();

        $query = $owner::query()
            ->where($this->leftAttribute . '>' . ($addSelf ? '=' : null) . $owner->{$this->leftAttribute})
            ->andWhere($this->rightAttribute . '<' . ($addSelf ? '=' : null) . $owner->{$this->rightAttribute})
            ->orderBy($this->leftAttribute);

        if ($depth !== null) {
            $query = $query->andWhere($this->levelAttribute . '<=' . ($owner->{$this->levelAttribute} + $depth));
        }

        if ($this->hasManyRoots) {
            $query = $query->andWhere($this->rootAttribute . '= :rootAttr:');
            $this->bind['rootAttr'] = $owner->{$this->rootAttribute};
        }

        $this->fire(
            self::EVT_TYPE_QUERY . ':before' . self::EVT_DESCENDANTS,
            $query,
            [
                'owner' => $owner,
                'depth' => $depth,
                'addSelf' => $addSelf
            ]
        );

        $query->bind($this->bind);

        $this->resultSet =  $query->execute();

        return $this;
    }

    /**
     * Named scope. Gets children for node (direct descendants only).
     *
     * @param null $depth
     * @param bool $addSelf
     * @return ResultsetInterface
     */
    public function children($depth = null, $addSelf = false)
    {
        return $this->descendants($depth, $addSelf);
    }

    /**
     * Named scope. Gets ancestors for node.
     *
     * @param  int $depth the depth.
     * @return NestedSet
     */
    public function ancestors($depth = null)
    {
        $owner = $this->getOwner();

        $query = $owner::query()
            ->where($this->leftAttribute . '<' . $owner->{$this->leftAttribute})
            ->andWhere($this->rightAttribute . '>' . $owner->{$this->rightAttribute})
            ->orderBy($this->leftAttribute);

        if ($depth !== null) {
            $query = $query->andWhere($this->levelAttribute . '>=' . ($owner->{$this->levelAttribute} - $depth));
        }

        if ($this->hasManyRoots) {
            $query = $query->andWhere($this->rootAttribute . '= :rootAttr:');
            $this->bind['rootAttr'] = $owner->{$this->rootAttribute};
        }

        $this->fire(
            self::EVT_TYPE_QUERY . ':before' . self::EVT_ANCESTORS,
            $query,
            [
                'owner' => $owner,
                'depth' => $depth
            ]
        );

        $query->bind($this->bind);

        $this->resultSet = $query->execute();

        return $this;
    }

    /**
     * Named scope. Gets root node(s).
     *
     * @return ResultsetInterface
     */
    public function roots()
    {
        $owner = $this->getOwner();

        $query = $owner::query()
            ->andWhere($this->leftAttribute . ' = 1')
        ;

        $this->fire(
            self::EVT_TYPE_QUERY . ':before' . self::EVT_ROOTS,
            $query,
            [
                'owner' => $owner
            ]
        );

        return $owner::find($query->getParams());
    }

    /**
     * Named scope. Gets parent of node.
     *
     * @return \Phalcon\Mvc\ModelInterface
     */
    public function parent()
    {
        $owner = $this->getOwner();

        $query = $owner::query()
            ->where($this->leftAttribute . '<' . $owner->{$this->leftAttribute})
            ->andWhere($this->rightAttribute . '>' . $owner->{$this->rightAttribute})
            ->orderBy($this->rightAttribute)
            ->limit(1);

        if ($this->hasManyRoots) {
            $query = $query->andWhere($this->rootAttribute . '= :rootAttr:');
            $this->bind['rootAttr'] = $owner->{$this->rootAttribute};
        }

        $this->fire(
            self::EVT_TYPE_QUERY . ':before' . self::EVT_PARENT,
            $query,
            [
                'owner' => $owner
            ]
        );

        $query->bind($this->bind);

        return $query->execute()->getFirst();
    }

    /**
     * Named scope. Gets previous sibling of node.
     *
     * @return ModelInterface
     */
    public function prev()
    {
        $owner = $this->getOwner();
        $query = $owner::query()
            ->where($this->rightAttribute . '=' . ($owner->{$this->leftAttribute} - 1));

        if ($this->hasManyRoots) {
            $query = $query->andWhere($this->rootAttribute . '= :rootAttr:');
            $this->bind['rootAttr'] = $owner->{$this->rootAttribute};
        }

        $this->fire(
            self::EVT_TYPE_QUERY . ':before' . self::EVT_PREV,
            $query,
            [
                'owner' => $owner
            ]
        );

        $query->bind($this->bind);

        return $query->execute()->getFirst();
    }

    /**
     * Named scope. Gets next sibling of node.
     *
     * @return ModelInterface
     */
    public function next()
    {
        $owner = $this->getOwner();
        $query = $owner::query()
            ->where($this->leftAttribute . '=' . ($owner->{$this->rightAttribute} + 1));

        if ($this->hasManyRoots) {
            $query = $query->andWhere($this->rootAttribute . '= :rootAttr:');
            $this->bind['rootAttr'] = $owner->{$this->rootAttribute};
        }

        $this->fire(
            self::EVT_TYPE_QUERY . ':before' . self::EVT_NEXT,
            $query,
            [
                'owner' => $owner
            ]
        );

        $query->bind($this->bind);

        return $query->execute()->getFirst();
    }

    /**
     * Prepends node to target as first child.
     *
     * @param  ModelInterface $target the target
     * @param  array $attributes List of attributes.
     * @return boolean
     * @throws \Exception
     */
    public function prependTo(ModelInterface $target, array $attributes = null)
    {
        // Re-search $target
        $target = $target::findFirst($target->{$this->primaryKey});

        return $this->addNode($target, $target->{$this->leftAttribute} + 1, 1, $attributes);
    }

    /**
     * Prepends target to node as first child.
     *
     * @param  ModelInterface $target the target.
     * @param  array $attributes list of attributes.
     * @return boolean
     */
    public function prepend(ModelInterface $target, array $attributes = null)
    {
        return $target->prependTo($this->getOwner(), $attributes);
    }

    /**
     * Appends node to target as last child.
     *
     * @param  ModelInterface $target the target.
     * @param  array $attributes list of attributes.
     * @return boolean
     * @throws \Exception
     */
    public function appendTo(ModelInterface $target, array $attributes = null)
    {
        // Re-search $target
        $target = $target::findFirst([
            'conditions' => $this->primaryKey . '= :id:',
            'bind' => ['id' => $target->{$this->primaryKey}]
        ]);

        return $this->addNode($target, $target->{$this->rightAttribute}, 1, $attributes);
    }

    /**
     * Appends target to node as last child.
     *
     * @param  ModelInterface $target the target.
     * @param  array $attributes list of attributes.
     * @return boolean
     */
    public function append(ModelInterface $target, array $attributes = null)
    {
        /** @var NestedSet $target */
        return $target->appendTo($this->getOwner(), $attributes);
    }

    /**
     * Inserts node as previous sibling of target.
     *
     * @param ModelInterface $target the target.
     * @param  array $attributes list of attributes.
     * @return boolean
     * @throws \Exception
     */
    public function insertBefore(ModelInterface $target, array $attributes = null)
    {
        return $this->addNode($target, $target->{$this->leftAttribute}, 0, $attributes);
    }

    /**
     * Inserts node as next sibling of target.
     *
     * @param  ModelInterface $target the target.
     * @param  array $attributes list of attributes.
     * @return boolean
     */
    public function insertAfter(ModelInterface $target, array $attributes = null)
    {
        return $this->addNode($target, $target->{$this->rightAttribute} + 1, 0, $attributes);
    }

    /**
     * Move node as previous sibling of target.
     *
     * @param  ModelInterface $target the target.
     * @return boolean
     */
    public function moveBefore(ModelInterface $target)
    {
        return $this->moveNode($target, $target->{$this->leftAttribute}, 0);
    }

    /**
     * Move node as next sibling of target.
     *
     * @param  ModelInterface $target the target.
     * @return boolean
     */
    public function moveAfter(ModelInterface $target)
    {
        return $this->moveNode($target, $target->{$this->rightAttribute} + 1, 0);
    }

    /**
     * Move node as first child of target.
     *
     * @param  ModelInterface $target the target.
     * @return boolean
     */
    public function moveAsFirst(ModelInterface $target)
    {
        return $this->moveNode($target, $target->{$this->leftAttribute} + 1, 1);
    }

    /**
     * Move node as last child of target.
     *
     * @param  ModelInterface $target the target.
     * @return boolean
     */
    public function moveAsLast(ModelInterface $target)
    {
        return $this->moveNode($target, $target->{$this->rightAttribute}, 1);
    }

    /**
     * Move node as new root.
     *
     * @return boolean
     * @throws Exception
     */
    public function moveAsRoot()
    {
        $owner = $this->getOwner();

        if (!$this->hasManyRoots) {
            throw new Exception('Many roots mode is off.');
        }

        if ($this->getIsNewRecord()) {
            throw new Exception('The node should not be new record.');
        }

        if ($this->getIsDeletedRecord()) {
            throw new Exception('The node should not be deleted.');
        }

        if ($owner->isRoot()) {
            throw new Exception('The node already is root node.');
        }

        $this->db->begin();

        $left = $owner->{$this->leftAttribute};
        $right = $owner->{$this->rightAttribute};
        $levelDelta = 1 - $owner->{$this->levelAttribute};
        $delta = 1 - $left;

        $condition = $this->leftAttribute . '>=' . $left . ' AND ';
        $condition .= $this->rightAttribute . '<=' . $right . ' AND ';
        $condition .= $this->rootAttribute . '= :rootAttr:';

        $this->bind['rootAttr'] = $owner->{$this->rootAttribute};

        $this->ignoreEvent = true;
        $result = $owner::find([
            'conditions' => $condition,
            'bind' => $this->bind
        ]);
        foreach ($result as $i) {
            $arr = [
                $this->leftAttribute => $i->{$this->leftAttribute} + $delta,
                $this->rightAttribute => $i->{$this->rightAttribute} + $delta,
                $this->levelAttribute => $i->{$this->levelAttribute} + $levelDelta,
                $this->rootAttribute => $owner->{$this->primaryKey}
            ];
            if ($i->update($arr) == false) {
                $this->db->rollback();
                $this->ignoreEvent = false;

                return false;
            }
        }
        $this->ignoreEvent = false;

        $this->shiftLeftRight($right + 1, $left - $right - 1);

        $this->db->commit();

        return true;
    }

    /**
     * Create root node if multiple-root tree mode. Update node if it's not new.
     *
     * @param  array $attributes list of attributes.
     * @param  array $whiteList whether to perform validation.
     * @return boolean
     * @throws Exception
     */
    public function saveNode(array $attributes = null, array $whiteList = null)
    {
        $owner = $this->getOwner();
        $this->ignoreEvent = true;
        if ($this->getIsNewRecord()) {
            $result = $this->makeRoot($attributes, $whiteList);
        } else {
            $result = $owner->update($attributes, $whiteList);
        }
        $this->ignoreEvent = false;
        return $result;
    }

    /**
     * Deletes node and it's descendants.
     *
     * @return boolean
     * @throws Exception
     */
    public function deleteNode()
    {
        $owner = $this->getOwner();

        if ($this->getIsNewRecord()) {
            throw new Exception('The node cannot be deleted because it is new.');
        }

        if ($this->getIsDeletedRecord()) {
            throw new Exception('The node cannot be deleted because it is already deleted.');
        }

        $this->db->begin();

        if ($owner->isLeaf()) {
            $this->ignoreEvent = true;
            if ($owner->delete() == false) {
                $this->db->rollback();
                $this->ignoreEvent = false;

                return false;
            }
        } else {
            $condition = $this->leftAttribute . '>=' . $owner->{$this->leftAttribute} . ' AND ';
            $condition .= $this->rightAttribute . '<=' . $owner->{$this->rightAttribute};

            if ($this->hasManyRoots) {
                $condition .= ' AND ' . $this->rootAttribute . '= :rootAttr:';
                $this->bind['rootAttr'] = $owner->{$this->rootAttribute};
            }

            $this->ignoreEvent = true;
            $result = $owner::find([
                'conditions' => $condition,
                'bind' => $this->bind
            ]);
            foreach ($result as $i) {
                if ($i->delete() == false) {
                    $this->db->rollback();
                    $this->ignoreEvent = false;

                    return false;
                }
            }
        }

        $key = $owner->{$this->rightAttribute} + 1;
        $delta = $owner->{$this->leftAttribute} - $owner->{$this->rightAttribute} - 1;
        $this->shiftLeftRight($key, $delta);
        $this->ignoreEvent = false;

        $this->db->commit();

        return true;
    }

    /**
     * Gets DB handler.
     *
     * @param ModelInterface $model
     * @return AdapterInterface
     * @throws Exception
     */
    private function getDbHandler(ModelInterface $model)
    {
        if (!$this->db instanceof AdapterInterface) {
            if ($model->getDi()->has('db')) {
                $db = $model->getDi()->getShared('db');
                if (!$db instanceof AdapterInterface) {
                    throw new Exception('The "db" service which was obtained from DI is invalid adapter.');
                }
                $this->db = $db;
            } else {
                throw new Exception('Undefined database handler.');
            }
        }

        return $this->db;
    }

    /**
     * @param  ModelInterface $target
     * @param  int $key
     * @param  int $levelUp
     *
     * @return boolean
     * @throws Exception
     */
    private function moveNode(ModelInterface $target, $key, $levelUp)
    {
        $owner = $this->getOwner();

        if (!$target) {
            throw new \Phalcon\Mvc\Model\Exception('Target node is not defined.');
        }

        if ($this->getIsNewRecord()) {
            throw new \Phalcon\Mvc\Model\Exception('The node should not be new record.');
        }

        if ($this->getIsDeletedRecord()) {
            throw new \Phalcon\Mvc\Model\Exception('The node should not be deleted.');
        }

        if ($target->getIsDeletedRecord()) {
            throw new \Phalcon\Mvc\Model\Exception('The target node should not be deleted.');
        }

        if ($owner == $target) {
            throw new \Phalcon\Mvc\Model\Exception('The target node should not be self.');
        }

        if ($target->isDescendantOf($owner)) {
            throw new \Phalcon\Mvc\Model\Exception('The target node should not be descendant.');
        }

        if (!$levelUp && $target->isRoot()) {
            throw new \Phalcon\Mvc\Model\Exception('The target node should not be root.');
        }

        $left = $owner->{$this->leftAttribute};
        $right = $owner->{$this->rightAttribute};
        $root = $owner->{$this->rootAttribute};
        $tLeft = $target->{$this->leftAttribute};
        $tRight = $target->{$this->rightAttribute};
        $tRoot = $target->{$this->rootAttribute};
        $delta = $right - $left + 1;
        $levelDelta = $target->{$this->levelAttribute} + $levelUp - $owner->{$this->levelAttribute};

        $owner->getDI()->getDb()->begin();

        /** @var Model\Query\Builder $query */
        $query = $owner::query()
            ->columns($this->primaryKey)
            ->where($this->leftAttribute . '>=' . $left)
            ->andWhere($this->rightAttribute . '<=' . $right);

        $this->bind['rootAttr'] = $root;
        $this->bind['tRootAttr'] = $tRoot;
        if ($this->hasManyRoots) {
            $query = $query->andWhere($this->rootAttribute . '= :rootAttr:', ['rootAttr' => $this->bind['rootAttr']]);
        }

        $data = $query->execute();

        $ownerTreePks = array();
        foreach ($data as $row) {
            $ownerTreePks[] = $row->{$this->primaryKey};
        }

        // --- moving in own tree
        if ($root === $tRoot) {
            // moving from left to right
            if ($key > $right) {
                foreach (array($this->leftAttribute, $this->rightAttribute) as $attribute) {

                    if ($levelUp) {
                        $condition = $attribute . '<=' . ($key - $levelUp);
                    } else {
                        $condition = $attribute . '<' . ($key);
                    }
                    $condition .= ' AND ' . $attribute . '>' . $right;
                    if ($this->hasManyRoots) {
                        $condition .= ' AND ' . $this->rootAttribute . ' = :tRootAttr:';
                    }
                    $result = $owner::find([
                        'conditions' => $condition,
                        'bind' => ['tRootAttr' => $this->bind['tRootAttr']]
                    ]);
                    if ($result) {
                        foreach ($result as $item) {
                            $item->saveNode([
                                $attribute => $item->{'get'.ucfirst($attribute)}() - $delta
                            ]);
                        }
                    }
                }
                $delta = $key - $right - $levelUp;
                if (!$levelUp) {
                    $delta = $delta - 1;
                }
            }
            // moving from right to left
            elseif ($key < $left) {
                foreach (array($this->leftAttribute, $this->rightAttribute) as $attribute) {
                    $condition = $attribute . '>=' . $key;
                    $condition .= ' AND ' . $attribute . '<' . $left;
                    if ($this->hasManyRoots) {
                        $condition .= ' AND ' . $this->rootAttribute . '= :tRootAttr:';
                    }
                    $result = $owner::find([
                        'conditions' => $condition,
                        'bind' => ['tRootAttr' => $this->bind['tRootAttr']]
                    ]);
                    if ($result) {
                        foreach ($result as $item) {
                            $item->saveNode([
                                $attribute => $item->{'get'.ucfirst($attribute)}() + $delta
                            ]);
                        }
                    }
                }
                $delta = $key - $left;
            }
            // no change
            else {
                $owner->getDI()->getDb()->rollback();
                return true;
            }
            // update owner tree
            $condition = $this->primaryKey . ' IN ({ownerTreePks:array})';
            $result = $owner::find([
                'conditions' => $condition,
                'bind' => ['ownerTreePks' => $ownerTreePks]
            ]);
            if ($result) {
                foreach ($result as $item) {
                    if (!$item->saveNode([
                        $this->leftAttribute => $item->{'get'.ucfirst($this->leftAttribute)}() + $delta,
                        $this->rightAttribute => $item->{'get'.ucfirst($this->rightAttribute)}() + $delta,
                        $this->levelAttribute => $item->{'get'.ucfirst($this->levelAttribute)}() + $levelDelta
                        ])
                    ) {
                        $owner->getDI()->getDb()->rollback();
                        return false;
                    }
                }
                $owner->{$this->leftAttribute} = $left + $delta;
                $owner->{$this->rightAttribute} = $right + $delta;
                $owner->{$this->levelAttribute} = $owner->{$this->levelAttribute} + $levelDelta;
                $owner->getDI()->getDb()->commit();
                return true;
            }
        }

        // --- moving to another tree
        // make place for new elements
        foreach (array($this->leftAttribute, $this->rightAttribute) as $attribute) {
            $condition = $attribute . '>=' . $key;
            $condition .= ' AND ' . $this->rootAttribute . '= :tRootAttr:';
            $result = $owner::find([
                'conditions' => $condition,
                'bind' => ['tRootAttr' => $this->bind['tRootAttr']]
            ]);
            if ($result) {
                foreach ($result as $item) {
                    $item->saveNode([
                        $attribute => $item->{'get'.ucfirst($attribute)}() + $delta
                    ]);
                }
            }
        }
        // closing the gap on old tree
        if (!$owner->isRoot()) {
            foreach (array($this->leftAttribute, $this->rightAttribute) as $attribute) {
                $condition = $attribute . '>' . $right;
                $condition .= ' AND ' . $this->rootAttribute . '= :rootAttr:';
                $result = $owner::find([
                    'conditions' => $condition,
                    'bind' => ['rootAttr' => $this->bind['rootAttr']]
                ]);
                if ($result) {
                    foreach ($result as $item) {
                        $item->saveNode([
                            $attribute => $item->{'get'.ucfirst($attribute)}() - $delta
                        ]);
                    }
                }
            }
        }
        // update owner tree
        $delta = $key - $left;
        $condition = $this->primaryKey . ' IN ({ownerTreePks:array})';
        $result = $owner::find([
            'conditions' => $condition,
            'bind' => ['ownerTreePks' => $ownerTreePks]
        ]);
        if ($result) {
            foreach ($result as $item) {
                if (!$item->saveNode([
                    $this->rootAttribute => $tRoot,
                    $this->leftAttribute = $this->leftAttribute + $delta,
                    $this->rightAttribute = $this->rightAttribute + $delta,
                    $this->levelAttribute = $this->leftAttribute + $levelDelta
                    ])
                ) {
                    $owner->getDI()->getDb()->rollback();
                    return false;
                }
            }
            $owner->{$this->rootAttribute} = $tRoot;
            $owner->{$this->leftAttribute} = $left + $delta;
            $owner->{$this->rightAttribute} = $right + $delta;
            $owner->{$this->levelAttribute} = $owner->{$this->levelAttribute} + $levelDelta;
            $owner->getDI()->getDb()->commit();
            return true;
        }
    }

    /**
     * @param int $key
     * @param int $delta
     * @param ModelInterface $model
     * @return boolean
     */
    private function shiftLeftRight($key, $delta, ModelInterface $model = null)
    {
        $owner = $model ?: $this->getOwner();

        foreach ([$this->leftAttribute, $this->rightAttribute] as $attribute) {
            $condition = $attribute . '>=' . $key;

            if ($this->hasManyRoots) {
                $condition .= ' AND ' . $this->rootAttribute . '= :rootAttr:';
                $this->bind['rootAttr'] = $owner->{$this->rootAttribute};
            }

            $result = $owner::find([
                'conditions' => $condition,
                'bind' => $this->bind
            ]);

            foreach ($result as $i) {
                /** @var ModelInterface $i */
                if ($i->update([$attribute => $i->{$attribute} + $delta]) == false) {
                    $this->db->rollback();
                    $this->ignoreEvent = false;

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  ModelInterface|NestedSet $target
     * @param  int $key
     * @param  int $levelUp
     * @param  array $attributes
     *
     * @return boolean
     * @throws \Exception
     */
    private function addNode(ModelInterface $target, $key, $levelUp, array $attributes = null)
    {
        $owner = $this->getOwner();

        if (!$this->getIsNewRecord()) {
            throw new Exception('The node cannot be inserted because it is not new.');
        }

        if ($this->getIsDeletedRecord()) {
            throw new Exception('The node cannot be inserted because it is deleted.');
        }

        if ($target->getIsDeletedRecord()) {
            throw new Exception('The node cannot be inserted because target node is deleted.');
        }

        if ($owner == $target) {
            throw new Exception('The target node should not be self.');
        }

        if (!$levelUp && $target->isRoot()) {
            throw new Exception('The target node should not be root.');
        }

        if ($this->hasManyRoots) {
            $owner->{$this->rootAttribute} = $target->{$this->rootAttribute};
        }

        $db = $this->getDbHandler($owner);
        $db->begin();

        try {
            $this->ignoreEvent = true;
            $this->shiftLeftRight($key, 2);
            $this->ignoreEvent = false;

            $owner->{$this->leftAttribute} = $key;
            $owner->{$this->rightAttribute} = $key + 1;
            $owner->{$this->levelAttribute} = $target->{$this->levelAttribute} + $levelUp;

            $this->ignoreEvent = true;
            $result = $owner->create($attributes);
            $this->ignoreEvent = false;

            if (!$result) {
                $db->rollback();
                $this->ignoreEvent = false;

                return false;
            }
            $db->commit();
        } catch (\Exception $e) {
            $db->rollback();
            $this->ignoreEvent = false;

            throw $e;
        }

        return true;
    }

    /**
     * @param  array $attributes
     * @param  array $whiteList
     *
     * @return boolean
     * @throws Exception
     */
    private function makeRoot($attributes, $whiteList)
    {
        $owner = $this->getOwner();

        $owner->{$this->leftAttribute} = 1;
        $owner->{$this->rightAttribute} = 2;
        $owner->{$this->levelAttribute} = 1;
        $owner->{$this->rootAttribute} = $owner->{$this->primaryKey} ?? $attributes[$this->primaryKey];

        if ($this->hasManyRoots) {
            $this->db->begin();
            $this->ignoreEvent = true;
            if ($owner->create($attributes, $whiteList) == false) {
                $this->db->rollback();
                $this->ignoreEvent = false;

                return false;
            }

            $this->ignoreEvent = false;

            $this->db->commit();
        } else {
            if (count($owner->roots())) {
                throw new Exception('Cannot create more than one root in single root mode.');
            }

            if ($owner->create($attributes, $whiteList) == false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param bool $toArray
     * @return Model\Resultset|array
     */
    public function toFlat($toArray = false)
    {
        return ($toArray) ? $this->resultSet->toArray() : $this->resultSet;
    }

    /**
     * @param int $left
     * @param null $right
     * @return array
     */
    public function toTree($left = 0, $right = null) {
        $tree = null;
        foreach ($this->toFlat(true) as $cat => $range) {
            if ($range[$this->leftAttribute] >= $left + 1 && (is_null($right) || $range[$this->rightAttribute] < $right)) {
                $range['children'] = $this->toTree($range[$this->leftAttribute], $range[$this->rightAttribute]);
                $tree[] = $range;
                $left = $range[$this->rightAttribute];
            }
        }
        return $tree;
    }
}
