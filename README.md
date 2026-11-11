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

### How the query vector is sent

On Postgres the vector is a bound parameter (`?`). On MariaDB it is written into the SQL as a binary
literal (`x'…'`, float32 little-endian), not as `vec_fromtext(?)`. The literal is built from floats only.

The reason is measured: MariaDB converts a constant `vec_fromtext()` again for every row when it appears
in the select list or in `ORDER BY` ([MDEV-42201](https://jira.mariadb.org/browse/MDEV-42201)). On a
laptop with MariaDB 12.3.3 and 768-dimension vectors:

| Query | `vec_fromtext(?)` | binary literal |
| --- | --- | --- |
| Exact search, no vector index, 20,000 rows | about 950 ms | about 24 ms |
| Exact search, no vector index, 50,000 rows | 2,725 ms | 58 ms |
| With a vector index (`M=16`), 50,000 rows | 2.6 ms | 0.7 ms |

Same rows returned in both forms. One machine, one client; your numbers will differ.

### Choosing `M`

`M` trades build time for recall. On 50,000 real text passages (768 dimensions), at MariaDB's default
search effort (`mhnsw_ef_search=20`), recall@10 was 83 to 86% with the default `M=6` and 99 to 100% with
`M=16`, at about the same query time. Building the index took about 3 to 4 times longer with `M=16`. Two runs
on one laptop: measure on your own data before choosing.

## Testing

```bash
composer update
vendor/bin/phpunit
```

The integration tests run only when `DB_CONNECTION` is `mariadb` or `pgsql` (see `.github/workflows/tests.yml`).

## License

MIT
