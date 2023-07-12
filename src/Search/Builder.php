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
    protected $filtersPath = 'app' . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'Filters';
    protected $filtersRealPath;
    protected $filtersNamespace = 'App\Models\Filters';
    protected $request;
    /** End params */

    /** Start setters */
    protected function setOptions($options = [])
    {
        if (array_key_exists('filtersPath', $options)) {
            $this->filtersPath = $options['filtersPath'];
        }
        if (array_key_exists('filtersNamespace', $options)) {
            $this->filtersNamespace = $options['filtersNamespace'];
        }
        return $this;
    }

    protected function setModelClass($model)
    {
        $this->modelClass = app($model);
        return $this;
    }

    protected function setModelName()
    {
        $this->modelName = basename(str_replace('\\', DIRECTORY_SEPARATOR, get_class($this->modelClass)));
        return $this;
    }

    protected function setFiltersRealPath()
    {
        $this->filtersRealPath = base_path($this->filtersPath . DIRECTORY_SEPARATOR . $this->modelName);
        return $this;
    }

    protected function setModelQuery()
    {
        $this->modelQuery = $this->modelClass->newQuery();
        return $this;
    }

    protected function setFilters()
    {
        $this->filters = $this->getCleanFilters();
        return $this;
    }

    private function getCleanFilters(): array
    {
        $filtersNames = [];
        if (file_exists($this->filtersRealPath)) {
            $allFilters = scandir($this->filtersRealPath);
            $filters = array_diff($allFilters, ['.', '..']);
            foreach ($filters as $key => $filter) {
                $filtersNames[] = preg_replace('/\\.[^.\\s]{3,4}$/', '', $filter);
            }
        }
        return $filtersNames;
    }

    protected function setRequest(Request $request)
    {
        $this->request = $request->merge([
            'model' => $this->modelName,
            'filtersRealPath' => $this->filtersRealPath,
            'filtersNamespace' => $this->filtersNamespace,
            'managedFilterClass' => $this->filtersNamespace . '\\' . $this->modelName . '\ManagedFilter',
        ]);
        return $this;
    }
    /** End params */

    /** Start logic */
    private function applyFilters()
    {
        foreach ($this->filters as $key => $filter) {
            $filter = $this->filtersNamespace . DIRECTORY_SEPARATOR . $this->modelName . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $filter);
            if (class_exists($filter)) {
                $this->modelQuery = $filter::apply($this->modelQuery, $this->request);
            }
        }
        return $this;
    }

    private function executeSearch()
    {
        return ($this->request->paginate === 0 || $this->request->paginate == '0') ?
            $this->modelQuery->get() :
            $this->modelQuery->paginate($this->request->paginate ?? 10);
    }
    /** End logic */

    public function get(string $model, Request $request, array $options = [])
    {
        return $this->setOptions($options)
            ->setModelClass($model)
            ->setModelName()
            ->setFiltersRealPath()
            ->setModelQuery()
            ->setFilters()
            ->setRequest($request)
            ->applyFilters()
            ->executeSearch();
    }
}
