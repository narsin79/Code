<?php 

namespace Avask\Traits\Inventory;

use Avask\Traits\OrderTrait;
use Avask\Traits\User\UserTrait;
use Avask\Models\Inventory\InventoryTransaction;
use Avask\Models\Inventory\InventoryTransactionLog;
use Avask\Models\Inventory\Storage;
use Avask\Models\Products\ProductOptionValue;
use Avask\Models\Products\ProductVariant;
use Avask\Models\Products\Product;
use Avask\Models\Subscriptions\Subscription;
use Avask\Models\Orders\Order;
use Avask\Models\Chips\TemporaryEmployeeTransaction;
use Avask\Models\Utilities\VariantOption;
use Avask\Models\Utilities\VariantOptionTransaction;
use Carbon\Carbon;
use DB;

trait InventoryTrait
{
    use OrderTrait;
    use UserTrait;
    
    public function inventoryTransactions()
    {
        $transactions = collect([]);
        if($this->storageExists()){
            $deposits = $this->storage->toTransactions;
            $withdraws = $this->storage->fromTransactions;
            $transactions = $deposits->merge($withdraws);
        }
        return $transactions;
    }

    public function inventoryTransactionLogs()
    {
        $transactions = $this->inventoryTransactions();
        $logs = collect([]);
        foreach ($transactions as $transaction) {
            $t_logs = $transaction->transaction_logs;
            $logs = $logs->merge($t_logs);
        }
        return $logs;
    }
    
    public function hasInventoryItems()
    {
        // if(count($this->inventoryTransactionLogs()) > 0){
        //     return true;
        // }
        // return false;
        if ($this->isInventoryEmpty()) return false;
        return true; 
    }
    
    public function isInventoryEmpty()
    {
        if ($this->isTemporaryEmployee()) {
            $transactions = TemporaryEmployeeTransaction::where('employee_id', $this->id)->whereNull('ended_at')->count(); 
            if ($transactions > 0) return false; 
        }else {
            $logs = InventoryTransactionLog::OfStorage([$this->storage->id])
                    ->join('inventory_transactions as it', 'it.id', '=', 'inventory_transaction_logs.inventory_transaction_id')
                    ->select(DB::raw("(SUM(IF(inventory_transaction_logs.action not in ('REVOKE','DISCARDED'), processed_quantity, 0)) - SUM(IF(inventory_transaction_logs.action in ('REVOKE','DISCARDED'), processed_quantity, 0))) as storage_amount"))
                    ->first();

            if($logs && $logs->storage_amount > 0){
                return false;
            }
        }        
        
        return true;
    }
    
    public function inventoryActionDifferenceCount()
    {
        return ($this->assigned()->sum('processed_quantity') + $this->reassigned()->sum('processed_quantity')) - $this->revoked()->sum('processed_quantity');
    }
    
    public function assigned()
    {
        return $this->inventoryTransactionLogs()->where('action', 'ASSIGN');
    }

    public function reassigned()
    {
        return $this->inventoryTransactionLogs()->where('action', 'REASSIGN');
    }
    
    public function revoked()
    {
        return $this->inventoryTransactionLogs()->where('action', 'REVOKE');
    }
    
    public function storageExists()
    {
        if(isset($this->storage) && $this->storage){
            return true;
        }
        return false;
    }
    
    public function inventory($product_variant_id = null)
    {
        if($this->storageExists())
        {
            $inventory_products = [];
            
            $subscriptions = $this->customer->subscriptions()
                        ->join('products as p','p.id','=','subscriptions.product_id')
                        ->join('product_variants as pv','pv.id','=','subscriptions.product_variant_id')
                        ->join('product_categories as pc','pc.id','=','p.product_category_id')
                        ->where('subscriptions.visible_on_employee_list', 1);
                        
            if($product_variant_id){
                $subscriptions = $subscriptions->where('pv.id', $product_variant_id);
            }        
                        

            $subscriptions = $subscriptions->orderBy('pc.name')->orderBy('pv.name')->get();
            foreach ($subscriptions as $subscription) {
                $item = ($subscription->variant) ? $subscription->variant : $subscription->product;
                $transactions = $item->inventoryTransactions()->OfStorage([$this->storage->id])->orderBy('created_at')->get();

                if(count($transactions) > 0) {
                    $quantity= 0;
                    $variant_options = null;
                    $size = false;
                    foreach($transactions as $transaction) {
                        $transaction_size = $transaction->inventory_transaction->variantOptionTransaction()
                                            ->orderBy('created_at','desc')->first();
                        if ($transaction_size) {
                            $transaction_size = $transaction_size->variantOption->optionValue();
                        } else {
                            $transaction_size = $transaction->inventory_transaction->product_id . "-";
                        }
                        if ($size != $transaction_size){
                            $size = $transaction_size;
                            $quantity = 0;
                        }
                        if($transaction->action == 'REVOKE'){
                            $quantity = $quantity - $transaction->processed_quantity;
                        }else{
                            $quantity = $quantity + $transaction->processed_quantity;
                        }

                        $variant_options = $transaction->inventory_transaction->variantOptionTransaction()->lists('variant_options_id')->toArray();
                    }
                    
                    if($subscription->variant)
                    {
                        $item->product_variant_id = $item->id;
                    }else{
                        $item->product_id = $item->id;
                        $item->product_variant_id = $item->product_variant_id;
                    }
                    
                    $item->variant_options = $variant_options;
                    $item->storage_amount = $quantity;
                    $item->actual_size = $size;
                    if($quantity > 0){
                        $inventory_products[] = $item;
                    }
                }
            }
            
            return $inventory_products;
        }
        
        return null;
    }
    
    public function getInventoryItems($product_variant_id = null, $size = null, $ignoreReplaced = true, $includePooled = true, $until_date = null)
    {
        if($this->storageExists())
        {
            $inventory_products = InventoryTransactionLog::
                OfStorage([$this->storage->id])
                ->with('inventory_transaction','inventory_transaction.productVariant', 'inventory_transaction.productVariant.images')
                ->select('o.subscription_id', 's.take_from_subscription_id', 'pv.*', 'vot.value as size', 
                    DB::raw("(SUM(IF(inventory_transaction_logs.action != 'REVOKE' and inventory_transaction_logs.action != 'DISCARDED', processed_quantity, 0)) - 
                                SUM(IF(inventory_transaction_logs.action = 'REVOKE' or inventory_transaction_logs.action = 'DISCARDED', processed_quantity, 0))) as storage_amount, 
                        IF(s.take_from_subscription_id IS NOT NULL and s.take_from_subscription_id > 0, 1, 0) as is_pooled"),
                     'vot.variant_options_id', 'inventory_transaction_logs.*', 'o.product_variant_id','s.chip_based')
                ->join('inventory_transactions as it', 'it.id', '=', 'inventory_transaction_logs.inventory_transaction_id')
                ->join('products as p', 'p.id', '=', 'it.product_id')
                ->leftJoin('product_variants as pv', 'pv.id', '=', 'it.product_variant_id')   
                ->join('inventory_requests as ir' ,'ir.id', '=', 'it.inventory_request_id')
                ->join('orders as o','o.id','=','ir.order_id')
                ->join('subscriptions as s','s.id','=','o.subscription_id')
                ->leftJoin(DB::raw("(SELECT optionable_id, optionable_type, variant_options_id, ov.value as size, vo.product_option_id, vot.id, ov.value, vo.product_option_value_id,ov.sort_order
                                     FROM variant_options_transactions vot
                                     JOIN `variant_options` AS `vo` ON `vo`.`id` = `vot`.`variant_options_id`
                                       JOIN `product_option_values` AS `pov` ON `pov`.`id` = `vo`.`product_option_value_id`
                                       JOIN `option_values` AS `ov` ON `ov`.`id` = `pov`.`option_value_id` WHERE  optionable_type = 'inventory_order_transaction'
                                   )  AS vot"),'vot.optionable_id','=','o.id')
                // ->leftJoin('variant_options as vo', 'vo.id', '=', 'vot.variant_options_id')
                // ->leftJoin('product_option_values as pov', 'pov.id', '=', 'vo.product_option_value_id')
                // ->leftJoin('option_values as ov', 'ov.id', '=', 'pov.option_value_id')
                // ->leftJoin('variant_options_transactions as vot', function($query)
                // {
                //     $query->on('vot.optionable_id', '=', 'it.id')->where('vot.optionable_type', '=', 'inventory_transaction');
                // })
                ->whereNull('it.deleted_at')
                ->groupBy('it.product_variant_id', 'vot.size')
                ->orderBy('pv.name', 'ASC')
                ->orderBy('vot.sort_order', 'ASC');
            
            if($product_variant_id){
                $inventory_products = $inventory_products->where('pv.id', $product_variant_id);
            }
            
            if($size){
                $inventory_products = $inventory_products->where('vot.size', $size);
            }

            if ($ignoreReplaced) {
                $inventory_products = $inventory_products->where('ir.action', '!=', DB::raw('\'REPLACE\''));
            }
            
            if ($includePooled == false) {
                $inventory_products = $inventory_products->whereRaw('s.take_from_subscription_id IS NULL');
            }

            if ($until_date) {
                $inventory_products = $inventory_products->whereDate('inventory_transaction_logs.created_at', '<=', $until_date.' 23:59:00');
            }

            $inventory_products = $inventory_products->get();
            
            foreach($inventory_products as $key=>$item){
                $images = [];
                if ($item->inventory_transaction) {
                    $images = $item->inventory_transaction->productVariant->images();
                }
                $image = (count($images) > 0) ? $images->first() : null;
                

                if ($image)
                    $item->default_image = $item->inventory_transaction->productVariant->images()->first();  
                else 
                    $item->default_image = '';

                if($item->storage_amount > 0) {
                    $item->variant_options = [$item->variant_options_id];
                }else {
                    //do not include items with 0 storage amount
                     $inventory_products = $inventory_products->keyBy('id');
                     $inventory_products->forget($item->id);
                }                
            }

            return $inventory_products;
        }
        
        return null;
    }
    
    /**
	 * Recalls inventory items
	 * @param  collection       $inventory_items 
	 * @param  int              $quantity 
     * @return collection       $generated_orders 
	 **/

    public function recallInventoryItems($inventory_items = null, $quantity = null)
    {
        $inventory_items = isset($inventory_items) ? $inventory_items : $this->getInventoryItems();
        $generated_orders = [];
        
        DB::transaction(function() use($inventory_items, $quantity, &$generated_orders) {

            foreach($inventory_items as $item)
            {
                if (class_basename ($this) == 'Customer') {
                    $customer_id = $this->id;
                    $employee_id = null;
                }else{
                    $customer_id = $this->customer->id;
                    $employee_id = $this->id;
                }
                
                $detail = [
                    'customer_id'   => $customer_id,
                    'employee_id'   => $employee_id,
                    'order_date'    => Carbon::now(),
                    'product_id'    => $item->product_id,
                    'product_variant_id'=> $item->product_variant_id,
                    'quantity'      => $item->storage_amount,
                    'approved' => 1,
                    'processed_at' => Carbon::now(),
                    'status' => 'Pending',
                    'user_id' => $this->getCurrentUserId(),
                    'is_inventory_order' => 1,
                    'chip_based' => $item->chip_based,
                    'due_date' => ($this->end_date && Carbon::now()->gte(Carbon::parse($this->end_date)->subWeeks(2))) ? $this->end_date : NULL // only add due dates if the employee's end date is 2 after now
                ];
                
                $recall_orders = $this->recallOrderCount($detail['product_variant_id'], $detail['employee_id'], $item->variant_options);
                
                $recall_count = 0;
                $amount_processed = 0;

                if($recall_orders){
                    foreach($recall_orders as $recall_order){
                        $recall_count  += $recall_order->quantity;
                        $amount_processed  += $recall_order->amountProcessed();

                        if ($recall_order->due_date){
                            $recall_order->due_date = $detail['due_date'];
                            $recall_order->save();
                        }
                        
                    }
                }
                
                $difference = ($item->storage_amount + $amount_processed) - $recall_count;

                if($difference > 0){
                    if($item->is_pooled){
                        $registeredChips            = $this->getChips($item->product_variant_id, $item->size)->count();
                        $inventoryChipDifference    = $difference - $registeredChips;
                        
                        if($quantity){
                            if($quantity == $inventoryChipDifference){
                                $inventoryChipDifference    = $quantity;
                                $registeredChips            = 0;
                            }else if($quantity > $inventoryChipDifference){
                                $registeredChips            = $quantity - $inventoryChipDifference;
                            }else if($quantity < $inventoryChipDifference){
                                $inventoryChipDifference    = $quantity;
                                $registeredChips            = 0;
                            }
                        }

                        //Generate return order equal to the chip registration count as pending
                        if($registeredChips > 0){
                            $detail['quantity'] = $registeredChips;
                            $order = $this->generateOrder($detail, $item->variant_options, 'ADD');
                            if($order) array_push($generated_orders,  $order);
                        }
                        
                        //Generate return order equal to the difference of inventory and chip count then process
                        $detail['quantity'] = ($inventoryChipDifference == 0) ? $difference : $inventoryChipDifference;
                        
                        if($inventoryChipDifference != 0 && $detail['quantity'] > 0){
                            
                            $order = $this->generateOrder($detail, $item->variant_options, 'ADD');
                            if($order){
                                //Process return orders immediately if that subscription is taken from pooled
                                if($item->take_from_subscription_id){
                                    $subscription = Subscription::find($item->take_from_subscription_id);
                                    $storage_id = ($subscription->customer->storage) ? $subscription->customer->storage->id : null;
                                    $order->process($order->quantity, $storage_id, null);
                                }
                                array_push($generated_orders,  $order);
                            }
                        }
                    }else{
                        $detail['quantity'] = ($quantity) ? $quantity : $difference;
                        $order = $this->generateOrder($detail, $item->variant_options, 'ADD');
                        if($order) array_push($generated_orders,  $order);
                    }
                }
            }
        });
        
        return $generated_orders;
    }
    
    public function order($item, $quantity = null)
    {
        $quantity = ($quantity) ? $quantity : $item->storage_amount;
        
        $detail = [
            'customer_id'   => $this->customer->id,
            'employee_id'   => $this->id,
            'order_date'    => Carbon::now(),
            'product_id'    => $item->product_id,
            'product_variant_id'=> $item->product_variant_id,
            'quantity'      => $quantity,
            'approved' => 1,
            'processed_at' => Carbon::now(),
            'status' => 'Pending',
            'user_id' => $this->getCurrentUserId(),
            'is_inventory_order' => 1,
            'is_pooled' => $item->is_pooled,
            'chip_based' => $item->chip_based
        ];
        
        return $this->generateOrder($detail, $item->variant_options, 'TAKE');
    }
    
    public function generateInventoryRequest($action)
    {
        $transaction_id = DB::table('inventory_requests')->insertGetId([
            'request_type' => 'ORDER',
            'created_at' => $this->created_at,
            'updated_at' => $order->updated_at,
            'order_id' => $inventory_order_subscription->id,
            'status' => $status,
            'action' => ($to_storage->owner_type == 'InternalStorage') ? 'ADD' : 'TAKE',
        ]);
    }
    
    public function updateItemSize($variant_id, $old_size, $option_value_id)
    {
        DB::beginTransaction();
        
        $recall_item = $this->getInventoryItems($variant_id, $old_size);
        $this->recallInventoryItems($recall_item);
        
        $item = $recall_item->first();
        $detail = [
                'customer_id'   => $this->customer->id,
                'employee_id'   => $this->id,
                'order_date'    => Carbon::now(),
                'product_id'    => $item->product_id,
                'product_variant_id'=> $item->product_variant_id,
                'quantity'      => $item->storage_amount,
                'approved' => 1,
                'processed_at' => Carbon::now(),
                'status' => 'Pending',
                'user_id' => $this->getCurrentUserId(),
                'is_inventory_order' => 1,
                'is_pooled' => $item->is_pooled,
                'chip_based' => $item->chip_based
            ];
        
        $order = $this->generateOrder($detail, [$option_value_id], 'TAKE');
        
        DB::commit();
        
        return $order;
    }
    
    /**
	 * Update inventory items by generating either take or return orders
	 * @param int           $product_variant_id 
	 * @param string        $size  //XS
	 * @param int           $newQuantity  
	 * @return boolean      
	 */
    public function updateInventoryAmount($product_variant_id, $size, $newQuantity)
    {
        $inventoryItems = $this->getInventoryItems($product_variant_id, $size);
        $item = $inventoryItems->first();
        if (!$item) return false; 
        
        //Determine what type and the quantity of the order to generate 
        $oldQuantity        = (int)$item->storage_amount;
        
        if($newQuantity > $oldQuantity){
            $action         = 'TAKE';
            $amountToOrder  = $newQuantity - $oldQuantity;
        }else{
            $action         = 'ADD';
            $amountToOrder  = $oldQuantity - $newQuantity;
        }
        
        if($amountToOrder > 0){
            switch($action){
                case 'TAKE':
                    return $this->order($item, $amountToOrder);
                break;
                case 'ADD':
                    return $this->recallInventoryItems($inventoryItems, $amountToOrder);
                break;
            }
        }

        return true;
    }

    public function addInventoryItem($product_variant_id, $newQuantity, $attribute_id=null, $size=null) 
    {   
        $variant = ProductVariant::find($product_variant_id);
        if (!$variant) return false;
        $subscription = $this->customer->subscriptions()->select(DB::raw('id, take_from_subscription_id,IF(take_from_subscription_id IS NOT NULL and take_from_subscription_id > 0, 1, 0) as is_pooled, chip_based'))
                        ->where('product_variant_id', $variant->id)
                        ->first(); 
        
        if (!$subscription) return false;

        $attribute = null; 
        if ($attribute_id) {
            $attribute = $variant->productAttributes()->where('id', $attribute_id)->first();
        }

        $detail = [
            'customer_id'               => $this->customer->id,
            'employee_id'               => $this->id,
            'order_date'                => Carbon::now(),
            'product_id'                => $variant->product_id,
            'product_variant_id'        => $variant->id,
            'quantity'                  => $newQuantity,
            'approved'                  => 1,
            'processed_at'              => Carbon::now(),
            'status'                    => 'Pending',
            'user_id'                   => $this->getCurrentUserId(),
            'is_inventory_order'        => 1,
            'is_pooled'                 => $subscription->is_pooled,
            'subscription_id'           => $subscription->id,
            'chip_based'                => $subscription->chip_based
        ];
        
        $variant_option = null; 
        if ($attribute) {
            $variant_option = VariantOption::firstOrCreate([
                      'product_option_id' => $attribute->product_option_id,
                      'product_option_value_id' => $attribute->product_option_value_id,
                  ]);
        }
        $variant_options = ($variant_option) ? [$variant_option->id] : [];  
        $order = $this->generateOrder($detail, $variant_options, 'TAKE');
        
        return $order;
    }
    
    /**
	 * Regulate inventory
	 * @param  array    $request
        [
            'action' => 'REGULATE_UP/REGULATE_DOWN'
            'quantity' => 1,
            'product_id' =>12,
            'product_variant_id' =>127,
            'storage_id' => 1,      //Defaults to "Nytlager" internal storage 
            'building_id' => 2,     //Defaults to taastrup 
            'pov_id' => 611         //product_option_value_id
            'user_id' => 1,         //Current user
        ]     
	 * @return null
	 */
    public static function regulate($request)
    {
        $from_storage = Storage::firstOrCreate([
            'owner_id' => 5,
            'owner_type' => 'InternalStorage',
        ])->id;

        if($request['storage_id']){
            $to_storage = $request['storage_id'];
        }else{
            $to_storage = Storage::firstOrCreate([
                'owner_id' => 1,
                'owner_type' => 'InternalStorage',
            ])->id;
        }
        
        // DB::beginTransaction();     
        $transaction = InventoryTransaction::create([
            'product_id' =>$request['product_id'],
            'product_variant_id' =>$request['product_variant_id'],
            'inventory_request_id' =>null,
            'requested_quantity' =>null,
            'from_storage_id' =>$from_storage,
            'to_storage_id' =>$to_storage, 
            'status' => 'Completed',
            'completed_at' => Carbon::now(),
        ]);
        
        //attach variant options if there is any 
        if($request['pov_id']){
            $product_option_value = ProductOptionValue::find($request['pov_id']);

            if($product_option_value){
                $variantOption = VariantOption::firstOrCreate([
                    'product_option_id'         => $product_option_value->product_option_id,
                    'product_option_value_id'   => $product_option_value->id,
                ]);

                $variantOptionTransaction = VariantOptionTransaction::create([
                    'optionable_id'             => $transaction->id,
                    'optionable_type'           => 'inventory_transaction',
                    'variant_options_id'        => $variantOption->id
                ]);
            }
        }

        //Add location
        $building_id = (isset($request['building_id']) && $request['building_id']) ? $request['building_id'] : 2; 
        $transaction->productLocations()->create([
            'inventory_transaction_id'  => $transaction->id,
            'building_id'               => $building_id,
            'quantity'                  => $request['quantity']
        ]);

        //Add log
        $transaction->transaction_logs()->create([
            'user_id'                   => $request['user_id'],
            'action'                    => $request['action'],
            'ip'                        => (isset($_SERVER['REMOTE_ADDR'])) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1',
            'processed_quantity'        => $request['quantity'],
        ]);
        
        // DB::commit();
    }
}