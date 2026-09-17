# Laravel Vector Metrics

Laravel 13 already supports vector distance queries on MariaDB and Postgres, but only with **cosine** distance. This package adds **Euclidean (L2)** distance, and lets you choose the metric and the MariaDB `M` parameter for vector indexes.

It adds macros only. No framework class is replaced.

## Requirements

- PHP 8.3+, Laravel 13
- MariaDB 11.7+ or Postgres with [pgvector](https://github.com/pgvector/pgvector)

## Installation

```bash
composer require rhaima96/laravel-vector-metrics
```

The service provider is registered automatically.

## Usage

Supported metrics: `cosine`, `euclidean`.

### Migrations

```php
Schema::create('documents', function (Blueprint $table) {
    $table->id();
    $table->vector('embedding', 1536);

    // MariaDB: VECTOR INDEX ... M=16 DISTANCE=euclidean
    // Postgres: USING hnsw (embedding vector_l2_ops)
    $table->vectorIndexUsing('embedding', 'euclidean', m: 16);
});
```

`m` is MariaDB's graph degree (3–200, default 6). Postgres ignores it.

### Queries

```php
Document::query()
    ->select('id', 'title')
    ->selectVectorDistanceUsing('euclidean', 'embedding', $vector)   // adds embedding_distance
    ->whereVectorDistanceUsing('euclidean', 'embedding', $vector, 0.8)
    ->orderByVectorDistanceUsing('euclidean', 'embedding', $vector)
    ->limit(10)
    ->get();
```

| Macro | Arguments |
| --- | --- |
| `selectVectorDistanceUsing` | `$metric, $column, $vector, $as = null` |
| `whereVectorDistanceUsing` | `$metric, $column, $vector, $maxDistance, $boolean = 'and'` |
| `orWhereVectorDistanceUsing` | `$metric, $column, $vector, $maxDistance` |
| `orderByVectorDistanceUsing` | `$metric, $column, $vector` |
| `vectorIndexUsing` (Blueprint) | `$column, $metric, $name = null, $m = 6` |

`$vector` is an array of floats or an `Arrayable`.

> On MariaDB, a vector index is only used when the query's distance function matches the index's `DISTANCE`. Create the index with the same metric you query with.

## Testing

```bash
composer update
vendor/bin/phpunit
```

The integration tests run only when `DB_CONNECTION` is `mariadb` or `pgsql` (see `.github/workflows/tests.yml`).

## License

MIT
