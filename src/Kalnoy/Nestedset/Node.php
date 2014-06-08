<?php

namespace Kalnoy\Nestedset;

use Exception;
use LogicException;
use \Illuminate\Database\Eloquent\Model as Eloquent;
use \Illuminate\Database\Query\Builder;
use \Illuminate\Database\Query\Expression;

class Node extends Eloquent {

    /**
     * The name of "lft" column.
     *
     * @var string 
     */
    const LFT = '_lft';

    /**
     * The name of "rgt" column.
     *
     * @var string 
     */
    const RGT = '_rgt';

    /**
     * The name of "parent id" column.
     *
     * @var string 
     */
    const PARENT_ID = 'parent_id';

    /**
     * Insert direction.
     *
     * @var string 
     */
    const BEFORE = 'before';

    /**
     * Insert direction.
     *
     * @var string 
     */
    const AFTER = 'after';

    /**
     * Whether model uses soft delete.
     * 
     * @var bool
     * 
     * @since 1.1
     */
    static protected $softDelete;

    /**
     * Pending operation.
     * 
     * @var array
     */
    protected $pending = [ 'root' ];

    /**
     * Keep track of the number of performed operations.
     * 
     * @var int
     */
    static $actionsPerformed = 0;

    /**
     * {@inheritdoc}
     */
    protected static function boot()
    {
        parent::boot();

        static::$softDelete = static::getIsSoftDelete();

        static::signOnEvents();
    }

    /**
     * Get whether model uses soft delete.
     * 
     * @return bool
     */
    protected static function getIsSoftDelete()
    {
        $instance = new static;

        return method_exists($instance, 'withTrashed');
    }

    /**
     * Sign on model events.
     */
    protected static function signOnEvents()
    {
        static::saving(function ($model)
        {
            return $model->callPendingAction();
        });

        if ( ! static::$softDelete)
        {
            static::deleting(function ($model)
            {
                // We will need fresh data to delete node safely
                $model->refreshNode();
            });

            static::deleted(function ($model)
            {
                $model->deleteNode();
            });
        }
    }

    /**
     * Set an action.
     * 
     * @param string $action
     */
    protected function setAction($action)
    {
        $this->pending = func_get_args();

        return $this;
    }

    /**
     * Clear pending action.
     */
    protected function clearAction()
    {
        $this->pending = null;
    }

    /**
     * Call pending action.
     *
     * @return null|false
     */
    protected function callPendingAction()
    {
        if ( ! $this->pending) return;

        $method = 'action'.ucfirst(array_shift($this->pending));
        $parameters = $this->pending;

        $this->pending = null;

        return call_user_func_array([ $this, $method ], $parameters);
    }

    /**
     * Make a root node.
     */
    protected function actionRoot()
    {
        // Simplest case that do not affect other nodes.
        if ( ! $this->exists)
        {
            $cut = $this->getLowerBound() + 1;

            $this->setAttribute(static::LFT, $cut);
            $this->setAttribute(static::RGT, $cut + 1);

            return true;
        }

        // Reset parent object
        $this->setParent(null);

        return $this->insertAt($this->getLowerBound() + 1);
    }

    /**
     * Get the lower bound.
     * 
     * @return int
     */
    protected function getLowerBound()
    {
        return $this->newServiceQuery()->max(static::RGT);
    }

    /**
     * Append a node to the parent.
     *
     * @param \Kalnoy\Nestedset\Node $parent
     */
    protected function actionAppendTo(Node $parent)
    {
        return $this->actionAppendOrPrepend($parent);
    }

    /**
     * Prepend a node to the parent.
     * 
     * @param \Kalnoy\Nestedset\Node $parent
     */
    protected function actionPrependTo(Node $parent)
    {
        return $this->actionAppendOrPrepend($parent, true);
    }

    /**
     * Append or prepend a node to the parent.
     * 
     * @param \Kalnoy\Nestedset\Node $parent
     * @param bool $prepend
     */
    protected function actionAppendOrPrepend(Node $parent, $prepend = false)
    {
        if ( ! $parent->exists)
        {
            throw new LogicException('Cannot use non-existing node as a parent.');
        }

        $this->setParent($parent);

        $parent->refreshNode();

        return $this->insertAt($prepend ? $parent->getLft() + 1 : $parent->getRgt());
    }

    /**
     * Apply parent model.
     * 
     * @param \Kalnoy\Nestedset\Node|null $value
     */
    protected function setParent($value)
    {
        $this->attributes[static::PARENT_ID] = $value ? $value->getKey() : null;
        $this->setRelation('parent', $value);
    }

    /**
     * Insert node before or after another node.
     * 
     * @param \Kalnoy\Nestedset\Node $node
     * @param bool $after
     */
    protected function actionBeforeOrAfter(Node $node, $after = false)
    {
        if ( ! $node->exists)
        {
            throw new LogicException('Cannot insert before/after non-existing node.');
        }

        if ($this->getParentId() <> $node->getParentId())
        {
            $this->setParent($node->getAttribute('parent'));
        }

        $node->refreshNode();

        return $this->insertAt($after ? $node->getRgt() + 1 : $node->getLft());
    }

    /**
     * Insert node before other node.
     * 
     * @param \Kalnoy\Nestedset\Node $node
     */
    protected function actionBefore(Node $node)
    {
        return $this->actionBeforeOrAfter($node);
    }

    /**
     * Insert node after other node.
     * 
     * @param \Kalnoy\Nestedset\Node $node
     */
    protected function actionAfter(Node $node)
    {
        return $this->actionBeforeOrAfter($node, true);
    }

    /**
     * Refresh node's crucial attributes.
     */
    public function refreshNode()
    {
        if ( ! $this->exists || static::$actionsPerformed === 0) return;

        $attributes = $this->newServiceQuery()->getNodeData($this->getKey());

        $this->attributes = array_merge($this->attributes, $attributes);
    }

    /**
     * Get the root node.
     *
     * @param   array   $columns
     *
     * @return  Node
     */
    static public function root(array $columns = array('*'))
    {
        return static::whereIsRoot()->first($columns);
    }

    /**
     * Relation to the parent.
     *
     * @return BelongsTo
     */
    public function parent()
    {
        return $this->belongsTo(get_class($this), static::PARENT_ID);
    }

    /**
     * Relation to children.
     *
     * @return HasMany
     */
    public function children()
    {
        return $this->hasMany(get_class($this), static::PARENT_ID);
    }

    /**
     * Get query for descendants of the node.
     *
     * @return  \Kalnoy\Nestedset\QueryBuilder
     */
    public function descendants()
    {
        return $this->newQuery()->whereDescendantOf($this->getKey());
    }

    /**
     * Get query for siblings of the node.
     * 
     * @param self::AFTER|self::BEFORE|null $dir
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function siblings($dir = null)
    {
        switch ($dir)
        {
            case self::AFTER: 
                $query = $this->next();

                break;

            case self::BEFORE:
                $query = $this->prev();

                break;

            default:
                $query = $this->newQuery()
                    ->where($this->getKeyName(), '<>', $this->getKey());

                break;
        }

        $query->where(static::PARENT_ID, '=', $this->getParentId());
        
        return $query;
    }

    /**
     * Get query for siblings after the node.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function nextSiblings()
    {
        return $this->siblings(self::AFTER);
    }

    /**
     * Get query for siblings before the node.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function prevSiblings()
    {
        return $this->siblings(self::BEFORE);
    }

    /**
     * Get query for nodes after current node.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function next()
    {
        return $this->newQuery()->whereIsAfter($this->getKey())->defaultOrder();
    }

    /**
     * Get query for nodes before current node in reversed order.
     *
     * @return \Kalnoy\Nestedset\QueryBuilder
     */
    public function prev()
    {
        return $this->newQuery()->whereIsBefore($this->getKey())->reversed();
    }

    /**
     * Get query for ancestors to the node not including the node itself.
     *
     * @return  \Kalnoy\Nestedset\QueryBuilder
     */
    public function ancestors()
    {
        return $this->newQuery()->whereAncestorOf($this->getKey());
    }

    /**
     * Make this node a root node.
     * 
     * @return $this
     */
    public function makeRoot()
    {
        return $this->setAction('root');
    }

    /**
     * Save node as root.
     * 
     * @return bool
     */
    public function saveAsRoot()
    {
        return $this->makeRoot()->save();
    }

    /**
     * Append and save a node.
     *
     * @param \Kalnoy\Nestedset\Node $node
     *
     * @return bool
     */
    public function append(Node $node)
    {
        return $node->appendTo($this)->save();
    }

    /**
     * Prepend and save a node.
     *
     * @param \Kalnoy\Nestedset\Node $node
     *
     * @return bool
     */ 
    public function prepend(Node $node)
    {
        return $node->prependTo($this)->save();
    }

    /**
     * Append a node to the new parent.
     *
     * @param \Kalnoy\Nestedset\Node $parent
     *
     * @return $this
     */
    public function appendTo(Node $parent)
    {
        return $this->setAction('appendTo', $parent);
    }

    /**
     * Prepend a node to the new parent.
     *
     * @param \Kalnoy\Nestedset\Node $parent
     *
     * @return $this
     */
    public function prependTo(Node $parent)
    {        
        return $this->setAction('prependTo', $parent);
    }

    /**
     * Insert self after a node.
     *
     * @param \Kalnoy\Nestedset\Node $node
     *
     * @return $this
     */
    public function after(Node $node)
    {
        return $this->setAction('after', $node);
    }

    /**
     * Insert self after a node and save.
     * 
     * @param \Kalnoy\Nestedset\Node $node
     * 
     * @return bool
     */
    public function insertAfter(Node $node)
    {
        return $this->after($node)->save();
    }

    /**
     * Insert self before node.
     *
     * @param \Kalnoy\Nestedset\Node $node
     *
     * @return $this
     */
    public function before(Node $node)
    {
        return $this->setAction('before', $node);
    }

    /**
     * Insert self before a node and save.
     * 
     * @param \Kalnoy\Nestedset\Node $node
     * 
     * @return bool
     */
    public function insertBefore(Node $node)
    {
        return $this->before($node)->save();
    }

    /**
     * Move node up given amount of positions.
     * 
     * @param int $amount
     * 
     * @return bool
     */
    public function up($amount = 1)
    {
        if ($sibling = $this->prevSiblings()->skip($amount - 1)->first())
        {
            return $this->insertBefore($sibling);
        }

        return false;
    }

    /**
     * Move node down given amount of positions.
     * 
     * @param int $amount
     * 
     * @return bool
     */
    public function down($amount = 1)
    {
        if ($sibling = $this->nextSiblings()->skip($amount - 1)->first())
        {
            return $this->insertAfter($sibling);
        }

        return false;
    }

    /**
     * Insert node at specific position.
     *
     * @param  int $position
     *
     * @return bool
     */
    protected function insertAt($position)
    {
        $this->refreshNode();

        if ($this->exists && $this->getLft() < $position && $position < $this->getRgt()) 
        {
            throw new Exception("Trying to insert node into one of it's descendants.");
        }

        if ($this->exists)
        {
            $this->moveNode($position);
        }
        else
        {
            $this->insertNode($position);
        }

        ++static::$actionsPerformed;
    }

    /**
     * Move a node to new position.
     *
     * @param int $lft
     * @param int $rgt
     * @param int $pos
     *
     * @return int
     */
    protected function moveNode($pos)
    {
        $lft = $this->getLft();
        $rgt = $this->getRgt();

        $from = min($lft, $pos);
        $to   = max($rgt, $pos - 1);

        // The height of node that is being moved
        $height = $rgt - $lft + 1;

        // The distance that our node will travel to reach it's destination
        $distance = $to - $from + 1 - $height;

        if ($pos > $lft) $height *= -1; else $distance *= -1;

        $params = compact('lft', 'rgt', 'from', 'to', 'height', 'distance');

        $query = $this->newServiceQuery()->getQuery()
            ->whereBetween(static::LFT, array($from, $to))
            ->orWhereBetween(static::RGT, array($from, $to));

        $grammar = $query->getGrammar();

        // Sync with original since those attributes are updated after prev operation
        $this->original[static::LFT] = $this->attributes[static::LFT] += $distance;
        $this->original[static::RGT] = $this->attributes[static::RGT] += $distance;

        return $query->update($this->getColumnsPatch($params, $grammar));
    }

    /**
     * Insert new node at specified position.
     * 
     * @param int $position
     */
    protected function insertNode($position)
    {
        $this->makeGap($position, 2);

        $height = $this->getNodeHeight();

        $this->setAttribute(static::LFT, $position);
        $this->setAttribute(static::RGT, $position + $height - 1);
    }

    /**
     * Make or remove gap in the tree. Negative height will remove gap.
     *
     * @param int $cut
     * @param int $height
     *
     * @return int the number of updated nodes.
     */
    protected function makeGap($cut, $height)
    {
        $params = compact('cut', 'height');
        
        $query = $this->newServiceQuery()->getQuery();

        return $query
            ->where(static::LFT, '>=', $cut)
            ->orWhere(static::RGT, '>=', $cut)
            ->update($this->getColumnsPatch($params, $query->getGrammar()));
    }

    /**
     * Get patch for columns.
     *
     * @param  array  $params
     * @param  \Illuminate\Database\Query\Grammars\Grammar $grammar
     *
     * @return array
     */
    protected function getColumnsPatch(array $params, $grammar)
    {
        $columns = array();

        foreach (array(static::LFT, static::RGT) as $col) 
        {
            $columns[$col] = $this->getColumnPatch($grammar->wrap($col), $params);
        }

        return $columns;
    }

    /**
     * Get patch for single column.
     *
     * @param  string $col
     * @param  array  $params
     *
     * @return string
     */
    protected function getColumnPatch($col, array $params)
    {
        extract($params);

        if ($height > 0) $height = '+'.$height;

        if (isset($cut)) 
        {
            return new Expression("case when $col >= $cut then $col $height else $col end");
        }

        if ($distance > 0) $distance = '+'.$distance;

        return new Expression("case ".
            "when $col between $lft and $rgt then $col $distance ".
            "when $col between $from and $to then $col $height ".
            "else $col end"
        );
    }

    /**
     * Update the tree when the node is removed physically.
     *
     * @return void
     */
    protected function deleteNode()
    {
        // DBMS with support of foreign keys will remove descendant nodes automatically
        $this->newQuery()->whereNodeBetween([ $this->getLft(), $this->getRgt() ])->delete();

        // In case if user wants to re-create the node
        $this->makeRoot();

        return $this->makeGap($this->getRgt() + 1, - $this->getNodeHeight());
    }

    /**
     * {@inheritdoc}
     * 
     * @since 1.2
     */
    public function newEloquentBuilder($query)
    {
        return new QueryBuilder($query);
    }

    /**
     * Get a new base query that includes deleted nodes.
     * 
     * @since 1.1
     */
    protected function newServiceQuery()
    {
        return static::$softDelete ? $this->withTrashed() : $this->newQuery();
    }

    /**
     * {@inheritdoc}
     */
    public function newCollection(array $models = array())
    {
        return new Collection($models);
    }

    /**
     * {@inheritdoc}
     */
    public function newFromBuilder($attributes = array())
    {
        $instance = parent::newFromBuilder($attributes);

        $instance->clearAction();

        return $instance;
    }

    /**
     * Get node size (rgt-lft).
     *
     * @return int
     */
    public function getNodeHeight()
    {
        if ( ! $this->exists) return 2;

        return $this->getRgt() - $this->getLft() + 1;
    }

    /**
     * Get number of descendant nodes.
     *
     * @return int
     */
    public function getDescendantCount()
    {
        return round($this->getNodeHeight() / 2) - 1;
    }

    /**
     * Set the value of model's parent id key.
     *
     * Behind the scenes node is appended to found parent node.
     *
     * @param int $value
     * 
     * @throws Exception If parent node doesn't exists
     */
    public function setParentIdAttribute($value)
    {
        if ($this->getAttribute(static::PARENT_ID) != $value) 
        {
            $this->appendTo(static::findOrFail($value));
        }
    }

    /**
     * Get whether node is root.
     *
     * @return boolean
     */
    public function isRoot()
    {
        return $this->getAttribute(static::PARENT_ID) === null;
    }

    /**
     * Get the lft key name.    
     *
     * @return  string
     */
    public function getLftName()
    {
        return static::LFT;
    }

    /**
     * Get the rgt key name.
     *
     * @return  string
     */
    public function getRgtName()
    {
        return static::RGT;
    }

    /**
     * Get the parent id key name.
     *
     * @return  string
     */
    public function getParentIdName()
    {
        return static::PARENT_ID;
    }

    /**
     * Get the value of the model's lft key.
     *
     * @return  integer
     */
    public function getLft()
    {
        return isset($this->attributes[static::LFT]) ? $this->attributes[static::LFT] : null;
    }

    /**
     * Get the value of the model's rgt key.
     *
     * @return  integer
     */
    public function getRgt()
    {
        return isset($this->attributes[static::RGT]) ? $this->attributes[static::RGT] : null;
    }

    /**
     * Get the value of the model's parent id key.
     *
     * @return  integer
     */
    public function getParentId()
    {
        return $this->getAttribute(static::PARENT_ID);
    }

    /**
     * Shorthand for next()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Node
     */
    public function getNext(array $columns = array('*'))
    {
        return $this->next()->first($columns);
    }

    /**
     * Shorthand for prev()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Node
     */
    public function getPrev(array $columns = array('*'))
    {
        return $this->prev()->first($columns);
    }

    /**
     * Shorthand for ancestors()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function getAncestors(array $columns = array('*'))
    {
        return $this->newQuery()->ancestorsOf($this->getKey(), $columns);
    }

    /**
     * Shorthand for descendants()
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function getDescendants(array $columns = array('*'))
    {
        return $this->newQuery()->descendantsOf($this->getKey(), $columns);
    }

    /**
     * Shorthand for siblings()
     *
     * @param   array   $column
     *
     * @return  \Kalnoy\Nestedset\Collection
     */
    public function getSiblings(array $column = array('*')) 
    {
        return $this->siblings()->get($columns);
    }

    /**
     * Shorthand for nextSiblings().
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function getNextSiblings(array $columns = array('*'))
    {
        return $this->nextSiblings()->get($columns);
    }

    /**
     * Shorthand for prevSiblings().
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Collection
     */
    public function getPrevSiblings(array $columns = array('*'))
    {
        return $this->prevSiblings()->get($columns);
    }

    /**
     * Get next sibling.
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Node
     */
    public function getNextSibling(array $columns = array('*'))
    {
        return $this->nextSiblings()->first($columns);
    }

    /**
     * Get previous sibling.
     *
     * @param  array  $columns
     *
     * @return \Kalnoy\Nestedset\Node
     */
    public function getPrevSibling(array $columns = array('*'))
    {
        return $this->prevSiblings()->reversed()->first($columns);
    }

    /**
     * Get whether a node is a descendant of other node.
     * 
     * @param \Kalnoy\Nestedset\Node $other
     * 
     * @return bool
     */
    public function isDescendantOf(Node $other)
    {
        return $this->getLft() > $other->getLft() and $this->getLft() < $other->getRgt();
    }
}