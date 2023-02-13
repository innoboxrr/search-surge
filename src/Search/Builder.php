<?php

namespace Innoboxrr\SearchSurge\Search;

use Illuminate\Http\Request;

class Builder
{

	/** Start params */
	
	protected $modelClass;

	protected $modelName;

	protected $modelQuery;

	protected $filters;

	protected $filtersPath = 'Models\Filters';

	protected $filtersRealPath;

	protected $filtersNamespace = 'App\Models\Filters';

	protected $request;

	/** End params */

	/** Start setters */

	protected function setOptions($options = []) 
	{

		if(array_key_exists('filtersPath', $options)) {

			$this->setFiltersPath($options['filtersPath']);

		}

		if(array_key_exists('filtersNamespace', $options)) {

			$this->setFiltersNamespace($options['filtersNamespace']);

		}

	}

	protected function setModelClass($model) 
	{

		$this->modelClass = app($model);

	}

	protected function setModelName()
	{

		$this->modelName = basename(str_replace('\\', '/', get_class($this->modelClass)));

	}

	protected function setModelQuery()
	{

		$this->modelQuery = $this->modelClass->newQuery();

	}

	protected function setFiltersPath($path)
	{
		
		$this->filtersPath = $path;

	}

	protected function setFiltersRealPath()
	{

		$this->filtersRealPath = app_path($this->filtersPath . '/' . $this->modelName);

	}

	protected function setFilters()
	{

		// Arreglo para almacenar el nombre de los filtros
		$filtersNames = [];

		// Verificar que el directorio que deseamos inspeccionar exista
		if(file_exists($this->filtersRealPath)){

			// Recuperar en un arreglo todos los filtros disponibles en el directorio
			$allFilters = scandir($this->filtersRealPath);

			// Quitar del arreglo ./ y ../
			$filters = array_diff($allFilters, array('.', '..'));

			// Colcar cada filtro en el arreglo $filtersNames
			foreach ($filters as $key => $filter) {

				$filtersNames[] = preg_replace('/\\.[^.\\s]{3,4}$/', '', $filter);

			}

		}

		$this->filters = $filtersNames;

	}

	protected function setRequest(Request $request)
	{

		$this->request = $request->merge([
        	'model' => $this->modelName,
        	'filtersRealPath' => $this->filtersRealPath,
        	'filtersNamespace' => $this->filtersNamespace,
        	'managedFilterClass' => $this->filtersNamespace . '\\' . $this->modelName . '\ManagedFilter'
        ]);

	}

	protected function setEnv(string $model, Request $request, array $options = [])
	{

		$this->setOptions($options);

		$this->setModelClass($model);

		$this->setModelName();

		$this->setFiltersRealPath();

		$this->setModelQuery();

		$this->setFilters();

		$this->setRequest($request);

		return $this;

	}

	/** End params */

	/** Start logic */

	private function applyFilters()
	{

		foreach ($this->filters as $key => $filter) {
			
			$filter = $this->filtersNamespace . '\\' . $this->modelName .  '\\'. $filter;

			if(class_exists($filter)) {

				$this->modelQuery = $filter::apply($this->modelQuery, $this->request);

			}

		}

		return $this;

	}

	private function paginate()
	{

		return ($this->request->paginate === 0 || $this->request->paginate == '0') ? 
			$this->modelQuery->get() : 
			$this->modelQuery->paginate($this->request->paginate ?? 10);

	}

	/** End logic */

	public function get(string $model, Request $request, array $options = [])
	{

		return $this->setEnv($model, $request, $options)
			->applyFilters()
			->paginate();

	}

}