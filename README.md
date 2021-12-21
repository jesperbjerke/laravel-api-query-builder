# Laravel ApiQueryBuilder

A library to enable clients to query models in a very dynamic way, mimicking the Eloquent ORM.

## Installation

```shell script
composer require bjerke/api-query-builder
```

### Configuration

There is not much to configure, but one thing that can be configured is what collation to use when querying with localized order clauses.
It is preconfigured to use `utf8mb4_swedish_ci` for the `sv` and `sv-SE` locales. If you don't need any special collations for your other locales, there's no need to publish this configuration.
If you do want to add other collations for specific locales however, you need to publish the configuration file from this library so you can change it in your own application.
To do this run the following artisan command:
```sh
php artisan vendor:publish --provider="Bjerke\ApiQueryBuilder\QueryBuilderServiceProvider"
```

You will now have a `querybuilder.php` config file in `/config` where you can add additional locales => collation combinations

## Usage

To use the query builder, you will use the 2 main components of this library. The trait `QueryBuilderModelTrait` and the builder itself `QueryBuilder`.

The trait is there to help the builder validate requested fields, relations, appendable attributes and counts. As well as some helper methods. Read more on how to use [relations](#with), [appendable attributes](#appends) and [counts](#counts) in their own descriptions below.
This trait needs to be included in the models you want to use the query builder on.

In your controller method, you can then use the query builder to compile an Eloquent builder class based on the request like:
```php
public function index(Request $request)
{
    // Setup the builder
    $queryBuilder = new QueryBuilder(new MyModel, $request);

    // Parse the request and return an Eloquent Builder instance
    $query = $queryBuilder->build();

    // The instance can be extended/modified freely just as any other Eloquent Builder instance
    // For example, maybe we want to enable the option to turn pagination on/off?
    if (($pagination = $request->input('paginate')) !== null &&
        ($pagination === false || $pagination === 'false' || $pagination === '0')
    ) {
        return $query->get();
    }

    $perPage = $request->input('per_page');

    return $query->paginate($perPage)->appends($request->except('page'));
}
```

### Available query methods

Most methods include an `or` counterpart, that will allow you to create OR statements in your queries. Just like Eloquent.
For example `where` and `orWhere`.

- [where](#where--orwhere)
- [whereIn](#wherein--orwherein)
- [whereNotIn](#wherenotin--orwherenotin)
- [whereBetween](#wherebetween--orwherebetween)
- [whereNotBetween](#wherenotbetween--orwherenotbetween)
- [whereNull](#wherenull--orwherenull)
- [whereNotNull](#wherenotnull--orwherenotnull)
- [whereHas](#wherehas--orwherehas)
- [whereDoesntHave](#wheredoesnthave--orwheredoesnthave)
- [whereDate](#wheredate) (whereDate / whereMonth / whereDay / whereYear / whereTime),
- [search](#search)
- [select](#select)
- [orderBy](#orderby)
- [groupBy](#groupby)
- [limit](#limit)
- [with](#with)
- [appends](#appends)
- [counts](#counts)
- [pagination](#pagination--per_page)

---

### where / orWhere

Executes a where statement. It can be defined in a couple of ways.
The following will do an exact match where `first_name` equals `test`
```
?where[first_name]=test
```
You can also do more advanced matching by defining an operator (`=`, `!=`, `like`, `>`, `<`). When defining an operator you also need to define a `value` parameter.
The following will perform a `like` query matching on `%test%`
```
?where[first_name][value]=%25test%25&where[first_name][operator]=like
```
__These methods are recursive. Meaning you can wrap multiple statements in a parent "where" to match all statements in it.__

---

### whereIn / orWhereIn

Similar to [where / orWhere](#where--orwhere), but matches a list of values. Values can be defined as a comma-separated string or as an actual array.
```
?whereIn[id]=1,2,3
```
or
```
?whereIn[id][]=1&whereIn[id][]=2&whereIn[id][]=3
```

---

### whereNotIn / orWhereNotIn

Same as [whereNotIn / orWhereNotIn](#wherenotin--orwherenotin), but matches the absence of provided values. 

---

### whereBetween / orWhereBetween

Matches column value is between the 2 provided values. Values can be defined as a comma-separated string or as an actual array.
```
?whereBetween[date]=2017-01-01,2018-01-01
```
or
```
?whereBetween[date][]=2017-01-01&whereBetween[date][]2018-01-01
```

---

### whereNotBetween / orWhereNotBetween

Same as [whereBetween / orWhereBetween](#wherenotbetween--orwherenotbetween), but matches the value should be outside of provided range.

---

### whereNull / orWhereNull
```
?whereNull[]=updated_at
```
---

### whereNotNull / orWhereNotNull

Same as [whereNull / orWhereNull](#wherenull--orwherenull), but matches the value should not be null.

---

### whereHas / orWhereHas

Queries existance of a relation. This requires your relation to be added to the `allowedApiRelations` array on your model. Otherwise it will just ignore this query.

Simple existence check, will only return results that has any bookings related to it:
```
?whereHas[]=bookings
```
Filter the existance check by a column value. Will only return results that has a booking with id 1 related to it:
```
?whereHas[][bookings][id]=1
```
Advanced querying. Will accept most query methods:
```
?whereHas[][bookings][whereIn][id]=1,2,3
```

---

### whereDoesntHave / orWhereDoesntHave

Same as [whereHas / orWhereHas](#wheredoesnthave--orwheredoesnthave), but matches the absence of a relation.

---

### whereDate

Query by date. All abbreviations of this method are: whereDate / whereMonth / whereDay / whereYear / whereTime.
```
?whereDate[created_at]=2016-12-31
```
You can also do more advanced matching by defining an operator (`=`, `!=`, `>`, `<`). When defining an operator you also need to define a `value` parameter.
```
?whereDate[created_at][value]=2016-12-31&where[created_at][operator]=<
```

---

### search

This is a method to make it a bit easier to do search queries on multiple columns, instead of doing advanced `where`-queries.
```
?search[value]=Jesper&search[columns]=first_name,last_name,phone&search[split]=true
```
Parameters:
```
- value: Search query
- columns: Comma separated string or array of column names to search in
- split: Boolean. Defaults to false 
    Optionally set to true  to treat spaces as delimiters for keywords,
    i.e "Jesper Bjerke" will result a query for all "Jesper" and all "Bjerke"
    Without split, it will treat it as a single keyword and match on full "Jesper Bjerke"
- json: Boolean. Defaults to false.
    If the search column is json and you want the search to be case insensitive, set this to true.
```

---

### select

Limit the data-set to only pull specific colummns.
```
?select=id,first_name,last_name
```
or
```
?select[]=id&select[]=first_name&select[]=last_name
```
You can also select relation properties, if you've loaded this with `with`.
```
?select[]=user.first_name
```

---

### orderBy

Order the result based on one or more columns.
```
?orderBy=first_name,desc
```
or multiple columns
```
?orderBy[first_name]=desc&orderBy[created_at]=desc
```
Define the order with `desc` or `asc`. There is also a specialized order called `localizedDesc` and `localizedDesc` that will run the ordering with a preconfigured collation based on current locale. Read more about [configuration](#configuration).

You can also order based on a relation property, if you've loaded this with `with`.
```
?orderBy=user.first_name,desc
```

---

### groupBy

Group the result by a column.
```
?groupBy=first_name
```

---

### limit

Limit the total possible returned result by a number.
```
?limit=2
```
Will only ever return 2 results at most.

---

### with

Eager load relations. This requires your relation to be added to the `allowedApiRelations` array on your model ([read more](#defining-allowed-relations-appendable-attributes-and-counts)). Otherwise it will just ignore this query.

Be cautions of performance of loading a lot of relations. Only do this where you know you will only get a limited result-set.

```
?with=user,booking
```
or
```
?with[]=user&with[]=booking
```

---

### appends

Append attributes. This requires your attribute to be added to the `allowedApiAppends` array on your model ([read more](#defining-allowed-relations-appendable-attributes-and-counts)). Otherwise it will just ignore this query.

Be cautions of performance of loading a lot of appendable attributes. These are processed after the query result on each model. Only do this where you know you will only get a limited result-set and the appended attribute is not hammering the database etc.

```
?appends=full_name,generated_name
```
or
```
?appends[]=full_name&appends[]=generated_name
```

---

### counts

Relation-counts. This will return a property with an integer indicating the number of results of a relation this model has.

This requires your attribute to be added to the `allowedApiCounts` array on your model ([read more](#defining-allowed-relations-appendable-attributes-and-counts)). Otherwise it will just ignore this query.

Be cautions of performance of counting a lot of relations. They will produce extra database hits.

```
?counts=users,bookings
```
or
```
?counts[]=users&counts[]=bookings
```

---

### pagination / per_page

Pagination is not specifically handled by the query builder, but here's an example of how you can do this:
```php
public function index(Request $request)
{
    // Setup the builder
    $queryBuilder = new QueryBuilder(new MyModel, $request);

    // Parse the request and return an Eloquent Builder instance
    $query = $queryBuilder->build();

    // The instance can be extended/modified freely just as any other Eloquent Builder instance
    // For example, maybe we want to enable the option to turn pagination on/off?
    if (($pagination = $request->input('paginate')) !== null &&
        ($pagination === false || $pagination === 'false' || $pagination === '0')
    ) {
        return $query->get();
    }

    $perPage = $request->input('per_page');

    return $query->paginate($perPage)->appends($request->except('page'));
}
``` 
Pagination is now true by default, and then to control the pagination for the query, you can now pass extra URL parameters.

To turn pagination off completely:
```
?pagination=false
```

To adjust the number of results per page
```
?per_page=25
```

---

## Defining allowed fields, relations, appendable attributes and counts

To avoid exposing everything on your models, you will have to define each relation, appended attribute or count that you want to be queryable.
The validation basically works the same on all of them. The only exception is `allowedApiFields`, where there is a default to allow all standard fields. After including the `QueryBuilderModelTrait` in your model, you can add the following methods to it:

```php
// ...
public function allowedApiFields(): array
{
    // Default is ['*']
    return [
        'firstname',
        'lastname'
    ];
}
// ...
````

```php
// ...
public function allowedApiRelations(): array
{
    return [
        'user' // Must be a relation on your model
    ];
}
// ...
````

```php
// ...
public function allowedApiCounts(): array
{
    return [
        'user' // Must be a relation on your model
    ];
}
// ...
```

```php
// ...
public function allowedApiAppends(): array
{
    return [
        'full_name' // Must be an appendable attribute on your model
    ];
}
// ...
```
