<?php

namespace Bjerke\ApiQueryBuilder;

/**
 * Trait QueryBuilderModelTrait
 *
 * Contains some model helper methods that ApiQueryBuilder relies on when making queries
 *
 * @package Bjerke\ApiQueryBuilder
 */
trait QueryBuilderModelTrait
{

    /**
     * @var array
     */
    protected static $overrideAppends = [];

    /**
     * @var array
     */
    protected static $mergeAppends = [];

    /**
     * @inheritdoc
     */
    protected function getArrayableAppends()
    {
        if (isset(static::$overrideAppends[static::class])) {
            return static::$overrideAppends[static::class];
        }

        if (isset(static::$mergeAppends[static::class])) {
            return array_merge(static::$mergeAppends[static::class], parent::getArrayableAppends());
        }

        return parent::getArrayableAppends();
    }

    /**
     * Override currently set appends on model
     *
     * @param array $appends
     */
    public static function overrideAppends($appends = [])
    {
        static::$overrideAppends[static::class] = $appends;
    }

    /**
     * Merges default appends on model with provided ones
     *
     * @param array $appends
     */
    public static function mergeAppends($appends = [])
    {
        static::$mergeAppends[static::class] = $appends;
    }

    /**
     * Returns array of regular model fields/columns that are allowed to be selected and or queried upon
     * Default is "*" which means all fields are available to select/query on
     *
     * @return array
     */
    public function allowedApiFields(): array
    {
        return ['*'];
    }

    /**
     * Returns the allowed fields from requested ones
     *
     * @param array $requestedFields Array of requested fields to query/select
     *
     * @return array
     */
    public function validatedApiFields($requestedFields = []): array
    {
        $validatedApiFields = [];
        $allowedApiFields = $this->allowedApiFields();

        if (in_array('*', $allowedApiFields, true)) {
            return $requestedFields;
        }

        if (!empty($allowedApiFields)) {
            $validatedApiFields = array_intersect($allowedApiFields, $requestedFields);
        }

        return $validatedApiFields;
    }

    /**
     * Returns array of relations that are allowed to be loaded/returned
     *
     * @return array
     */
    public function allowedApiRelations(): array
    {
        return [];
    }

    /**
     * Returns the allowed relations from requested ones
     *
     * @param array $requestedRelations Array of requested relations to load
     *
     * @return array
     */
    public function validatedApiRelations($requestedRelations = []): array
    {
        $validatedApiRelations = [];
        $allowedApiRelations = $this->allowedApiRelations();

        if (!empty($allowedApiRelations)) {
            $validatedApiRelations = array_intersect($allowedApiRelations, $requestedRelations);
        }

        return $validatedApiRelations;
    }

    /**
     * Returns array of appended attributes that are allowed to be loaded/returned
     *
     * @return array
     */
    public function allowedApiAppends(): array
    {
        return [];
    }

    /**
     * Returns the allowed appended attributes from requested ones
     *
     * @param array $requestedAppends Array of requested appends to load
     *
     * @return array
     */
    public function validatedApiAppends($requestedAppends = []): array
    {
        $validatedApiAppends = [];
        $allowedApiAppends = $this->allowedApiAppends();

        if (!empty($allowedApiAppends)) {
            $validatedApiAppends = array_intersect($allowedApiAppends, $requestedAppends);
        }

        return $validatedApiAppends;
    }

    /**
     * Returns array of relations that are allowed to return counts on
     *
     * @return array
     */
    public function allowedApiCounts(): array
    {
        return [];
    }

    /**
     * Returns the allowed counts from requested ones
     *
     * @param array $requestedCounts Array of requested counts to load
     *
     * @return array
     */
    public function validatedApiCounts($requestedCounts = []): array
    {
        $validatedApiCounts = [];
        $allowedApiCounts = $this->allowedApiCounts();

        if (!empty($allowedApiCounts)) {
            $validatedApiCounts = array_intersect($allowedApiCounts, $requestedCounts);
        }

        return $validatedApiCounts;
    }

}
