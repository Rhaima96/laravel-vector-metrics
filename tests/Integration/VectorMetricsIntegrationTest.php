<?php

namespace Rhaima96\VectorMetrics\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Attributes\RequiresDatabase;
use Orchestra\Testbench\TestCase;
use Rhaima96\VectorMetrics\VectorMetricsServiceProvider;

#[RequiresDatabase(['mariadb', 'pgsql'])]
class VectorMetricsIntegrationTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [VectorMetricsServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() === 'pgsql') {
            Schema::ensureVectorExtensionExists();
        }

        Schema::dropIfExists('documents');
        Schema::create('documents', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->vector('embedding', 3);
            $table->vectorIndexUsing('embedding', 'euclidean');
        });

        foreach (['near' => [1, 0, 0], 'far' => [4, 0, 0], 'middle' => [0, 2, 0]] as $name => $vector) {
            $json = json_encode($vector);

            DB::table('documents')->insert([
                'name' => $name,
                'embedding' => DB::getDriverName() === 'mariadb' ? DB::raw("vec_fromtext('{$json}')") : $json,
            ]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('documents');

        parent::tearDown();
    }

    public function testEuclideanDistance()
    {
        $results = DB::table('documents')
            ->select('name')
            ->selectVectorDistanceUsing('euclidean', 'embedding', [0, 0, 0])
            ->whereVectorDistanceUsing('euclidean', 'embedding', [0, 0, 0], 3)
            ->orderByVectorDistanceUsing('euclidean', 'embedding', [0, 0, 0])
            ->get();

        $this->assertSame(['near', 'middle'], $results->pluck('name')->all());
        $this->assertEqualsWithDelta(1.0, (float) $results[0]->embedding_distance, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $results[1]->embedding_distance, 0.0001);
    }

    public function testCosineDistance()
    {
        // "near" and "far" point the same way, so their cosine distance to [1, 0, 0] is 0.
        $names = DB::table('documents')
            ->whereVectorDistanceUsing('cosine', 'embedding', [1, 0, 0], 0.01)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $this->assertSame(['far', 'near'], $names);
    }
}
