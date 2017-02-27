<?php

namespace Optimus\Bruno\Traits;

use DB;
use Doctrine\ORM\QueryBuilder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Optimus\Bruno\Exceptions\NoRootAliasException;

trait DoctrineBuilderTrait
{
    /**
     * Root alias
     *
     * @var null|string
     */
    protected $rootAlias = null;

    /**
     * @var array
     */
    protected $includes = [];

    /**
     * Apply resource options
     *
     * @param QueryBuilder $queryBuilder
     * @param array $options
     * @return QueryBuilder
     * @throws NoRootAliasException
     */
    protected function applyResourceOptions(QueryBuilder $queryBuilder, array $options = [])
    {
        $this->resetRelations();
        
        if (empty($options)) {
            return $queryBuilder;
        }

        extract($options);

        $rootAliases = $queryBuilder->getRootAliases();

        // Set root alias
        if (!is_array($rootAliases) || !isset($rootAliases[0])) {
            throw new NoRootAliasException();
        }

        $this->setRootAlias($rootAliases[0]);

        // Includes
        if (isset($includes)) {
            if (!is_array($includes)) {
                throw new InvalidArgumentException('Includes should be an array.');
            }

            if (count($includes)) {
                foreach ($includes as $include) {
                    $this->includeRelation($include, $queryBuilder, true);
                }
            }
        }

        if (isset($filter_groups)) {
            $this->applyFilterGroups($queryBuilder, $filter_groups);
        }
        if (isset($sort)) {
            if (!is_array($sort)) {
                throw new InvalidArgumentException('Sort should be an array.');
            }

            $this->applySorting($queryBuilder, $sort);
        }

        if (isset($limit)) {
            $queryBuilder->setMaxResults($limit);

            if (isset($page)) {
                $queryBuilder->setFirstResult(($page - 1) * $limit);
            }
        }

        return $queryBuilder;
    }

    /**
     * Include relation
     *
     * @param string $include
     * @param QueryBuilder $queryBuilder
     * @param boolean $select
     * @return bool
     */
    protected function includeRelation($include, QueryBuilder $queryBuilder, $select = false)
    {
        if (strpos($include, '.') === false) {
            $include = sprintf(
                '%s.%s',
                $this->getRootAlias(),
                $include
            );
        }

        if (in_array($include, $this->includes)) {
            return false;
        }

        $key = explode(".", $include);
        $alias = $key[1];

        if ($select) {
            $queryBuilder->addSelect($alias);
        }

        $queryBuilder->leftJoin($include, $alias);

        $this->includes[] = $include;

        return true;
    }

    /**
     * Apply filter groups
     *
     * @param QueryBuilder $queryBuilder
     * @param array $filterGroups
     * @return array
     */
    protected function applyFilterGroups(QueryBuilder $queryBuilder, array $filterGroups = [])
    {
        $joins = [];

        foreach ($filterGroups as $group) {
            $or = isset($group['or']) ? $group['or'] : false;

            $filters = [];

            foreach ($group['filters'] as $filter) {
                $useParamKey = true;
                $paramKey = 'param' . Str::random(8);

                $operator = $filter['operator'];
                $key = $filter['key'];
                $value = $filter['value'];
                $not = isset($filter['not']) ? $filter['not'] : false;

                // Customer filter method
                if ($customFilterMethod = $this->hasCustomFilter($key)) {
                    call_user_func(
                        [$this, $customFilterMethod],
                        $queryBuilder,
                        $operator,
                        $value,
                        $not
                    );

                    continue;
                }

                if (strpos($key, '.') === false) {
                    $key = sprintf(
                        '%s.%s',
                        $this->getRootAlias(),
                        $key
                    );
                }

                switch ($operator) {
                    case 'ct':
                    case 'sw':
                    case 'ew':
                        $valueString = [
                            'ct' => '%' . $value . '%', // contains
                            'ew' => '%' . $value, // ends with
                            'sw' => $value . '%' // starts with
                        ];

                        $value = $valueString[$operator];

                        if ($not) {
                            $filters[] = $queryBuilder->expr()->notLike($key, ':' . $paramKey);
                            continue;
                        }

                        $filters[] = $queryBuilder->expr()->like($key, ':' . $paramKey);
                        break;

                    case 'eq':
                    default:
                        if ($not) {
                            if (!is_numeric($value) && !is_bool($value)) {
                                if (
                                    (!is_string($value) && is_null($value)) ||
                                    (is_string($value) && $value == '') ||
                                    ($value == 'NULL')
                                ) {
                                    $filters[] = $queryBuilder->expr()->isNotNull($key);
                                    $useParamKey = false;
                                    continue;
                                }
                            }

                            $filters[] = $queryBuilder->expr()->neq($key, ':' . $paramKey);
                            continue;
                        }

                        if (!is_numeric($value) && !is_bool($value)) {
                            if (
                                (!is_string($value) && is_null($value)) ||
                                (is_string($value) && $value == '') ||
                                ($value == 'NULL')
                            ) {
                                $filters[] = $queryBuilder->expr()->isNull($key);
                                $useParamKey = false;
                                continue;
                            }
                        }

                        $filters[] = $queryBuilder->expr()->eq($key, ':' . $paramKey);
                        break;

                    case 'gt':
                        if ($not) {
                            $filters[] = $queryBuilder->expr()->gt($key, ':' . $paramKey);
                            continue;
                        }

                        $filters[] = $queryBuilder->expr()->gt($key, ':' . $paramKey);
                        break;

                    case 'lt':
                        if ($not) {
                            $filters[] = $queryBuilder->expr()->lt($key, ':' . $paramKey);
                            continue;
                        }

                        $filters[] = $queryBuilder->expr()->lt($key, ':' . $paramKey);
                        break;

                    case 'gte':
                        if ($not) {
                            $filters[] = $queryBuilder->expr()->gte($key, ':' . $paramKey);
                            continue;
                        }

                        $filters[] = $queryBuilder->expr()->gte($key, ':' . $paramKey);
                        break;

                    case 'lte':
                        if ($not) {
                            $filters[] = $queryBuilder->expr()->lte($key, ':' . $paramKey);
                            continue;
                        }

                        $filters[] = $queryBuilder->expr()->lte($key, ':' . $paramKey);
                        break;

                    case 'in':
                        if ($not) {
                            $filters[] = $queryBuilder->expr()->notIn($key, ':' . $paramKey);
                            continue;
                        }

                        $filters[] = $queryBuilder->expr()->in($key, ':' . $paramKey);
                        break;
                }

                if ($useParamKey) {
                    $queryBuilder->setParameter($paramKey, $value);
                }
            }

            // Expression
            $expr = ($or) ? 'orX' : 'andX';

            /** @var \Doctrine\ORM\Query\Expr\Orx $expression */
            $expression = $queryBuilder->expr()->$expr();

            foreach ($filters as $filter) {
                $expression->add($filter);
            }

            $queryBuilder->andWhere($expression);
        }

        return $joins;
    }

    /**
     * Check if repository has custom filter
     *
     * @param string $key
     * @return bool|string
     */
    protected function hasCustomFilter($key)
    {
        return $this->hasCustomMethod('filter', $key);
    }

    /**
     * Check if repository has custom method
     *
     * @param string $type
     * @param string $key
     * @return bool|string
     */
    private function hasCustomMethod($type, $key)
    {
        $methodName = sprintf('%s%s', $type, Str::studly($key));

        if (method_exists($this, $methodName)) {
            return $methodName;
        }

        return false;
    }

    /**
     * Apply sorting
     *
     * @param QueryBuilder $queryBuilder
     * @param array $sorting
     * @return mixed
     */
    protected function applySorting(QueryBuilder $queryBuilder, array $sorting)
    {
        foreach ($sorting as $sort) {
            $key = $sort['key'];

            if (strpos($key, '.') === false) {
                $key = sprintf(
                    '%s.%s',
                    $this->getRootAlias(),
                    $key
                );
            }

            $queryBuilder->addOrderBy(
                $key,
                $sort['direction']
            );
        }
    }

    /**
     * @return null|string
     */
    public function getRootAlias()
    {
        if (!$this->rootAlias) {
            return $this->getName();
        }
        
        return $this->rootAlias;
    }

    /**
     * @param null|string $rootAlias
     */
    public function setRootAlias($rootAlias)
    {
        $this->rootAlias = $rootAlias;
    }

    /**
     * Reset relations
     */
    private function resetRelations()
    {
        $this->includes = [];
    }
}
