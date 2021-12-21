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
        'whereNull',
        'orWhereNull',
        'whereNotNull',
        'orWhereNotNull',
        'whereHas',
        'orWhereHas',
        'whereDoesntHave',
        'orWhereDoesntHave',
        'whereDate',
        'orWhereDate',
        'whereDay',
        'orWhereDay',
        'whereMonth',
        'orWhereMonth',
        'whereYear',
        'orWhereYear',
        'whereTime',
        'orWhereTime',
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

        if (($with = $this->request->get('with')) !== null) {
            $this->setWith($query, $with, $this->request->get('select'));
        }

        if (($appends = $this->request->get('appends')) !== null) {
            if (is_string($appends)) {
                $appends = explode(',', $appends);
            }

            $this->model::mergeAppends($this->model->validatedApiAppends($appends));
        }

        if (($select = $this->request->get('select')) !== null) {
           $this->setSelect($query, $select);
        }

        if (($counts = $this->request->get('counts')) !== null) {
            $this->setCounts($query, $counts);
        }

        if (($orderBy = ($this->request->get('orderBy') ?? $this->request->get('order_by'))) !== null) {
            $this->setOrderBy($query, $orderBy);
        }

        if (($groupBy = ($this->request->get('groupBy') ?? $this->request->get('group_by'))) !== null) {
            $this->setGroupBy($query, $groupBy);
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
     * @param Builder $query
     * @param array|string $with
     * @param null|array|string $select
     *
     * @return Builder
     */
    private function setWith(Builder $query, $with, $select = null)
    {
        if (is_string($with)) {
            $with = explode(',', $with);
        }

        $formattedRelationSelect = [];
        if ($select !== null) {
            if (is_string($select)) {
                $select = explode(',', $select);
            }

            $select = array_filter(
                $select,
                static function ($column) {
                    return (strpos($column, '.') !== false);
                }
            );

            foreach ($select as $nestedSelect) {
                $stack = explode('.', $nestedSelect);
                $column = array_pop($stack);
                $relation = Str::camel(join('.', $stack));

                if (!array_key_exists($relation, $formattedRelationSelect)) {
                    $formattedRelationSelect[$relation] = [];
                }

                $formattedRelationSelect[$relation][] = ColumnNameSanitizer::sanitize($column);
            }
        }

        $queriedRelations = $this->model->validatedApiRelations(array_map(static function ($relation) {
            return Str::camel($relation);
        }, $with));

        if (empty($formattedRelationSelect)) {
            return $query->with($queriedRelations);
        }

        $relationQueries = [];
        foreach ($queriedRelations as $queriedRelation) {
            $validatedQueries = $this->validateRelationSelect($this->model, $queriedRelation, $formattedRelationSelect);
            foreach ($validatedQueries as $relationQuery => $relationSelect) {
                if ($relationSelect && !empty($relationSelect)) {
                    $relationQueries[$relationQuery] = static function ($query) use ($relationSelect) {
                        $query->select($relationSelect);
                    };
                } else {
                    $relationQueries[] = $relationQuery;
                }
            }
        }

        if (!empty($relationQueries)) {
            $query->with($relationQueries);
        }
    }

    /**
     * @param Model $model
     * @param string $relations
     * @param array $select
     * @param null|string $currentStack
     *
     * @return array
     */
    private function validateRelationSelect($model, $relations, $select = [], $currentStack = null)
    {
        $validatedRelations = [];

        $stack = explode('.', $currentStack ?? $relations);
        $thisRelation = array_shift($stack);
        $relationModel = $model->{$thisRelation}()->getRelated();
        $nextStack = implode('.', $stack);
        $queryStack = rtrim(Str::replaceLast($nextStack, '', $relations), '.');

        if (array_key_exists($queryStack, $select)) {
            $validatedRelations[$queryStack] = $relationModel->validatedApiFields($select[$queryStack]);
        } else {
            $validatedRelations[$queryStack] = null;
        }

        if (!empty($stack)) {
            $validatedRelations = array_merge(
                $validatedRelations,
                $this->validateRelationSelect(
                    $relationModel,
                    $relations,
                    $select,
                    implode('.', $stack)
                )
            );
        }

        return $validatedRelations;
    }

    /**
     * @param Builder $query
     * @param array|string $select
     *
     * @return Builder
     */
    private function setSelect(Builder $query, $select)
    {
        if (is_string($select)) {
            $select = explode(',', $select);
        }

        // Filter out nested selects (handled in "with" query)
        $compiledSelects = array_filter(
            $this->model->validatedApiFields(ColumnNameSanitizer::sanitizeArray($select)),
            static function ($column) {
                return (strpos($column, '.') === false);
            }
        );

        if (empty($compiledSelects)) {
            return $query;
        }

        $table = $this->model->getTable();
        $query->select(array_map(static function ($column) use ($table) {
            return $table . '.' . $column;
        }, $compiledSelects));

        return $query;
    }

    /**
     * @param Builder $query
     * @param array|string $counts
     *
     * @return Builder
     */
    private function setCounts(Builder $query, $counts)
    {
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

        return $query;
    }

    /**
     * @param Builder $query
     * @param array|string $orderBy
     *
     * @return Builder
     * @throws \Exception
     */
    private function setOrderBy(Builder $query, $orderBy)
    {
        if (is_string($orderBy)) {
            $orderBy = explode(',', $orderBy);
            $this->setOrder($query, $orderBy[0], $orderBy[1]);
        } else {
            foreach ($orderBy as $column => $order) {
                $this->setOrder($query, $column, $order);
            }
        }

        return $query;
    }

    /**
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
                } else {
                    return $query;
                }
            } else {
                $sanitizedColumn = $query->getGrammar()->wrap(ColumnNameSanitizer::sanitize($column));
            }

            if (Str::startsWith($order, 'localized')) {
                $query->orderByRaw(implode('', [
                    $sanitizedColumn,
                    ' COLLATE ',
                    config('querybuilder.collations.locale.' . App::getLocale(), 'utf8mb4_unicode_ci'),
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
     * @param Builder $query
     * @param array|string $groupBy
     *
     * @return Builder
     */
    private function setGroupBy(Builder $query, $groupBy)
    {
        if (is_string($groupBy)) {
            $groupBy = explode(',', $groupBy);
            $query->groupBy(ColumnNameSanitizer::sanitizeArray($groupBy));
        } else {
            $query->groupBy(ColumnNameSanitizer::sanitizeArray($groupBy));
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
            case 'whereDate':
            case 'orWhereDate':
            case 'whereDay':
            case 'orWhereDay':
            case 'whereMonth':
            case 'orWhereMonth':
            case 'whereYear':
            case 'orWhereYear':
            case 'whereTime':
            case 'orWhereTime':
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
            case 'whereNull':
            case 'orWhereNull':
            case 'whereNotNull':
            case 'orWhereNotNull':
                $column = ColumnNameSanitizer::sanitize($value);
                if (!in_array($column, $this->model->validatedApiFields([$column]), true)) {
                    return $query;
                }
                $query->{$method}($column);
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
            $relationName = $this->getRelationName($relation);
            if (in_array($relationName, $this->model->allowedApiRelations(), true)) {
                $query->{$method}($relationName, function ($query) use ($relationName, $params) {
                    foreach ($params as $column => $value) {
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

        $caseInsensitiveJSON = (isset($options['json']) && !empty($options['json'])) ? $options['json'] : false;
        if ($caseInsensitiveJSON === true || $caseInsensitiveJSON === 'true') {
            $this->caseInsensitiveJsonSearch($query, $columns, $value);
        } else {
            $this->likeSearch($query, $columns, $value);
        }

        return $query;
    }

    private function caseInsensitiveJsonSearch($query, $columns, $value)
    {
        $query->where(static function (Builder $query) use ($columns, $value) {
            foreach ($columns as $column) {
                $column = ColumnNameSanitizer::sanitize($column);

                if (is_string($value)) {
                    $query->orWhereRaw('LOWER(' . $query->getGrammar()->wrap($column) . ') like ?', ['%' . Str::lower($value) . '%']);
                } else {
                    foreach ($value as $val) {
                        $query->orWhereRaw('LOWER(' . $query->getGrammar()->wrap($column) . ') like ?', ['%' . Str::lower($val) . '%']);
                    }
                }
            }
        });
        return $query;
    }

    private function likeSearch($query, $columns, $value) {
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
