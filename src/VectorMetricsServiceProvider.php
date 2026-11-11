<?php

namespace Rhaima96\VectorMetrics;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class VectorMetricsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Builder::macro('selectVectorDistanceUsing', function (string $metric, string $column, $vector, ?string $as = null) {
            /** @var \Illuminate\Database\Query\Builder $this */
            [$sql, $bindings] = VectorMetric::distance($this->getGrammar(), $metric, $column, $vector);

            return $this->selectRaw($sql.' as '.$this->getGrammar()->wrap($as ?? $column.'_distance'), $bindings);
        });

        Builder::macro('whereVectorDistanceUsing', function (string $metric, string $column, $vector, float $maxDistance, string $boolean = 'and') {
            /** @var \Illuminate\Database\Query\Builder $this */
            [$sql, $bindings] = VectorMetric::distance($this->getGrammar(), $metric, $column, $vector);

            return $this->whereRaw($sql.' <= ?', [...$bindings, $maxDistance], $boolean);
        });

        Builder::macro('orWhereVectorDistanceUsing', function (string $metric, string $column, $vector, float $maxDistance) {
            /** @var \Illuminate\Database\Query\Builder $this */
            return $this->whereVectorDistanceUsing($metric, $column, $vector, $maxDistance, 'or');
        });

        Builder::macro('orderByVectorDistanceUsing', function (string $metric, string $column, $vector) {
            /** @var \Illuminate\Database\Query\Builder $this */
            [$sql, $bindings] = VectorMetric::distance($this->getGrammar(), $metric, $column, $vector);

            return $this->orderByRaw($sql.' asc', $bindings);
        });

        // $m is MariaDB's graph degree (3–200) and is ignored on Postgres.
        Blueprint::macro('vectorIndexUsing', function (string $column, string $metric, ?string $name = null, int $m = 6) {
            /** @var \Illuminate\Database\Schema\Blueprint $this */
            [$algorithm, $operatorClass] = VectorMetric::index($this->grammar, $metric, $m);

            return $this->indexCommand('vectorIndex', $column, $name, $algorithm, $operatorClass);
        });
    }
}
