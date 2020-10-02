<?php 

namespace Avask\Traits;
use Avask\Models\Inventory\Storage;

/**
 * Trait StorageTrait.
 */
trait StorageTrait
{
    public function createStorage()
    {
        if($this->storage == null){
            $storage = new Storage();
            return $this->storage()->save($storage);
        }
            
        return $this->storage;
    }
}