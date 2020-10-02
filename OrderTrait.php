<?php 

namespace Avask\Traits;

use Avask\Models\Chips\BannedChip;
use Avask\Models\Orders\Order;
use Avask\Models\Orders\OrderTransaction;
use Avask\Models\Orders\OrderedTransaction;
use Avask\Models\Products\ProductOptionValue;
use Avask\Models\Utilities\VariantOptionTransaction;
use Avask\Models\Inventory\InventoryOrderAdjustment;
use Avask\Models\Products\ProductAttribute;
use Avask\Models\Subscriptions\Subscription;
use Avask\Models\Chips\ChipRegistration;
use DB;
use Event;
use Auth;
use Avask\Events\Orders\InventoryTransactionAdded;


trait OrderTrait
{
    
    public function process($quantity, $storage_id = null, $location = null, $size_id = null, $using_different_size = false)
    {
        if($storage_id == null){
            $storage_id  = $this->getStorageID();
        }
        
        if($location == null){
            $location   = ($this->customer->building_id) ? $this->customer->building_id : 2;
        }
        
        //Removed condition as it will always be approved now
        // if($this->approved()){
            $transaction =  $this->inventoryRequest->addTransaction($quantity, $storage_id, $location, $size_id);
        // }
        
        if($transaction){
            $transaction_log = $transaction->transaction_logs()->latest()->first();
            $option_value = ProductAttribute::find($size_id);
            if($using_different_size) {

                InventoryOrderAdjustment::firstOrCreate(['order_id' => $this->id, 'inventory_transaction_id' => $transaction->id, 'inventory_transaction_log_id' => $transaction_log->id, 'option_value_id' => $option_value->getOptionValueId()]);
            }
            if($this->amountUnprocessed() <= 0){
                $this->fill(['status' => 'Completed'])->save();
            }
            
            Event::fire(new InventoryTransactionAdded($transaction));
            
            $this->syncStatus();
            
            return true;
        }
        
        return false;
    }
    
    public function getStorageID()
    {
        $storage = null;
        
        if($this->subscription->takeFromSubscription){
            $storage = $this->subscription->takeFromSubscription->customer->storage;
            
        }else{
            if($this->employee_id)
                $storage = $this->customerEmployee->storage;
            else
                $storage = $this->customer->storage;
        }
        
        return ($storage) ? $storage->id : null;
    }
    
    public function hasInventoryTransaction()
    {
        if($this->inventoryRequest->inventoryTransactions()->count() > 0) {
            return true;
        }
        return false; 
    }
    
    public function approved()
    {
        if ($this->approved == 1) {
            return true;
        }
        return false;
    }
    
    
    public function processOrder($action)
    {
        if($this->approved == 1){
            return $this->inventoryRequest()->create([
                'request_type' => 'ORDER',
                'status' => 'Pending',
                'action' => $action,
            ]);
        }
    }
    
    public function copyOrder()
    {
        $order = $this->replicate();
        $order->save();
        
        $variant_options = $this->variantOptionTransaction;
        
        if(count($variant_options) > 0){
            $order->attachVariantOption($variant_options);
        }
        
        return $order;
    }
    
    public function action()
    {
        return $this->inventoryRequest->action;
    }
    
    public function isCompleted()
    {
        if($this->amountUnprocessed() == 0){
            return true;
        }
        return false;
    }
    
    public function processing()
    {
        if(!$this->isCompleted() && $this->amountProcessed() > 0){
            return true;
        }
        return false;
    }
    
    public function cancel()
    {
        $this->update(['status' => 'Cancelled']);
        $this->inventoryRequest()->update(['status' => 'Cancelled']);
    }
    
    public function amountProcessed()
    {
        $date = (func_num_args()) ? func_get_arg(0) : null;

        $transactions = $this->inventoryRequest->transaction_logs();
        
        if($date){
            $transactions = $transactions->whereDate('inventory_transaction_logs.created_at', '<=', $date.' 23:59:00')->whereNull('inventory_transaction_logs.deleted_at');
        }

        $amount = $transactions->sum('processed_quantity');
        if($amount) return $amount;
        
        return 0;
    }
    
    public function amountUnprocessed()
    {
        return $this->quantity - $this->amountProcessed();
    }
    
    public function hasVariant()
    {
        if($this->productVariant) {
            return true;
        }
        return false;
    }
    
    public function getVariant()
    {
        return $this->productVariant;
    }
    
    public function itemOrdered()
    {
        if($this->hasVariant()){
            return $this->productVariant;
        }else{
            return $this->product;
        }
    }
    
    public function syncStatus()
    {
        $this->inventoryRequest->fill(['status' => $this->status])->save();
        $this->inventoryRequest->inventoryTransactions()->first()->fill(['status' => $this->status])->save();
    }
    
    
    /**
     * Creates the orders with inventory transaction.
     *
     * @param string $type          ADD or TAKE
     * @param array  $detail Order details.
     *
     * @return object
     */
    public function generateOrder($detail, $variant_option_ids, $action)
    {
        $order = Order::create($detail);
        $order->attachVariantOptionsGivenIDs($variant_option_ids);
        $order->processOrder($action);
        return $order;
    }
    
    public function attachVariantOptionsGivenIDs($variant_option_ids)
    {
        foreach($variant_option_ids as $id)
        {
            if ($id) {
                $this->variantOptionTransaction()->create([
                    'variant_options_id' => $id
                ]);
            }
        }
    }
    
    public function forStorageID()
    {
        if($this->customerEmployee){
            if(!$this->customerEmployee->storage)
                $this->customerEmployee()->createStorage();
            return $this->customerEmployee->storage->id;
        }else{
            if (!$this->customer->storage) {
                $storage = $this->customer->createStorage(); 
                return $storage->id; 
            }

            return $this->customer->storage->id;
        }
    }

    public function isProductInSubscription()
    {
        return DB::table('subscriptions')->where('product_id',$this->product_id)
                        ->where('product_variant_id',$this->product_variant_id)
                        ->where('customer_id',$this->customer_id)
                        ->first();
    }
    
    public function checkIfOrderExists($variant_id, $employee_id, $variant_option_ids)
    {
        return Order::forInventory()
                        ->select('orders.*', 'pv.name as product_variant', 'pv.product_nr', 'c.dist_name', 'vot.id as vot_id', 'ov.value as size', 'e.name as employee_name', 'e.no as employee_no', 's_from.id as from', 's_to.id as to', 'ir.status as status')
                        ->leftJoin('inventory_requests as ir', 'ir.order_id', '=', 'orders.id') 
                        ->leftJoin('inventory_transactions as it', 'it.inventory_request_id', '=', 'ir.id')
                        ->leftJoin('storages as s_from', 's_from.id', '=', 'it.from_storage_id')
                        ->leftJoin('storages as s_to', 's_to.id', '=', 'it.to_storage_id')
                        ->join('customers as c', 'c.id', '=', 'orders.customer_id')
                        ->leftJoin('customer_employees as e', 'e.id', '=', 'orders.employee_id')
                        ->join('products as p', 'p.id', '=', 'orders.product_id')
                        ->join('product_variants as pv', 'pv.id', '=', 'orders.product_variant_id')
                        ->leftJoin('variant_options_transactions as vot', function($query)
                        {
                            $query->on('vot.optionable_id', '=', 'orders.id')->where('vot.optionable_type', '=', 'inventory_order_transaction');
                        })
                        ->leftJoin('variant_options as vo', 'vo.id', '=', 'vot.variant_options_id')
                        ->join('product_option_values as pov', 'pov.id', '=', 'vo.product_option_value_id')
                        ->join('option_values as ov', 'ov.id', '=', 'pov.option_value_id')
                        ->where('pv.id', $variant_id)
                        ->where('e.id', $employee_id)
                        ->whereIn('vo.id', $variant_option_ids)
                        ->where('ir.action', 'ADD')
                        ->where('ir.status', 'Pending')
                        ->orderBy('orders.id')
                        ->groupBy('orders.id')
                        ->first();
    }
    
    public function recallOrderCount($variant_id, $employee_id, $variant_option_ids)
    {
        $query = Order::forInventory()
                        ->select('orders.*')
                        ->leftJoin('inventory_requests as ir', 'ir.order_id', '=', 'orders.id') 
                        ->where('orders.product_variant_id', $variant_id)
                        ->where('ir.action', 'ADD');
                        
        if($employee_id){
            $query->where('orders.employee_id', $employee_id);
        }
        
        //Check if inventory item have size
        if(array_filter($variant_option_ids)){
            $query->leftJoin('variant_options_transactions as vot', function($query){
                        $query->on('vot.optionable_id', '=', 'orders.id')->where('vot.optionable_type', '=', 'inventory_order_transaction');
                    })
                    ->leftJoin('variant_options as vo', 'vo.id', '=', 'vot.variant_options_id');
            $query->whereIn('vot.variant_options_id', $variant_option_ids);
        }
        
        return $query->get();
    }
    
    public function changeSize($new_size_id)
    {
        // if($this->action() == 'TAKE'){
            $product_option_value = ProductOptionValue::find($new_size_id);
            $variant_option = $product_option_value->addVariantOption();

            $transaction = VariantOptionTransaction::where('optionable_id', $this->id)
                            ->where('optionable_type', 'inventory_order_transaction')
                            ->first();
            if ($transaction) {
                $transaction->update(['variant_options_id'=> $variant_option->id]);
            }else {
                $transaction =  $this->variantOptionTransaction()->create([
                    'optionable_id' => $this->id,
                    'optionable_type' => 'inventory_order_transaction',
                    'variant_options_id' => $variant_option->id,
                ]);
            }
             
            return $transaction;

        // }else{
            // return 'Change size only applies to orders to the customers/employees.';
        // }
    }
    
    public function changeQuantity($quantity)
    {
        if($quantity){
            $this->fill(['quantity' => $quantity])->save();

        }
    }
    
    public function deleteAssociatedBannedChips()
    {
        return $this->bannedChips()->delete();
    }
    
    /**
     * Links orders and chips
     *
     * @param  Array  $chipNumbers
     * @return \Illuminate\Http\Response
     */
    public function linkChipRegistration($chip_registration_id)
    {
        $data = [
            'order_id' => $this->id,
            'chip_registration_id' => $chip_registration_id,
        ];
        
        return $this->chipLinks()->updateOrCreate($data, $data);
    }

    public function deleteChipLinkRegistrations() {        
        foreach ($this->chipLinks as $link) {
            //check if the chip registration has been packed or sorted
            $chip = $link->chipRegistration;
            if ($chip) {
                $last_transaction_type = $chip->getLastChipTransactionType();
                if ($last_transaction_type != 'Registration') {
                    return false;
                }else {
                    $chip->delete();
                    $link->delete();
                }
            }            
        }
        return true;
    }
    
    public function processOrders($orders)
    {
        $processOrderCount = 0;
        if($orders->count() > 0){
            
            foreach($orders as $order){
                
                if($order && $order->allowAutomaticProcessing()){
                
                    $amountUnprocessed = $order->amountUnprocessed();
                    if($amountUnprocessed > 0){
                        $storage = $order->subscription->takeFromSubscription->customer->storage;          
                        $building = ($order->customer->building_id) ? $order->customer->building_id : 2;            
                        $order->process($amountUnprocessed, $storage->id, $building);
                        $processOrderCount++;
                    }
                }
            }
        }
        
        return $processOrderCount;
    }
    
    /*Check if order is allowed to be processed automatically*/
    public function allowAutomaticProcessing()
    {
        //allow all non-chip based and pulje transactions
        if (!$this->chip_based && $this->is_pooled) {
            return true; 
        }

        //Do not allow for return order
        if($this->action() == 'ADD'){
            return false;
        }
        
        //Check if there is any amount to process
        if($this->amountUnprocessed() <= 0){
            return false;
        }

        //check if there are still available products in the pool
        if ($this->is_pooled && !$this->isAvailableInPool()) {
            return false; 
        }
        
        //Check employee order and pooled
        $employee = $this->customerEmployee;
        
        if($this->is_pooled && $employee && $employee->readyForProcessing()){
            return true;
        }
        //auto approve customer order from pooled (by mikkel 29/08/2017)
        else if($this->is_pooled && !$employee) return true;

        return false;
    }

    public function getOldNewLagerAmount($variant_options_id = null, $product_nr = null, $size = null, $building_id = null)
    {
        $count = DB::table('products as p')
            ->select(DB::raw("
                pv.id, pv.name, 
                pv.product_id, 
                pv.product_nr, 
                ov.value AS size, 
                pov.id AS pov_id, 
                po.id AS po_id,
                inventory.product_option_id AS product_option_id,
                inventory.product_option_value_id AS product_option_value_id,
                ov.sort_order AS sort_order, 
                ov.id AS option_value_id, 
                inventory.building_id, 
                inventory.location,
                IF(inventory.new != 0, inventory.new ,0) as new,
                IF(inventory.used != 0, inventory.used, 0) as used
            "))
            ->join('product_variants as pv', 'pv.product_id', '=', 'p.id')
            ->join('product_options as po', 'po.product_id', '=', 'pv.product_id')
            ->join('product_attributes as pa', function($query)
            {
                $query->on('pa.product_variant_id', '=', 'pv.id')->on('pa.product_option_id', '=', 'po.id');
            })
            ->join('product_option_values as pov', 'pov.id', '=', 'pa.product_option_value_id')
            ->join('option_values as ov', 'ov.id', '=', 'pov.option_value_id')
            ->join('options as o', 'o.id', '=', 'po.option_id')
            ->leftJoin(DB::raw("(
                SELECT 
                    it.product_variant_id,
                    pl.building_id,
                    buildings.name AS location, 
                    vo.product_option_id, 
                    vo.product_option_value_id, 
                    SUM(IF(`from_storage_id` IN (31) OR `to_storage_id` IN (31), IF(itl.action = 'REVOKE' OR itl.action = 'REGULATE_UP' OR itl.action = 'NEW STOCK', processed_quantity, -1 * processed_quantity), 0)) AS new,
                    SUM(IF(`from_storage_id` IN (32) OR `to_storage_id` IN (32), IF(itl.action = 'REVOKE' OR itl.action = 'REGULATE_UP' OR itl.action = 'NEW STOCK', processed_quantity, -1 * processed_quantity), 0)) AS used
                FROM 
                    `inventory_transactions` as it
                LEFT JOIN 
                    `inventory_transaction_logs` AS `itl` ON `it`.`id` = itl.inventory_transaction_id
                LEFT JOIN 
                    `product_locations` AS `pl` ON `pl`.`inventory_transaction_id` = `it`.`id`
                LEFT JOIN 
                    `buildings` ON `pl`.`building_id` = `buildings`.`id`
                LEFT JOIN 
                    `variant_options_transactions` AS `vot` ON `vot`.`optionable_id` = `it`.`id` AND `vot`.`optionable_type` = 'inventory_transaction'
                LEFT JOIN 
                    `variant_options` AS `vo` ON `vo`.`id` = `vot`.`variant_options_id`
                where pl.id is not null  
                GROUP BY  
                    it.product_variant_id, 
                    `vo`.`product_option_value_id`, 
                    `building_id`
                ) as inventory")
                
                , function($query) {
                    $query->on('inventory.product_variant_id','=','pv.id');
                    $query->on('inventory.product_option_id','=','po.id');
                    $query->on('inventory.product_option_value_id','=','pov.id');
                   
                })
            ->whereRaw('po.is_used_for_variation = 0')
            ->where('pv.product_nr', $product_nr)
            ->whereIn('ov.value', $size)
            ->where('inventory.building_id', $building_id)
            ->groupBy('pv.id', 'ov.id','building_id')
            ->get();
                // dd($count);
            return $count;
    }

    public function isAvailableInPool() 
    {         
        $pulje_subscription = Subscription::where('is_pooled',1)->where('product_id',$this->product_id)->whereNull('termination_date')->where(function($query){
            $query->whereNull('end_date')->orWhere('end_date','<','now()');
        }); 
        if ($this->product_variant_id) $pulje_subscription->where('product_variant_id',$this->product_variant_id);
        $pulje_subscription = $pulje_subscription->first();
        if (!$pulje_subscription) return false; 

        if (!$pulje_subscription->chip_based) return true;  //all pulje non-chip based is auto approve

        $subscribers_customer_ids = ($pulje_subscription) ? $pulje_subscription->subscribers->pluck('customer_id')->toArray() : [];

        $pulje_subscribers_orders = Order::select(DB::raw("orders.id,orders.customer_id,concat(c.id ,' - ',c.dist_name) as customer_name, orders.product_id, orders.product_variant_id,
                    IF(orders.product_variant_id,pv.name,p.name) as product_name, IF(orders.product_variant_id,pv.product_nr,p.product_nr) as product_nr,vot.size,vot.sort_order,
                    (SUM(IF(itl.action != 'REVOKE' AND itl.action != 'DISCARDED', processed_quantity, 0)) - SUM(IF(itl.action = 'REVOKE' OR itl.action = 'DISCARDED', processed_quantity, 0))) AS quantity"))
                ->join('customers as c', 'c.id', '=', 'orders.customer_id')
                ->join('products as p', 'orders.product_id', '=', 'p.id')
                ->leftJoin('product_variants as pv', 'orders.product_variant_id', '=', 'pv.id')
                ->join('inventory_requests as ir', 'ir.order_id', '=', 'orders.id')
                ->leftJoin(DB::raw("(SELECT optionable_id, optionable_type, variant_options_id, ov.value as size, vo.product_option_id, vot.id, ov.value, vo.product_option_value_id,ov.sort_order
                                 FROM variant_options_transactions vot
                                 JOIN variant_options AS vo ON vo.id = vot.variant_options_id
                                   JOIN product_option_values AS pov ON pov.id = vo.product_option_value_id
                                   JOIN option_values AS ov ON ov.id = pov.option_value_id WHERE optionable_type = 'inventory_order_transaction'
                               )  AS vot"), 'vot.optionable_id','=','orders.id')
                ->join('inventory_transactions as it', 'it.inventory_request_id','=','ir.id')
                ->join('inventory_transaction_logs as itl', 'itl.inventory_transaction_id','=','it.id')
                ->whereNull('it.deleted_at')->whereNull('itl.deleted_at')        
                ->where("orders.is_pooled",'=',1)        
                ->whereIn('orders.customer_id',$subscribers_customer_ids)->where('orders.product_id',$this->product_id)->groupBy('pv.id','vot.size')
                ->havingRaw('quantity != 0');

        if ($this->product_variant_id) $pulje_subscribers_orders->where('orders.product_variant_id',$this->product_variant_id);

        $get_size = $this->getOrderSize();         
        if ($get_size) $pulje_subscribers_orders->where('vot.size',$get_size->size);
        $pulje_subscribers_orders = $pulje_subscribers_orders->first();
       
        $total_used = ($pulje_subscribers_orders) ? $pulje_subscribers_orders->quantity : 0;

        $pooled_products = ChipRegistration::select(DB::raw("chip_registrations.*, count(chip_registrations.id) as total_qty, pv.product_nr, vot.variant_options_id, vot.size, pv.name as product_name"))
                        ->leftJoin(DB::raw("(SELECT optionable_id, optionable_type, variant_options_id, ov.value as size, vo.product_option_id, vot.id, ov.value, vo.product_option_value_id
                                     FROM variant_options_transactions vot
                                     JOIN variant_options AS vo ON vo.id = vot.variant_options_id
                                       JOIN product_option_values AS pov ON pov.id = vo.product_option_value_id
                                       JOIN option_values AS ov ON ov.id = pov.option_value_id WHERE  optionable_type = 'chip_registration'
                                   )  AS vot"),'vot.optionable_id','=','chip_registrations.id')     
                        ->join('product_variants as pv','pv.id','=','chip_registrations.product_variation_id')   
                        ->where('chip_registrations.customer_id', $pulje_subscription->customer_id)     
                        ->where('chip_registrations.product_id',$this->product_id)->where('chip_registrations.product_variation_id',$this->product_variant_id)                
                        ->groupBy('product_variation_id','vot.size'); 
      
        if ($get_size) $pooled_products->where('vot.size',$get_size->size);
        $pooled_products = $pooled_products->first(); 
        
        $pool_amount = ($pooled_products) ? $pooled_products->total_qty : 0;
        $buffer = $pool_amount - $total_used; 

        if ($buffer > 0 && $buffer >= $this->quantity) return true; 
        else return false; 
    }
    public function addOrderTransaction($order_id, $user_id, $quantity) 
    {
        $amount_processed = $this->amountProcessed();
        $old_amount = $this->quantity;
        $new_amount = 0;
        if($quantity > $old_amount) {
            $quantity = $quantity - $old_amount;
            $new_amount = $old_amount + $quantity;
        } 
        else if($quantity < $old_amount) {
            $quantity = $quantity - $old_amount;
            $new_amount = $old_amount + $quantity;
        }
        $order_transaction = OrderTransaction::create(
            ['order_id' => $order_id,
             'amount' => $quantity,
             'old_amount' => $old_amount,
             'new_amount' => $new_amount,
             'user_id' => $user_id
        ]);

        return $order_transaction;
    }
    public function addOrderedTransaction($order_id, $user_id, $quantity)
    {
        $amount_processed = $this->amountProcessed();
        $old_amount = $this->ordered_amount;
        $new_amount = 0;
        if($quantity > $old_amount) {
            $quantity = $quantity - $old_amount;
            $new_amount = $old_amount + $quantity;
        } 
        else if($quantity < $old_amount) {
            $quantity = $quantity - $old_amount;
            $new_amount = $old_amount + $quantity;
        }
        else if($new_amount == 0) {
            $old_amount = 0;
            $new_amount = $quantity;
        }
        $order_transaction = OrderedTransaction::create(
            ['order_id' => $order_id,
             'ordered_amount' => $quantity,
             'old_amount' => $old_amount,
             'new_amount' => $new_amount,
             'user_id' => $user_id
        ]);

        return $order_transaction;
    }
}