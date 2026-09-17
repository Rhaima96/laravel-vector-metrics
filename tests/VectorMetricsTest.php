<?php

namespace Rhaima96\VectorMetrics\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Rhaima96\VectorMetrics\VectorMetricsServiceProvider;
use RuntimeException;

class VectorMetricsTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [VectorMetricsServiceProvider::class];
    }

    public static function queries(): array
    {
        return [
            'mariadb euclidean' => [MariaDbConnection::class, 'euclidean', 'vec_distance_euclidean(`embedding`, vec_fromtext(?))'],
            'mariadb cosine' => [MariaDbConnection::class, 'cosine', 'vec_distance_cosine(`embedding`, vec_fromtext(?))'],
            'pgsql euclidean' => [PostgresConnection::class, 'euclidean', '("embedding" <-> ?)'],
            'pgsql cosine' => [PostgresConnection::class, 'cosine', '("embedding" <=> ?)'],
        ];
    }

    #[DataProvider('queries')]
    public function testQueryMacros(string $connection, string $metric, string $distance)
    {
        $query = $this->connection($connection)->query()
            ->from('documents')
            ->selectVectorDistanceUsing($metric, 'embedding', [1, 2, 3])
            ->whereVectorDistanceUsing($metric, 'embedding', [1, 2, 3], 0.5)
            ->orWhereVectorDistanceUsing($metric, 'embedding', [4, 5, 6], 0.25)
            ->orderByVectorDistanceUsing($metric, 'embedding', collect([1, 2, 3]));

        $wrap = fn ($value) => $connection === MariaDbConnection::class ? "`{$value}`" : "\"{$value}\"";

        $this->assertSame(
            "select {$distance} as {$wrap('embedding_distance')} from {$wrap('documents')} where {$distance} <= ? or {$distance} <= ? order by {$distance} asc",
            $query->toSql()
        );
        $this->assertSame(['[1,2,3]', '[1,2,3]', 0.5, '[4,5,6]', 0.25, '[1,2,3]'], $query->getBindings());
    }

    public function testMariaDbVectorIndex()
    {
        $blueprint = new Blueprint($this->connection(MariaDbConnection::class), 'documents');
        $blueprint->vectorIndexUsing('embedding', 'euclidean', m: 16);

        $this->assertSame(
            ['alter table `documents` add vector index `documents_embedding_vectorindex`(`embedding`) M=16 DISTANCE=euclidean'],
            $blueprint->toSql()
        );
    }

    public function testPostgresVectorIndex()
    {
        $blueprint = new Blueprint($this->connection(PostgresConnection::class), 'documents');
        $blueprint->vectorIndexUsing('embedding', 'euclidean', 'documents_l2');

        $this->assertSame(
            ['create index "documents_l2" on "documents" using hnsw ("embedding" vector_l2_ops)'],
            $blueprint->toSql()
        );
    }

    public function testUnsupportedMetricThrows()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->connection(MariaDbConnection::class)->query()->from('documents')
            ->orderByVectorDistanceUsing('manhattan', 'embedding', [1, 2, 3]);
    }

    public function testUnsupportedDriverThrows()
    {
        $this->expectException(RuntimeException::class);

        $this->connection(MySqlConnection::class)->query()->from('documents')
            ->orderByVectorDistanceUsing('euclidean', 'embedding', [1, 2, 3]);
    }

    private function connection(string $class): Connection
    {
        // The PDO resolver is never called: these tests only compile SQL.
        $connection = new $class(fn () => null);
        $connection->useDefaultSchemaGrammar();

        return $connection;
    }
}
