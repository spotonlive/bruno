<?php

namespace Optimus\Bruno\Traits;

use DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait EloquentBuilderTrait
{
    /**
     * Apply resource options to a query builder
     * @param  Builder $queryBuilder
     * @param  array  $options
     * @return Builder
     */
    protected function applyResourceOptions(Builder $queryBuilder, array $options = [])
    {
        if (empty($options)) {
            return $queryBuilder;
        }

        extract($options);

        if (isset($includes)) {
            if (!is_array($includes)) {
                throw new InvalidArgumentException('Includes should be an array.');
            }

            $queryBuilder->with($includes);
        }

        if (isset($filter_groups)) {
            $filterJoins = $this->applyFilterGroups($queryBuilder, $filter_groups);
        }

        if (isset($sort)) {
            if (!is_array($sort)) {
                throw new InvalidArgumentException('Sort should be an array.');
            }

            if (!isset($filterJoins)) {
                $filterJoins = [];
            }

            $sortingJoins = $this->applySorting($queryBuilder, $sort, $filterJoins);
        }

        if (isset($limit)) {
            $queryBuilder->limit($limit);
        }

        if (isset($page)) {
            $queryBuilder->offset($page * $limit);
        }

        return $queryBuilder;
    }

    /**
     * @param Builder $queryBuilder
     * @param array $filterGroups
     * @param array $previouslyJoined
     * @return array
     */
    protected function applyFilterGroups(Builder $queryBuilder, array $filterGroups = [], array $previouslyJoined = [])
    {
        $joins = [];

        foreach ($filterGroups as $group) {
            $or = $group['or'];
            $filters = $group['filters'];

            $queryBuilder->where(function (Builder $query) use ($filters, $or, &$joins) {
                foreach ($filters as $filter) {
                    $this->applyFilter($query, $filter, $or, $joins);
                }
            });
        }

        foreach(array_diff($joins, $previouslyJoined) as $join) {
            $this->joinRelatedModelIfExists($queryBuilder, $join);
        }

        return $joins;
    }

    /**
     * @param Builder $queryBuilder
     * @param array $filter
     * @param bool|false $or
     * @param array $joins
     */
    protected function applyFilter(Builder $queryBuilder, array $filter, $or = false, array &$joins)
    {
        // $value, $not, $key, $operator
        extract($filter);

        $table = $queryBuilder->getModel()->getTable();

        if ($value === 'null' || $value === '') {
            $method = $not ? 'WhereNotNull' : 'WhereNull';

            call_user_func([$queryBuilder, $method], sprintf('%s.%s', $table, $key));
        } else {
            $method = $or === true ? 'orWhere' : 'where';
            $clauseOperator = null;
            $databaseField = null;

            switch($operator) {
                case 'ct':
                case 'sw':
                case 'ew':
                    $valueString = [
                        'ct' => '%'.$value.'%', // contains
                        'ew' => '%'.$value, // ends with
                        'sw' => $value.'%' // starts with
                    ];

                    $databaseField = DB::raw(sprintf('CAST(%s.%s AS TEXT)', $table, $key));
                    $clauseOperator = $not ? 'NOT ILIKE' : 'ILIKE';
                    $value = $valueString[$operator];
                    break;
                case 'eq':
                default:
                    $clauseOperator = $not ? '!=' : '=';
                    break;
                case 'gt':
                    $clauseOperator = $not ? '<' : '>';
                    break;
                case 'lt':
                    $clauseOperator = $not ? '>' : '<';
                    break;
                case 'in':
                    if ($or === true) {
                        $method = $not === true ? 'orWhereNotIn' : 'orWhereIn';
                    } else {
                        $method = $not === true ? 'whereNotIn' : 'whereIn';
                    }
                    break;
            }

            // If we do not assign database field, the customer filter method
            // will fail when we execute it with parameters such as CAST(%s AS TEXT)
            // key needs to be reserved
            if (is_null($databaseField)) {
                $databaseField = sprintf('%s.%s', $table, $key);
            }

            $customFilterMethod = $this->hasCustomMethod('filter', $key);
            if ($customFilterMethod) {
                call_user_func_array([$this, $customFilterMethod], [
                    $queryBuilder,
                    $method,
                    $clauseOperator,
                    $value,
                    $operator === 'in'
                ]);

                // column to join.
                // trying to join within a nested where will get the join ignored.
                $joins[] = $key;
            } else {
                // In operations do not have an operator
                if ($operator === 'in') {
                    call_user_func_array([$queryBuilder, $method], [
                        $databaseField, $value
                    ]);
                } else {
                    call_user_func_array([$queryBuilder, $method], [
                        $databaseField, $clauseOperator, $value
                    ]);
                }
            }
        }
    }

    /**
     * @param Builder $queryBuilder
     * @param array $sorting
     * @param array $previouslyJoined
     * @return array
     */
    protected function applySorting(Builder $queryBuilder, array $sorting, array $previouslyJoined = [])
    {
        $joins = [];
        foreach($sorting as $sortRule) {
            if (is_array($sortRule)) {
                $key = $sortRule['key'];
                $direction = mb_strtolower($sortRule['direction']) === 'asc' ? 'ASC' : 'DESC';
            } else {
                $key = $sortRule;
                $direction = 'ASC';
            }

            $customSortMethod = $this->hasCustomMethod('sort', $key);
            if ($customSortMethod) {
                $joins[] = $key;

                call_user_func([$this, $customSortMethod], $queryBuilder, $direction);
            } else {
                $queryBuilder->orderBy($key, $direction);
            }
        }

        foreach(array_diff($joins, $previouslyJoined) as $join) {
            $this->joinRelatedModelIfExists($queryBuilder, $join);
        }

        return $joins;
    }

    /**
     * @param $type
     * @param $key
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
     * @param Builder $query
     * @param $key
     */
    private function joinRelatedModelIfExists(Builder $query, $key)
    {
        $model = $query->getModel();

        // relationship exists, join to make special sort
        if (method_exists($model, $key)) {
            $relation = $model->$key();
            $type = 'inner';

            if ($relation instanceof BelongsTo) {
                $query->join(
                    $relation->getRelated()->getTable(),
                    $model->getTable().'.'.$relation->getForeignKey(),
                    '=',
                    $relation->getRelated()->getTable().'.'.$relation->getOtherKey(),
                    $type
                );
            } elseif ($relation instanceof BelongsToMany) {
                $query->join(
                    $relation->getTable(),
                    $relation->getQualifiedParentKeyName(),
                    '=',
                    $relation->getForeignKey(),
                    $type
                );
                $query->join(
                    $relation->getRelated()->getTable(),
                    $relation->getRelated()->getTable().'.'.$relation->getRelated()->getKeyName(),
                    '=',
                    $relation->getOtherKey(),
                    $type
                );
            } else {
                $query->join(
                    $relation->getRelated()->getTable(),
                    $relation->getQualifiedParentKeyName(),
                    '=',
                    $relation->getForeignKey(),
                    $type
                );
            }

            $table = $model->getTable();
            $query->select(sprintf('%s.*', $table));
        }
    }
}
