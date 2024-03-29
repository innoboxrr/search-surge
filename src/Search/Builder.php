<?php

namespace Innoboxrr\SearchSurge\Search;

use Innoboxrr\SearchSurge\Search\Support\DataContainer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Builder
{
    
    /** Start params */

        protected $basePath;
    
        protected $modelClass;
        
        protected $modelName;
        
        protected $modelQuery;
        
        protected $filters;
        
        protected $filtersPath = 'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'Filters';
        
        protected $filtersRealPath;
        
        protected $filtersNamespace = 'App\Models\Filters';
        
        protected $data; // Se asigna en setData
    
    /** End params */

    /** Start setters */

        /**
         * Configurar opciones
         *
         * @param array $options Opciones a configurar
         * @return self
         */
        public function setOptions($options = []): self
        {
            
            if (array_key_exists('filtersPath', $options)) {

                $this->filtersPath = $options['filtersPath'];

            }

            if (array_key_exists('filtersNamespace', $options)) {

                $this->filtersNamespace = $options['filtersNamespace'];

            }

            return $this;

        }

        /**
         * Establecer clase del modelo
         *
         * @param string $model Modelo a utilizar
         * @return self
         */
        protected function setModelClass($model): self
        {

            $this->modelClass = app($model);

            return $this;

        }

        /**
         * Establecer nombre del modelo
         *
         * @return self
         */
        protected function setModelName()
        {

            $this->modelName = basename(str_replace('\\', DIRECTORY_SEPARATOR, get_class($this->modelClass)));

            return $this;

        }

        /**
         * Establecer la ruta base.
         *
         * @param string $basePath Ruta base para los filtros
         * @return self
         */
        public function setBasePath(string $basePath): self
        {
            
            $this->basePath = $basePath;

            return $this;

        }

        /**
         * Establecer ruta real de los filtros
         *
         * @return self
         */
        protected function setFiltersRealPath(): self
        {
            
            $basePath = $this->basePath ?? base_path() . DIRECTORY_SEPARATOR;

            $this->filtersRealPath = $basePath . ($this->filtersPath . DIRECTORY_SEPARATOR . $this->modelName);
            
            return $this;
        }

        /**
         * Establecer consulta del modelo
         *
         * @return self
         */
        protected function setModelQuery()
        {
            
            $this->modelQuery = $this->modelClass->newQuery();
            
            return $this;

        }

        /**
         * Establecer filtros
         *
         * @return self
         */
        protected function setFilters()
        {
            
            $this->filters = $this->getCleanFilters();
            
            return $this;

        }

        /**
         * Obtener filtros limpios
         *
         * @return array
         */
        private function getCleanFilters(): array
        {
            // Incluye el nombre del modelo en la clave del caché para diferenciar los conjuntos de filtros
            $cacheKey = 'filters_names_for_' . $this->modelName;

            // Determina la duración del caché, por ejemplo, 24 horas
            $cacheDuration = 60 * 24; // 1440 minutos = 24 horas

            // Intenta obtener los nombres de los filtros desde el caché
            $filtersNames = Cache::remember($cacheKey, $cacheDuration, function () {
                $filtersNames = [];

                if (file_exists($this->filtersRealPath)) {
                    $allFilters = scandir($this->filtersRealPath);
                    $filters = array_diff($allFilters, ['.', '..']);

                    foreach ($filters as $filter) {
                        // Limpiar el nombre del filtro, quitando la extensión del archivo
                        $filtersNames[] = preg_replace('/\\.[^.\\s]{3,4}$/', '', $filter);
                    }
                }

                return $filtersNames;
            });

            return $filtersNames;
        }


        /**
         * Establecer data
         *
         * @param array $data Datos a establecer
         * @return self
         */
        protected function setData(array $data)
        {   

            $customData = [
                'model' => $this->modelName,
                'modelClass' => $this->modelClass,
                'filtersRealPath' => $this->filtersRealPath,
                'filtersNamespace' => $this->filtersNamespace,
                'managedFilterClass' => $this->filtersNamespace . '\\' . $this->modelName . '\ManagedFilter',
            ];

            $mergeData = array_merge($data, $customData);

            $this->data = new DataContainer($mergeData);

            return $this;
        }

    /** End params */

    /** Start logic */

        /**
         * Aplicar filtros a la consulta
         *
         * @return self
         */
        private function applyFilters()
        {
            foreach ($this->filters as $key => $filter) {
                $filterClass = $this->filtersNamespace . '\\' . $this->modelName .  '\\'. $filter;
                
                // Asumiendo que la clase existe, directamente aplicamos el filtro
                try {
                    $this->modelQuery = $filterClass::apply($this->modelQuery, $this->data);
                } catch (\Error $e) {
                    // Manejar el error de forma que no interrumpa la aplicación
                    Log::error("Filtro [$filterClass] no aplicado: " . $e->getMessage());
                }
            }
        
            return $this;
        }
        

        /**
         * Ejecutar la búsqueda
         *
         * @return mixed Resultado de la búsqueda
         */
        private function executeSearch()
        {

            return ($this->data->paginate === 0 || $this->data->paginate == '0') ?
                $this->modelQuery->get() :
                $this->modelQuery->paginate($this->data->paginate ?? 10);

        }

    /** End logic */

    /**
     * Realizar la búsqueda
     *
     * @param string $model Modelo en el cual realizar la búsqueda
     * @param array $data Datos entrantes
     * @param array $options Opciones adicionales para la búsqueda
     * @return mixed Resultado de la búsqueda
     */
    public function get(string $model, array $data = [], array $options = [])
    {

        return $this->setOptions($options)
            ->setModelClass($model)
            ->setModelName()
            ->setFiltersRealPath()
            ->setModelQuery()
            ->setFilters()
            ->setData($data)
            ->applyFilters()
            ->executeSearch();

    }

}
