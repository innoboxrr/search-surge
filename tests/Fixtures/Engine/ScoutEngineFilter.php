<?php

namespace Innoboxrr\SearchSurge\Tests\Fixtures\Engine;

use Innoboxrr\SearchSurge\Search\Filters\EngineFilter;

/**
 * Sin sobrescribir ids(): usa la implementacion por defecto, la que llama a
 * Scout. Es el uso que documenta el paquete como "no hace falta nada mas".
 */
class ScoutEngineFilter extends EngineFilter {}
