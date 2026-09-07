<?php

namespace Innoboxrr\SearchSurge\Tests\Unit;

use Illuminate\Support\Facades\Gate;
use Innoboxrr\SearchSurge\Search\Builder;
use Innoboxrr\SearchSurge\Search\Support\FilterRegistry;
use Innoboxrr\SearchSurge\Tests\Models\Filters\TestModel\ManagedFilter;
use Innoboxrr\SearchSurge\Tests\Models\TestModel;
use Innoboxrr\SearchSurge\Tests\Models\TestUser;
use Innoboxrr\SearchSurge\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ManagedTest extends TestCase
{
    protected function builder(): Builder
    {
        return $this->app->make(Builder::class);
    }

    protected function actingAsUser(int $id = 3): TestUser
    {
        $user = TestUser::query()->create(['id' => $id, 'name' => 'ana']);

        $this->be($user);

        return $user;
    }

    #[Test]
    public function sin_managed_no_se_acota_nada(): void
    {
        $this->actingAsUser();

        $sql = $this->builder()->query(TestModel::class, [])->toSql();

        $this->assertStringNotContainsString('owner_id', $sql);
    }

    #[Test]
    public function con_managed_se_acota_al_usuario(): void
    {
        $this->actingAsUser(3);

        $sql = $this->builder()->query(TestModel::class, ['managed' => true])->toSql();

        $this->assertStringContainsString('"owner_id" = ?', $sql);
    }

    #[Test]
    public function sin_usuario_autenticado_no_se_acota(): void
    {
        $sql = $this->builder()->query(TestModel::class, ['managed' => true])->toSql();

        $this->assertStringNotContainsString('owner_id', $sql);
    }

    #[Test]
    public function la_cadena_false_en_managed_no_activa_la_restriccion(): void
    {
        $this->actingAsUser();

        $sql = $this->builder()->query(TestModel::class, ['managed' => 'false'])->toSql();

        $this->assertStringNotContainsString('owner_id', $sql);
    }

    #[Test]
    public function la_cadena_false_en_except_view_any_no_salta_la_restriccion(): void
    {
        // Este era el fallo: 'false' == true daba verdadero, asi que
        // ?except_view_any=false acababa saltandose la comprobacion de permisos
        // justo cuando se pedia lo contrario.
        $this->actingAsUser();

        $sql = $this->builder()->query(TestModel::class, [
            'managed' => true,
            'except_view_any' => 'false',
        ])->toSql();

        $this->assertStringContainsString('"owner_id" = ?', $sql);
    }

    #[Test]
    public function con_except_view_any_y_permiso_global_no_se_acota(): void
    {
        $this->actingAsUser();

        Gate::define('viewAny', fn ($user, $model = null): bool => true);

        $sql = $this->builder()->query(TestModel::class, [
            'managed' => true,
            'except_view_any' => true,
        ])->toSql();

        $this->assertStringNotContainsString('owner_id', $sql);
    }

    #[Test]
    public function con_except_view_any_y_sin_permiso_global_si_se_acota(): void
    {
        $this->actingAsUser();

        Gate::define('viewAny', fn ($user, $model = null): bool => false);

        $sql = $this->builder()->query(TestModel::class, [
            'managed' => true,
            'except_view_any' => true,
        ])->toSql();

        $this->assertStringContainsString('"owner_id" = ?', $sql);
    }

    #[Test]
    public function el_filtro_de_autorizacion_se_aplica_antes_que_el_resto(): void
    {
        $filters = $this->app->make(FilterRegistry::class)
            ->resolve(TestModel::class);

        $this->assertSame(
            ManagedFilter::class,
            $filters[0]
        );
    }
}
