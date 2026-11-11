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
     * The pgvector distance operator for each metric (%s is the wrapped column).
     */
    private const PGSQL_DISTANCE = [
        'cosine' => '(%s <=> ?)',
        'euclidean' => '(%s <-> ?)',
    ];

    /**
     * The pgvector operator class for each metric.
     */
    private const PGSQL_OPERATOR_CLASS = [
        'cosine' => 'vector_cosine_ops',
        'euclidean' => 'vector_l2_ops',
    ];

    /**
     * Compile the distance expression and its bindings for the given query grammar.
     *
     * On MariaDB the query vector is written as a binary (float32 little-endian) hex literal instead of
     * vec_fromtext(?): MariaDB converts a constant vec_fromtext() again for every row in the SELECT list
     * and ORDER BY (MDEV-42201), which makes a scan of 20,000 rows about 40 times slower. The literal
     * is built from floats only, so it cannot carry SQL.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    public static function distance(object $grammar, string $metric, string $column, Arrayable|array $vector): array
    {
        $metric = self::validate($metric);
        $column = $grammar->wrap($column);

        return match (true) {
            $grammar instanceof MariaDbGrammar => ["vec_distance_{$metric}({$column}, x'".self::hex($vector)."')", []],
            $grammar instanceof PostgresGrammar => [sprintf(self::PGSQL_DISTANCE[$metric], $column), [self::encode($vector)]],
            default => throw new RuntimeException('Vector distance metrics are only supported by MariaDB and Postgres.'),
        };
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

    /**
     * Encode the vector as hex float32 little-endian, MariaDB's binary vector format.
     */
    public static function hex(Arrayable|array $vector): string
    {
        $values = array_map('floatval', array_values($vector instanceof Arrayable ? $vector->toArray() : $vector));

        return bin2hex(pack('g*', ...$values));
    }

    private static function validate(string $metric): string
    {
        if (! isset(self::PGSQL_OPERATOR_CLASS[$metric])) {
            throw new InvalidArgumentException("Unsupported vector distance metric [{$metric}]. Use [cosine] or [euclidean].");
        }

        return $metric;
    }
}
