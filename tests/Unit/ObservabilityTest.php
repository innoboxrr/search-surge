<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Innoboxrr\SearchSurge\Events\SearchExecuted;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Tests\Fixtures\Filters\KeyedNameFilter;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ObservabilityTest extends TestCase
{
    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    #[Test]
    public function emite_un_evento_por_cada_busqueda_ejecutada(): void
    {
        Event::fake([SearchExecuted::class]);

        $this->builder()->get(TestModel::class, ['paginate' => 0]);

        Event::assertDispatched(SearchExecuted::class, function (SearchExecuted $e): bool {
            return $e->model === TestModel::class
                && $e->sql !== ''
                && $e->milliseconds >= 0;
        });
    }

    #[Test]
    public function el_evento_dice_que_filtros_entraron_de_verdad(): void
    {
        Event::fake([SearchExecuted::class]);

        $this->builder()->get(TestModel::class, ['paginate' => 0, 'name' => 'ana'], [
            'filters' => [KeyedNameFilter::class],
        ]);

        Event::assertDispatched(SearchExecuted::class, function (SearchExecuted $e): bool {
            return $e->filters === [KeyedNameFilter::class];
        });
    }

    #[Test]
    public function un_filtro_omitido_no_aparece_en_el_evento(): void
    {
        Event::fake([SearchExecuted::class]);

        $this->builder()->get(TestModel::class, ['paginate' => 0], [
            'filters' => [KeyedNameFilter::class],
        ]);

        Event::assertDispatched(SearchExecuted::class, function (SearchExecuted $e): bool {
            return $e->filters === [];
        });
    }

    #[Test]
    public function el_evento_no_lleva_los_valores_de_los_parametros(): void
    {
        // Los valores pueden ser datos personales y esto acaba en logs que se
        // guardan mucho tiempo. Solo van las claves.
        Event::fake([SearchExecuted::class]);

        $this->builder()->get(TestModel::class, ['paginate' => 0, 'name' => 'ana-maria@ejemplo.com']);

        Event::assertDispatched(SearchExecuted::class, function (SearchExecuted $e): bool {
            $context = $e->context();

            return in_array('name', $e->parameters, true)
                && ! str_contains(json_encode($context) ?: '', 'ana-maria@ejemplo.com');
        });
    }

    #[Test]
    public function el_evento_no_expone_los_datos_internos_del_contenedor(): void
    {
        Event::fake([SearchExecuted::class]);

        $this->builder()->get(TestModel::class, ['paginate' => 0]);

        Event::assertDispatched(SearchExecuted::class, function (SearchExecuted $e): bool {
            return ! in_array('modelClass', $e->parameters, true)
                && ! in_array('managedFilterClass', $e->parameters, true);
        });
    }

    #[Test]
    public function el_evento_cuenta_los_resultados(): void
    {
        TestModel::query()->insert([['name' => 'a'], ['name' => 'b']]);

        Event::fake([SearchExecuted::class]);

        $this->builder()->get(TestModel::class, ['paginate' => 0]);

        Event::assertDispatched(SearchExecuted::class, fn (SearchExecuted $e): bool => $e->results === 2);
    }

    #[Test]
    public function los_eventos_se_pueden_apagar(): void
    {
        config()->set('search-surge.observability.events', false);

        Event::fake([SearchExecuted::class]);

        $this->builder()->get(TestModel::class, ['paginate' => 0]);

        Event::assertNotDispatched(SearchExecuted::class);
    }

    #[Test]
    public function query_no_emite_evento_porque_no_ejecuta_nada(): void
    {
        Event::fake([SearchExecuted::class]);

        $this->builder()->query(TestModel::class, ['paginate' => 0]);

        Event::assertNotDispatched(SearchExecuted::class);
    }

    #[Test]
    public function registra_las_busquedas_por_encima_del_umbral(): void
    {
        config()->set('search-surge.observability.slow_threshold', 0);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'lenta')
                    && $context['model'] === TestModel::class
                    && array_key_exists('ms', $context);
            });

        $this->builder()->get(TestModel::class, ['paginate' => 0]);
    }

    #[Test]
    public function no_registra_nada_por_debajo_del_umbral(): void
    {
        config()->set('search-surge.observability.slow_threshold', 60000);

        Log::shouldReceive('warning')->never();

        $this->builder()->get(TestModel::class, ['paginate' => 0]);
    }

    #[Test]
    public function el_umbral_se_puede_fijar_por_opciones(): void
    {
        Log::shouldReceive('warning')->once();

        $this->builder()->get(TestModel::class, ['paginate' => 0], ['slowThreshold' => 0]);
    }

    #[Test]
    public function isSlow_compara_contra_el_umbral(): void
    {
        $event = new SearchExecuted(TestModel::class, [], [], 'select 1', [], 120.0, 5);

        $this->assertTrue($event->isSlow(100));
        $this->assertFalse($event->isSlow(200));
        $this->assertSame(120.0, $event->context()['ms']);
    }
}
