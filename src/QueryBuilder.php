<?php

namespace Bjerke\ApiQueryBuilder;

use Bjerke\ApiQueryBuilder\Helpers\ColumnNameSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class QueryBuilder
{
    protected $queryMethods = [
        'where',
        'orWhere',
        'whereIn',
        'orWhereIn',
        'whereNotIn',
        'orWhereNotIn',
        'whereBetween',
        'orWhereBetween',
        'whereNotBetween',
        'orWhereNotBetween',
        'whereHas',
        'orWhereHas',
        'whereDoesntHave',
        'orWhereDoesntHave',
        'search'
    ];

    /**
     * @var Request|null
     */
    protected $request;

    /**
     * @var Model|null
     */
    protected $model;

    public function __construct(Model $model, Request $request)
    {
        $this->request = $request;
        $this->model = $model;
    }

    /**
     * Compiles and returns database query
     *
     * @return Builder
     * @throws \Exception
     */
    public function build()
    {
        $query = $this->model->newQuery();

        $params = $this->request->all();
        $query = $this->queryRecursive($query, $params);

        /**
         * Eager load with
         */
        if (($with = $this->request->get('with')) !== null) {
            if (is_string($with)) {
                $with = explode(',', $with);
            }

            $query->with($this->model->validatedApiRelations(array_map(static function ($relation) {
                return Str::camel($relation);
            }, $with)));
        }

        /**
         * Appended attributes
         */
        if (($appends = $this->request->get('appends')) !== null) {
            if (is_string($appends)) {
                $appends = explode(',', $appends);
            }

            $this->model::mergeAppends($this->model->validatedApiAppends($appends));
        }

        /**
         * Selects
         */
        if (($select = $this->request->get('select')) !== null) {
            if (is_string($select)) {
                $select = explode(',', $select);
            }

            $compiledSelects = $this->model->validatedApiFields(ColumnNameSanitizer::sanitizeArray($select));

            $table = $this->model->getTable();
            $query->select(array_map(static function ($column) use ($table) {
                if (strpos($column, '.') === false) {
                    $column = $table . '.' . $column;
                }
                return $column;
            }, $compiledSelects));
        }

        /**
         * Relation counts
         */
        if (($counts = $this->request->get('counts')) !== null) {
            if (is_string($counts)) {
                $counts = explode(',', $counts);
                $compiledCounts = $this->model->validatedApiCounts(array_map(static function ($relation) {
                    return Str::camel($relation);
                }, $counts));
            } else {
                $compiledCounts = [];
                $allowedApiCounts = $this->model->allowedApiCounts();
                foreach ($counts as $relation => $countQuery) {
                    $countRelation = Str::camel((is_string($relation)) ? $relation : $countQuery);

                    if (!in_array($countRelation, $allowedApiCounts, true)) {
                        continue;
                    }

                    if (is_array($countQuery)) {
                        $compiledCounts[$countRelation] = function (Builder $query) use ($countQuery) {
                            $this->queryRecursive($query, $countQuery);
                        };
                    } else {
                        $compiledCounts[] = $countRelation;
                    }
                }
            }

            $query->withCount($compiledCounts);
        }

        /**
         * Order by
         */
        if (($orderBy = ($this->request->get('orderBy') ?? $this->request->get('order_by'))) !== null) {
            if (is_string($orderBy)) {
                $orderBy = explode(',', $orderBy);
                $this->setOrder($query, $orderBy[0], $orderBy[1]);
            } else {
                foreach ($orderBy as $column => $order) {
                    $this->setOrder($query, $column, $order);
                }
            }
        }

        /**
         * Group by
         */
        if (($groupBy = ($this->request->get('groupBy') ?? $this->request->get('group_by'))) !== null) {
            if (is_string($groupBy)) {
                $groupBy = explode(',', $groupBy);
                $query->groupBy(ColumnNameSanitizer::sanitizeArray($groupBy));
            } else {
                $query->groupBy(ColumnNameSanitizer::sanitizeArray($groupBy));
            }
        }

        if (($limit = $this->request->get('limit')) !== null &&
            is_numeric($limit) &&
            $limit > 0
        ) {
            $query->limit($limit);
        }

        return $query;
    }

    /**
     * Sets orderBy on provided query
     *
     * @param Builder $query
     * @param string  $column
     * @param string  $order
     *
     * @return Builder
     * @throws \Exception
     */
    private function setOrder(Builder $query, $column, $order)
    {
        $order = Str::lower($order);
        if ($order === 'asc' ||
            $order === 'desc' ||
            $order === 'localizedasc' ||
            $order === 'localizeddesc'
        ) {
            if (strpos($column, '.') !== false) {
                $stack = explode('.', $column);
                $relationName = $this->getRelationName($stack[0]);
                if (in_array($relationName, $this->model->allowedApiRelations(), true)) {
                    $sanitizedColumn = '(' . $this->model->{$relationName}()->getRelationExistenceQuery(
                        $this->model->{$relationName}()
                                    ->getRelated()
                                    ->newQueryWithoutRelationships(),
                        $query,
                        [ColumnNameSanitizer::sanitize($stack[1])]
                    )->toSql() . ' limit 1)';
                }
            } else {
                $sanitizedColumn = ColumnNameSanitizer::sanitize($column);
            }

            if (Str::startsWith($order, 'localized')) {
                $query->orderByRaw(implode('', [
                    $sanitizedColumn,
                    ' COLLATE ',
                    config('collations.locale.' . App::getLocale(), 'utf8mb4_unicode_ci'),
                    ' ',
                    Str::replaceFirst('localized', '', $order)
                ]));
            } else {
                $query->orderByRaw($sanitizedColumn . ' ' . $order);
            }
        } else {
            throw new HttpException(400, 'Sort order must be asc or desc');
        }

        return $query;
    }

    /**
     * Returns formatted relation method name
     *
     * @param string $rawRelation
     *
     * @return string
     * @throws \Exception
     */
    private function getRelationName($rawRelation)
    {
        $rawRelationName = Str::camel($rawRelation);

        if (method_exists($this->model, $rawRelationName)) {
            return $rawRelationName;
        }

        $pluralRelationName = Str::camel(Str::plural($rawRelation));
        if (method_exists($this->model, $pluralRelationName)) {
            return $pluralRelationName;
        }

        $singularRelationName = Str::camel(Str::singular($rawRelation));
        if (method_exists($this->model, $singularRelationName)) {
            return $singularRelationName;
        }

        throw new HttpException(400, "Relation {$rawRelation} not found");
    }

    /**
     * Converts string representations of certain types to actual values
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function formatValue($value)
    {
        if (is_string($value)) {
            switch ($value) {
                case 'false':
                    $value = false;
                    break;
                case 'true':
                    $value = true;
                    break;
                case 'null':
                    $value = null;
                    break;
            }
        }

        return $value;
    }

    /**
     * Initiates recursive query, wraps all queries in its own
     * where-statement to isolate external queries others added later on
     *
     * @param Builder       $query
     * @param array         $params
     * @param null|string   $subMethod
     *
     * @return Builder
     * @throws \Exception
     */
    private function queryRecursive(Builder $query, $params, $subMethod = null)
    {
        $hasQuery = !empty(array_intersect($this->queryMethods, array_keys($params)));
        if (!$hasQuery) {
            return $query;
        }

        $query->where(function ($query) use ($params, $subMethod) {
            $this->loopNestedQuery($query, $params, $subMethod);
        });

        return $query;
    }

    /**
     * Loops queries, wraps in nested statements
     *
     * @param Builder       $query
     * @param array         $params
     * @param null|string   $subMethod
     *
     * @return Builder
     * @throws \Exception
     */
    private function loopNestedQuery(Builder $query, $params, $subMethod = null)
    {
        if ($subMethod !== null) {
            if (in_array($subMethod, $this->queryMethods, true)) {
                $query->{$subMethod}(function ($query) use ($params, $subMethod) {
                    foreach ($params as $method => $columns) {
                        if (in_array($method, $this->queryMethods, true)) {
                            $this->performNestedQuery($query, $method, $columns);
                        } else {
                            $this->performQuery($query, $subMethod, $method, $columns);
                        }
                    }
                });
            }
        } else {
            foreach ($params as $method => $columns) {
                if (in_array($method, $this->queryMethods, true)) {
                    $this->performNestedQuery($query, $method, $columns);
                }
            }
        }
    }

    /**
     * Performs all queries, runs recursively on where/orWhere
     *
     * @param Builder       $query
     * @param array         $params
     * @param null|string   $subMethod
     *
     * @return Builder
     * @throws \Exception
     */
    private function performNestedQuery(Builder $query, $method, $columns)
    {
        switch ($method) {
            case 'where':
            case 'orWhere':
                $this->loopNestedQuery($query, $columns, $method);
                break;
            case 'search':
                $query = $this->performQuery($query, $method, $columns, null);
                break;
            default:
                foreach ($columns as $column => $value) {
                    $query = $this->performQuery($query, $method, $column, $value);
                }
        }
    }

    /**
     * Runs query method on provided query.
     * Formats provided value
     *
     * Examples:
     * where[first_name]=test
     * where[first_name][value]=%25test%25&where[first_name][operator]=like
     *
     * whereIn[id]=1,2,3
     * whereBetween[date]=2017-01-01,2018-01-01
     *
     * whereHas[]=bookings
     * whereHas[][bookings][id]=1
     * whereHas[][bookings][whereIn][id]=1,2,3
     *
     * search[value]=Jesper&search[columns]=first_name,last_name,phone
     *
     * @param Builder       $query
     * @param string        $method
     * @param string|array  $column
     * @param mixed         $value
     *
     * @return Builder
     * @throws \Exception
     */
    private function performQuery(Builder $query, $method, $column, $value)
    {
        switch ($method) {
            case 'where':
            case 'orWhere':
                $column = ColumnNameSanitizer::sanitize($column);
                if (!in_array($column, $this->model->validatedApiFields([$column]), true)) {
                    return $query;
                }

                if (is_array($value)) {
                    if (isset($value['operator'], $value['value'])) {
                        $query->{$method}($column, $value['operator'], $this->formatValue($value['value']));
                    }
                } else {
                    $query->{$method}($column, $this->formatValue($value));
                }
                break;
            case 'whereHas':
            case 'orWhereHas':
            case 'whereDoesntHave':
            case 'orWhereDoesntHave':
                $this->performExistanceQuery($query, $method, $column, $value);
                break;
            case 'whereIn':
            case 'orWhereIn':
            case 'whereNotIn':
            case 'orWhereNotIn':
            case 'whereBetween':
            case 'orWhereBetween':
            case 'whereNotBetween':
            case 'orWhereNotBetween':
                $column = ColumnNameSanitizer::sanitize($column);
                if (!in_array($column, $this->model->validatedApiFields([$column]), true)) {
                    return $query;
                }

                if (is_string($value)) {
                    $value = explode(',', $value);
                }
                $query->{$method}($column, $value);
                break;
            case 'search':
                if (!in_array($column, $this->model->validatedApiFields([$column]), true)) {
                    return $query;
                }
                $this->performSearchQuery($query, $column);
                break;
        }

        return $query;
    }

    /**
     * Runs variations of whereHas queries
     *
     * Examples:
     * whereHas[]=bookings
     * whereHas[][bookings][id]=1
     * whereHas[][bookings][whereIn][id]=1,2,3
     * whereHas[bookings][where][bookable_type]=test
     *
     * @param Builder $query
     * @param string $method
     * @param string|int $relation Array index or relation name
     * @param string|array $params Array or string of relations or query params for provided relation in $relation
     *
     * @return Builder
     * @throws \Exception
     */
    private function performExistanceQuery($query, $method, $relation, $params)
    {
        // Complex query (eg. whereHas[bookings][where][bookable_type]=test)
        if (is_string($relation)) {
            if (in_array($this->getRelationName($relation), $this->model->allowedApiRelations(), true)) {
                $query->{$method}($relation, function ($query) use ($relation, $params) {
                    foreach ($params as $column => $value) {
                        if (in_array($column, $this->queryMethods, true)) {
                            foreach ($value as $subColumn => $subValue) {
                                if (strpos($subColumn, '.') === false) {
                                    $subColumn = $this->model->{$relation}()
                                                             ->getRelated()
                                                             ->getTable() . '.' . $subColumn;
                                }
                                $this->performQuery($query, $column, $subColumn, $subValue);
                            }
                        } else {
                            if (strpos($column, '.') === false) {
                                $column = $this->model->{$relation}()->getRelated()->getTable() . '.' . $column;
                            }
                            $this->performQuery($query, 'where', $column, $value);
                        }
                    }
                });
            }

            return $query;
        }

        // Simple query (eg. whereHas[]=bookings
        if (is_string($params)) {
            if (in_array($this->getRelationName($params), $this->model->allowedApiRelations(), true)) {
                $query->{$method}($this->getRelationName($params));
            }

            return $query;
        }

        // Multiquery (eg. whereHas[][bookings][id]=1&whereHas[][bookings][id]=2
        if (is_array($params)) {
            foreach ($params as $relationName => $relationColumns) {
                $relationName = $this->getRelationName($relationName);

                if (!in_array($this->getRelationName($relationName), $this->model->allowedApiRelations(), true)) {
                    continue;
                }

                $query->{$method}($relationName, function ($query) use ($relationName, $relationColumns) {
                    foreach ($relationColumns as $column => $value) {
                        if (in_array($column, $this->queryMethods, true)) {
                            foreach ($value as $subColumn => $subValue) {
                                if (strpos($subColumn, '.') === false) {
                                    $subColumn = $this->model->{$relationName}()
                                                             ->getRelated()
                                                             ->getTable() . '.' . $subColumn;
                                }
                                $this->performQuery($query, $column, $subColumn, $subValue);
                            }
                        } else {
                            if (strpos($column, '.') === false) {
                                $column = $this->model->{$relationName}()->getRelated()->getTable() . '.' . $column;
                            }
                            $this->performQuery($query, 'where', $column, $value);
                        }
                    }
                });
            }

            return $query;
        }

        return $query;
    }

    /**
     * Runs loose search on multiple columns
     * Optionally set "split" to true, to treat spaces as delimiters for keywords,
     * i.e "Jesper Bjerke" will result a query for all "Jesper" and all "Bjerke"
     * Without split, it will treat is as a single keyword and match on full "Jesper Bjerke"
     *
     * Examples:
     * search[value]=Jesper&search[columns]=first_name,last_name,phone&search[split]=true
     *
     * @param Builder $query
     * @param array  $options
     *
     * @return Builder
     * @throws \Exception
     */
    private function performSearchQuery($query, $options)
    {
        $value = (isset($options['value']) && !empty($options['value'])) ? $options['value'] : '';

        if (!$value) {
            throw new HttpException(400, 'Search value missing');
        }

        $split = (isset($options['split']) && !empty($options['split'])) ? $options['split'] : false;

        if ($split === true || $split === 'true') {
            $value = explode(' ', $value);
        }

        $columns = (isset($options['columns']) && !empty($options['columns'])) ? $options['columns'] : '';

        if (is_string($columns)) {
            $columns = explode(',', $columns);
        }

        if (empty($columns)) {
            throw new HttpException(400, 'Search columns missing');
        }

        $query->where(static function (Builder $query) use ($columns, $value) {
            foreach ($columns as $column) {
                $column = ColumnNameSanitizer::sanitize($column);

                if (is_string($value)) {
                    $query->orWhere($column, 'like', '%' . $value . '%');
                } else {
                    foreach ($value as $val) {
                        $query->orWhere($column, 'like', '%' . $val . '%');
                    }
                }
            }
        });

        return $query;
    }
}
