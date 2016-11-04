<?php

namespace Optimus\Bruno\Traits;

use DB;
use Doctrine\ORM\QueryBuilder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Optimus\Bruno\Exceptions\NoRootAlisException;

trait DoctrineBuilderTrait
{
    /**
     * Root alias
     *
     * @var null|string
     */
    protected $rootAlias = null;

    /**
     * Apply resource options
     *
     * @param QueryBuilder $queryBuilder
     * @param array $options
     * @return QueryBuilder
     * @throws NoRootAlisException
     */
    protected function applyResourceOptions(QueryBuilder $queryBuilder, array $options = [])
    {
        if (empty($options)) {
            return $queryBuilder;
        }

        extract($options);

        $rootAliases = $queryBuilder->getRootAliases();

        // Set root alias
        if (!is_array($rootAliases) || !isset($rootAliases[0])) {
            throw new NoRootAlisException();
        }

        $this->setRootAlias($rootAliases[0]);

        // Includes
        if (isset($includes)) {
            if (!is_array($includes)) {
                throw new InvalidArgumentException('Includes should be an array.');
            }

            if (count($includes)) {
                foreach ($includes as $include) {
                    $queryBuilder->addSelect($include)
                        ->leftJoin(
                            sprintf(
                                '%s.%s',
                                $this->getRootAlias(),
                                $include
                            ),
                            $include
                        );
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
        }

        if (isset($page)) {
            $queryBuilder->setFirstResult($page * $limit);
        }

        return $queryBuilder;
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
            $or = $group['or'];

            $filters = [];

            foreach ($group['filters'] as $filter) {
                $paramKey = Str::random(8);
                
                $operator = $filter['operator'];
                $key = $filter['key'];
                $value = $filter['value'];
                $not = $filter['not'];

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
                            $filters[] = $queryBuilder->expr()->neq($key, ':' . $paramKey);
                            continue;
                        }

                    $filters[] = $queryBuilder->expr()->eq($key, ':' . $paramKey);
                        break;

                    case 'gt':
                        if ($not) {
                            $filters[] = $queryBuilder->expr()->gt($key, ':' . $paramKey);
                            continue;
                        }

                        $filters[] = $queryBuilder->expr()->lt($key, ':' . $paramKey);
                        break;

                    case 'lt':
                        if ($not) {
                            $filters[] = $queryBuilder->expr()->lt($key, ':' . $paramKey);
                            continue;
                        }

                        $filters[] = $queryBuilder->expr()->gt($key, ':' . $paramKey);
                        break;

                    case 'in':
                        if ($not) {
                            $filters[] = $queryBuilder->expr()->notIn($key, ':' . $paramKey);
                            continue;
                        }

                        $filters[] = $queryBuilder->expr()->in($key, ':' . $paramKey);
                        break;
                }

                $queryBuilder->setParameter($paramKey, $value);
            }

            // Expression
            $expr = ($or) ? 'orX' : 'andX';


            foreach ($filters as $filter) {
                $queryBuilder->where($queryBuilder->expr()->$expr($filter));
            }
        }

        return $joins;
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
        return $this->rootAlias;
    }

    /**
     * @param null|string $rootAlias
     */
    public function setRootAlias($rootAlias)
    {
        $this->rootAlias = $rootAlias;
    }
}
