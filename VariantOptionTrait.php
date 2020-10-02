<?php 

namespace Avask\Traits;

use Avask\Models\Utilities\VariantOption;
use Avask\Models\Utilities\VariantOptionTransaction;

trait VariantOptionTrait
{
    public function attachVariantOption($variant_options)
    {
        foreach($variant_options as $variant_option){
            $variant_option_transaction = new VariantOptionTransaction();
            $variant_option_transaction->variant_options_id = $variant_option->variant_options_id;
            $this->variantOptionTransaction()->save($variant_option_transaction);
        }
        
        return true;
    }
    
    public function addVariantOption()
    {
        $variant_option = VariantOption::firstOrCreate([
                            'product_option_id' => $this->product_option_id,
                            'product_option_value_id' => $this->id,
                        ]);
                        
        return $variant_option;
    }
}