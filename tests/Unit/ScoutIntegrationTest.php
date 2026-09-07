<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Tests\Fixtures\Engine\LimitedScoutFilter;
use Innoboxrr\SearchSurge\Tests\Fixtures\Engine\ScoutEngineFilter;
use Innoboxrr\SearchSurge\Tests\Models\SearchableModel;
use Innoboxrr\SearchSurge\Tests\Models\TestUser;
use Innoboxrr\SearchSurge\Tests\TestCase;
use Laravel\Scout\ScoutServiceProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * El camino por defecto de EngineFilter, el que usa Laravel Scout.
 *
 * Hasta ahora de ese metodo solo se probaba la rama de error -"el modelo no es
 * buscable"-, asi que la via que documenta el paquete como "no hace falta nada
 * mas" no la habia ejecutado nunca nadie. Y es la puerta de entrada a
 * Elasticsearch, Algolia, Meilisearch y Typesense.
 *
 * Se usa el driver `collection` de Scout, que resuelve la busqueda con el propio
 * Eloquent: no hace falta levantar un motor para comprobar que el hibrido
 * encaja. Lo que se verifica es el contrato -que se llama a search(), que se
 * respeta el limite, que los ids acotan la consulta y mandan en el orden-, no la
 * relevancia de ningun motor concreto.
 */
class ScoutIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [ScoutServiceProvider::class]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('scout.driver', 'collection');
    }

    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    protected function seedSearchable(): void
    {
        SearchableModel::query()->insert([
            ['id' => 1, 'name' => 'zapato rojo', 'owner_id' => 1],
            ['id' => 2, 'name' => 'zapato azul', 'owner_id' => 2],
            ['id' => 3, 'name' => 'camisa roja', 'owner_id' => 1],
            ['id' => 4, 'name' => 'zapato verde', 'owner_id' => 2],
        ]);
    }

    /* -----------------------------------------------------------------
     | El contrato con Scout
     | ----------------------------------------------------------------- */

    #[Test]
    public function el_filtro_por_defecto_delega_en_scout(): void
    {
        $this->seedSearchable();

        $names = $this->builder()->get(
            SearchableModel::class,
            ['q' => 'zapato', 'paginate' => 0],
            ['filters' => [ScoutEngineFilter::class]]
        )->pluck('name')->all();

        $this->assertCount(3, $names);
        $this->assertNotContains('camisa roja', $names);
    }

    #[Test]
    public function sin_termino_no_se_consulta_al_motor(): void
    {
        $this->seedSearchable();

        $count = $this->builder()->get(
            SearchableModel::class,
            ['paginate' => 0],
            ['filters' => [ScoutEngineFilter::class]]
        )->count();

        $this->assertSame(4, $count);
    }

    #[Test]
    public function si_el_motor_no_encuentra_nada_devuelve_cero_y_no_todo(): void
    {
        $this->seedSearchable();

        $count = $this->builder()->get(
            SearchableModel::class,
            ['q' => 'sombrero', 'paginate' => 0],
            ['filters' => [ScoutEngineFilter::class]]
        )->count();

        $this->assertSame(0, $count);
    }

    #[Test]
    public function el_limite_llega_al_motor(): void
    {
        $this->seedSearchable();

        $count = $this->builder()->get(
            SearchableModel::class,
            ['q' => 'zapato', 'paginate' => 0],
            ['filters' => [LimitedScoutFilter::class]]
        )->count();

        // Hay tres zapatos, pero el filtro solo pide dos ids.
        $this->assertSame(2, $count);
    }

    /* -----------------------------------------------------------------
     | Lo que hace valioso al híbrido
     | ----------------------------------------------------------------- */

    #[Test]
    public function los_filtros_sql_siguen_recortando_lo_que_devolvio_scout(): void
    {
        // Esta es la razon de ser del hibrido: el motor pone la relevancia y la
        // base sigue mandando en el resto, incluida la autorizacion.
        $this->seedSearchable();

        $names = $this->builder()->get(
            SearchableModel::class,
            ['q' => 'zapato', 'paginate' => 0],
            [
                'filters' => [ScoutEngineFilter::class],
                'query' => SearchableModel::query()->where('owner_id', 2),
            ]
        )->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['zapato azul', 'zapato verde'], $names);
    }

    #[Test]
    public function el_orden_del_motor_manda_sobre_el_de_la_base(): void
    {
        $this->seedSearchable();

        $ids = $this->builder()->get(
            SearchableModel::class,
            ['q' => 'zapato', 'paginate' => 0],
            ['filters' => [ScoutEngineFilter::class]]
        )->pluck('id')->all();

        // Scout decide el orden; la consulta lo conserva en vez de reordenar
        // por clave primaria.
        $this->assertSame(
            SearchableModel::search('zapato')->take(500)->keys()->all(),
            $ids
        );
    }

    #[Test]
    public function se_combina_con_la_paginacion(): void
    {
        $this->seedSearchable();

        $page = $this->builder()->get(
            SearchableModel::class,
            ['q' => 'zapato', 'paginate' => 2, 'page' => 1],
            ['filters' => [ScoutEngineFilter::class]]
        );

        $this->assertSame(3, $page->total());
        $this->assertCount(2, $page->items());
    }

    #[Test]
    public function un_modelo_que_no_usa_scout_lo_dice_con_claridad(): void
    {
        // El mensaje tiene que explicar que falta, no reventar con un
        // "Call to undefined method".
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no es buscable');

        $this->builder()->get(
            TestUser::class,
            ['q' => 'algo', 'paginate' => 0],
            ['filters' => [ScoutEngineFilter::class]]
        );
    }
}
