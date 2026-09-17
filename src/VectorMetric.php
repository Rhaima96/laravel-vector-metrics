<?php

namespace Rhaima96\VectorMetrics;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Query\Grammars\MariaDbGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Grammars\MariaDbGrammar as MariaDbSchemaGrammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar as PostgresSchemaGrammar;
use InvalidArgumentException;
use RuntimeException;

final class VectorMetric
{
    /**
     * The distance SQL for each driver and metric (%s is the wrapped column).
     */
    private const DISTANCE = [
        'mariadb' => [
            'cosine' => 'vec_distance_cosine(%s, vec_fromtext(?))',
            'euclidean' => 'vec_distance_euclidean(%s, vec_fromtext(?))',
        ],
        'pgsql' => [
            'cosine' => '(%s <=> ?)',
            'euclidean' => '(%s <-> ?)',
        ],
    ];

    /**
     * The pgvector operator class for each metric.
     */
    private const PGSQL_OPERATOR_CLASS = [
        'cosine' => 'vector_cosine_ops',
        'euclidean' => 'vector_l2_ops',
    ];

    /**
     * Compile the distance expression for the given query grammar.
     */
    public static function distance(object $grammar, string $metric, string $column): string
    {
        $driver = match (true) {
            $grammar instanceof MariaDbGrammar => 'mariadb',
            $grammar instanceof PostgresGrammar => 'pgsql',
            default => throw new RuntimeException('Vector distance metrics are only supported by MariaDB and Postgres.'),
        };

        return sprintf(self::DISTANCE[$driver][self::validate($metric)], $grammar->wrap($column));
    }

    /**
     * Get the [algorithm, operator class] of a vector index for the given schema grammar.
     *
     * @return array{0: string|null, 1: string}
     */
    public static function index(object $grammar, string $metric, int $m): array
    {
        $metric = self::validate($metric);

        return match (true) {
            $grammar instanceof MariaDbSchemaGrammar => [null, "M={$m} DISTANCE={$metric}"],
            $grammar instanceof PostgresSchemaGrammar => ['hnsw', self::PGSQL_OPERATOR_CLASS[$metric]],
            default => throw new RuntimeException('Vector indexes are only supported by MariaDB and Postgres.'),
        };
    }

    /**
     * Encode the vector as the JSON text both drivers accept.
     */
    public static function encode(Arrayable|array $vector): string
    {
        return json_encode($vector instanceof Arrayable ? $vector->toArray() : $vector, JSON_THROW_ON_ERROR);
    }

    private static function validate(string $metric): string
    {
        if (! isset(self::PGSQL_OPERATOR_CLASS[$metric])) {
            throw new InvalidArgumentException("Unsupported vector distance metric [{$metric}]. Use [cosine] or [euclidean].");
        }

        return $metric;
    }
}
