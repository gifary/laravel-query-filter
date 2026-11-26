<?php

namespace Ambengers\QueryFilter;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

abstract class AbstractQueryLoader extends RequestQueryBuilder
{
    /**
     * Relationships that can be lazy/eager loaded.
     *
     * @var array
     */
    protected $loadables = [];

    /**
     * Constraint methods that may be applied to an eager loaded relation
     * via the query string, keyed by the relation name.
     *
     * Example:
     *
     *     protected $loadableConstraints = [
     *         'comments' => ['approved', 'latest'],
     *     ];
     *
     * Only the methods declared here, the safe defaults, and the query
     * scopes defined on the related model are ever called. Any other
     * constraint from the query string is ignored.
     *
     * @var array
     */
    protected $loadableConstraints = [];

    /**
     * Safe, read-only constraint methods allowed on any declared relation.
     *
     * @var array
     */
    protected $defaultConstraints = [
        'withTrashed',
        'onlyTrashed',
        'withoutTrashed',
    ];

    /**
     * Set the builder instance.
     *
     * @param  Illuminate\Database\Eloquent\Builder  $builder
     */
    public function setEloquentBuilder(Builder $builder)
    {
        $this->builder = $builder;

        return $this;
    }

    /**
     * Load relations based on the given query parameters.
     *
     * @param  Illuminate\Database\Eloquent\Builder  $builder
     * @param  string  $relations
     * @return Illuminate\Database\Eloquent\Builder
     */
    public function load($relations = '')
    {
        if (! $relations) {
            return $this->builder;
        }

        $relations = collect(
            $this->camelKeys(
                $this->parseRelations(explode(',', $relations))
            )
        )->intersectByKeys(
            $this->camelElements($this->loadables)->flip()
        )->toArray();

        return $this->builder->with($relations);
    }

    /**
     * Parse the relations out of the given array.
     *
     * @param  array  $relations
     * @return array
     */
    protected function parseRelations(array $relations)
    {
        $results = [];

        foreach ($relations as $key => $relation) {
            // If the relation contains a pipe symbol, that means our request wanted to have constraints on the
            // eager loaded relationship. We will need to save the relation as the key and provide the parsed
            // constraints as a value. Parsed constraints will be a closure that eloquent builder can run.
            if (Str::contains($relation, '|')) {
                [$relation, $constraints] = explode('|', $relation);

                $results[$relation] = $this->parseConstraints($relation, explode(',', $constraints));

                continue;
            }

            $results[$relation] = function () {
                // Nothing to do here..
            };
        }

        return $results;
    }

    /**
     * Parse the constraints into callable query constrains.
     *
     * Only methods declared in `$loadableConstraints` for the given relation,
     * the safe default constraint methods, and the query scopes defined on the
     * related model will ever be called. Any other constraint from the query
     * string is ignored, so a read-only request can never run destructive
     * queries such as `truncate` or `delete` on the related table.
     *
     * @param  string  $relation
     * @param  array  $constraints
     * @return callable
     */
    protected function parseConstraints($relation, array $constraints)
    {
        return function ($query) use ($relation, $constraints) {
            $allowed = $this->allowedConstraints($relation, $query);

            foreach ($constraints as $constraint) {
                $constraint = Str::camel($constraint);

                if (! in_array($constraint, $allowed)) {
                    continue;
                }

                $query->$constraint();
            }
        };
    }

    /**
     * Resolve the constraint methods allowed for the given relation.
     *
     * @param  string  $relation
     * @param  Illuminate\Database\Eloquent\Builder  $query
     * @return array
     */
    protected function allowedConstraints($relation, $query)
    {
        $declared = (array) ($this->loadableConstraints[$relation] ?? []);

        $scopes = [];

        foreach ((new \ReflectionClass($query->getModel()))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (Str::startsWith($method->name, 'scope')) {
                $scopes[] = Str::camel(Str::substr($method->name, 5));
            }
        }

        return array_merge($this->defaultConstraints, $declared, $scopes);
    }
}
