<?php

namespace Innoboxrr\SearchSurge\Search\Support;

class DataContainer
{

    private $data;

    public function __construct(array $data)
    {
    
        $this->data = $data;
    
    }

    public function set($name, $value)
    {
     
        $this->data[$name] = $value;
        
    }

    public function has($name)
    {
        return array_key_exists($name, $this->data); // Retorna true si la propiedad existe
    }

    public function __get($name)
    {
    
        return $this->data[$name] ?? null;
    
    }

    public function all()
    {
        return $this->data;
    }

}
