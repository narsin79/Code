<?php

namespace Avask\Http\Controllers\Chips;

use Illuminate\Http\Request;

//chips
use Avask\Models\Chips\Chip;
use Avask\Models\Chips\BannedChip;
use Avask\Models\Chips\ChipScanner;
use Avask\Models\Chips\ChipTransaction;
use Avask\Models\Chips\ChipRegistration;
use Avask\Models\Chips\ChipTransactionLog;
use Avask\Models\Chips\ChipDeliveryTransaction;
use Avask\Models\Chips\TemporaryEmployeeTransaction;
use Avask\Models\Chips\ChipScannerType;
use Avask\Models\Chips\RegulateChip;
use Avask\Models\Chips\ChipMissing;
//deliveries
use Avask\Models\Deliveries\Delivery;
// customers
use Avask\Models\Customers\Customer;
use Avask\Models\Customers\CustomerEmployee;
// products
use Avask\Models\Products\Product;
use Avask\Models\Products\ProductVariant;
use Avask\Models\Products\ProductAttribute;
use Avask\Models\Products\ProductOptionValue;
use Avask\Models\Products\OptionValue;
use Avask\Models\Utilities\VariantOption;
use Avask\Models\Utilities\VariantOptionTransaction;
//Inventory
use Avask\Models\Inventory\InternalStorage;
use Avask\Models\Inventory\InventoryTransaction;
use Avask\Models\Inventory\Storage;
use Avask\Models\Orders\Order;

use Avask\Traits\ChipTrait; 

use Avask\Http\Requests;
use Avask\Http\Controllers\Controller;
use Avask\ChipInventory;
use Avask\Repositories\Product\ProductRepository;
use Avask\Repositories\Customer\CustomerImportRepository;
use Avask\Repositories\Deliveries\DeliveryRepository;
use Avask\Models\Chips\ChipRegistrationTransaction;
use Avask\Models\Chips\BannedReason;
use Avask\Models\Products\SeamSizeGroup;
use Input;
use Response;
use Auth;
use Validator;
use DB;
use Excel;
use \Carbon\Carbon;
use Session;
use Redirect;
use Datatables;
use Search;
use File; 
use AppStorage;

class ChipController extends Controller 
{
    use ChipTrait;

    public function index() { }

    public function showRegistration($chip_scanner_id=null) //taastrup
    {
        $scanners = ChipScanner::all();
        $chip_scanner = false;
        if ($chip_scanner_id) {
        //     $chip_scanner_id = 5;//default scanner
            $chip_scanner = ChipScanner::where('api_id',$chip_scanner_id)->first();
        }
        else if(Auth::user()->chipScanner){
            //user default scanner
            $chip_scanner = Auth::user()->chipScanner;
            $chip_scanner_id = $chip_scanner->api_id;
        }
        

        $since = DB::selectOne("SELECT NOW() AS now")->now;
        return view('chips.registration.index',compact('since','chip_scanner_id', 'chip_scanner', 'scanners'));
    }

    public function showBannedReason($chip_scanner_id=null)
    {
        $scanners = ChipScanner::all();
        $banned_reason = BannedReason::all();
        $chip_scanner = false;
        if ($chip_scanner_id) {
        //     $chip_scanner_id = 5;//default scanner
            $chip_scanner = ChipScanner::where('api_id',$chip_scanner_id)->first();
        }
        else if(Auth::user()->chipScanner){
            //user default scanner
            $chip_scanner = Auth::user()->chipScanner;
            $chip_scanner_id = $chip_scanner->api_id;
        }


        
        $since = DB::selectOne("SELECT NOW() AS now")->now;
        return view('chips.registration.banned_reason',compact('since','chip_scanner_id', 'chip_scanner', 'scanners','banned_reason'));
    }

    public function register(Request $request)
    {
        DB::beginTransaction();
        // dd($request->all());
        /**
        *
        * NOTE: size_id from post is equals to the product_attribute_id
        */
        $since = DB::selectOne("SELECT NOW() AS now")->now;
        $scannerId = Input::get('chip_scanner');
        $is_filtered = (bool) Input::get('is_filtered', 0);
        $missing_banned = [];
        // dd($is_filtered); //removed since all scanners can be used for packing, sorting, registration
        /*if (!$this->confirmScannerId($scannerId, ChipScanner::TXT_REGISTRATION)) {
            return Response::json(['success'=>false,'message'=>'Incorrect scanner. Please use the scanner for registration.']);
        }*/
        
        $order = Order::find($request->order_id);
        $orderProcessAmount = 0;

        $data = $this->callChipAPI($scannerId);

        $jsonChips = json_decode($data['content'], false);
        $jsonChipsKeys = array_keys((array)$jsonChips);

        $chip_count = count($jsonChipsKeys);
        // dd($chip_count);
        
        $chip_numbers = $this->removeIgnoredChips($jsonChipsKeys,$request->ignoredChips);
        $chip_numbers = $this->getArrayChips($chip_numbers, ChipScannerType::TXT_REGISTRATION);
        // dd($chip_numbers);
        $registration_columns = Input::only('product_id','customer_id','customer_employee_id','product_variation_id','size_id');
        $checkboxes = Input::only('update_product_id','update_product_variation_id','update_customer_id','update_customer_employee_id','add_to_queue');

        foreach ($checkboxes as $name => $value) {
            $field_name = substr($name, 7);
            if ($value != 1 && in_array($field_name, array_keys($registration_columns))) {
                unset($registration_columns[$field_name]);
            }
        }
        // dd($registration_columns);
        //$registration_columns = array_filter($registration_columns);
        
      // If request has pooled enable 
        if(!$request->order_id && isset($request->is_pooled) && count($registration_columns) > 0){
         
            $chips_to_banned = ChipRegistration::join('chip_registration_transactions as crt', 'crt.id', '=', 'chip_registrations.latest_cr_transaction_id')
                ->join('product_variants as pv', 'pv.id', '=', 'chip_registrations.product_variation_id')
                ->where('crt.customer_id', $registration_columns['customer_id'])
                ->where('crt.employee_id', $registration_columns['customer_employee_id'])
                ->where('chip_registrations.product_variation_id', $registration_columns['product_variation_id'])
                ->select('chip_registrations.*')
                ->get();
            $banned_reason = DB::table('banned_reasons')->where('name', 'REGULATE')->pluck('id');

            foreach ($chips_to_banned as $banned) {
                // dd($banned);
                BannedChip::firstOrCreate(['chip_registration_id'=>$banned->id, 'banned_reason_id' => $banned_reason]);
            }

            foreach ($chip_numbers as $chip_no=>$data) {
                if ((isset($data['is_missing']) && $data['is_missing']) || (isset($data['is_banned']) && $data['is_banned'])) {
                    $missing_banned[$chip_no] = $data; 
                    continue; 
                }
                // dd($chip_no);
               $chip = Chip::where('chip_no', $chip_no)->first();  

                $chip_registration = $chip->registration();

                $chip_registration_transaction = ChipRegistrationTransaction::firstOrCreate(
                            ['chip_registration_id' => $chip_registration->id,
                            'customer_id' => $registration_columns['customer_id'],
                            'employee_id' => $registration_columns['customer_employee_id'],
                            ]);

                $chip_registration->update(['latest_cr_transaction_id' => $chip_registration_transaction->id]);

            }

            $text = (count($missing_banned) > 0) ? '<br>Undtagen nogle manglende / forbudte chips:<br>'.implode('<br>', array_keys($missing_banned)) : ''; 
            // dd($text, $missing_banned);   
            return Response::json(['success'=>true,'message'=>'Chipsene blev Regulated.'. $text]);
          
        }
       // dd($request->all());
        if (count(array_filter($registration_columns)) <= 0) {            
            if (count($chip_numbers) > 0) {
                $p=0; 
                //de program chips
                $registrations = ChipRegistration::select(DB::raw('chip_registrations.*,c.chip_no'))
                        ->join('chips as c','c.id','=','chip_registrations.chip_id')
                        ->whereIn('c.chip_no',array_keys($chip_numbers))
                        ->get();
                if (count($registrations) > 0 && !$is_filtered) {
                    $scanner = ChipScanner::where('api_id',$scannerId)->first();
                    if (!$scanner) return Response::json(['success'=>false,'message'=>'Invalid Scanner.']);
                    $transaction = ChipTransaction::create(['scanner_type'=>ChipScannerType::REGISTRATION, 'scanned_by' => Auth::user()->id, 'chip_scanner_id'=>$scanner->id,'total_amount'=>count($registrations)]);
                    foreach ($registrations as $key => $registration) {                       

                        ChipTransactionLog::create([
                            "chip_transaction_id" => $transaction->id,
                            "chip_registration_id" => $registration->id
                        ]);

                        //remove from Banned chips if present
                        if($order){
                            $order->linkChipRegistration($registration->id);
                            $orderProcessAmount++;
                        }

                        BannedChip::deleteChipByRegID($registration->id);
                        $registration->delete();
                        $this->forgetChip($registration->chip_no, ChipScannerType::TXT_REGISTRATION);
                        $p++;
                    }
                    //Process return orders
                    if($order && $orderProcessAmount > 0){
                        $order->process($orderProcessAmount, $request->storage_id, $request->building_id);
                        if($order->amountUnprocessed() == 0)
                        $order->deleteAssociatedBannedChips();
                    }
                }
                DB::commit();    
                // there is nothing to change           
                if ($p > 0) return Response::json(['success'=>true,'message'=>'Chipsene blev omprogrammeret.','is_filtered'=>$is_filtered]);
                else return Response::json(['success'=>false,'message'=>'Nothing to process.','is_filtered'=>$is_filtered]);
                
            }else{
                return Response::json(['success'=>false,'message'=>'Nothing to process.','is_filtered'=>$is_filtered]);
            }
            
        } //eof count registration columns
       

        $registration_columns['created_by'] = Auth::user()->id;
        
        $count = 0;
        // create a transaction
        $scanned_by = (Auth::user()) ? Auth::user()->id : NULL;
        $scanner = ChipScanner::where('api_id',$scannerId)->first();
        if (!$scanner) return Response::json(['success'=>false,'message'=>'Invalid Scanner.']);

        $transaction = ChipTransaction::create(['scanner_type'=>ChipScannerType::REGISTRATION, 'scanned_by' => $scanned_by, 'chip_scanner_id'=>$scanner->id]);

        foreach ($chip_numbers as $chip_no => $data) {
            $new_registration_columns = $registration_columns; // used to create the new registration
            $new_registration_columns['is_manual'] = Input::get('is_manual', 0);
            
            $chip = Chip::getChipByNo($chip_no);

            if ((isset($data['is_missing']) && $data['is_missing']) || (isset($data['is_banned']) && $data['is_banned'])) {
                $missing_banned[$chip_no] = $data;
                continue; 
            }
            
            if (!$chip) {
                $chip = Chip::create(['created_by'=>Auth::user()->id,'active'=>1,'chip_no'=>$chip_no]);

                //since the chip does not exist yet in the chips table, it is safe to say that it has never been registered
                $new_registration_columns['chip_id'] = $chip->id;
                $new_registration = $this->storeChipRegistration($new_registration_columns);
                if (!$new_registration){
                    return Response::json(['success'=>false,'message'=>'Validation error. Please fill out the required fields.']);
                    DB::rollback();
                }

                // create a new transaction log
                ChipTransactionLog::create([
                    "chip_transaction_id" => $transaction->id,
                    "chip_registration_id" => $new_registration->id
                ]);


            }else {
                if ($is_filtered) continue; //if is_filtered is true, then ignore all the chips that are already in the system (assigned/not assigned)

                $registration = $chip->registration();
                $new_registration_columns['chip_id'] = $chip->id;
                $hasChanges = false; 
                if ($registration){
                    // end all other registration for that chip
                    ChipRegistration::where('chip_id',$chip->id)->where('id','!=',$registration->id)->delete();

                    $registration->size_id = null;
                    if ($registration->variant_option_transaction && $registration->product_variation_id){
                        $variant_option = $registration->variant_option_transaction->variantOption;
                        $size = ProductAttribute::where('product_variant_id',$registration->product_variation_id)
                                ->where('product_option_id',$variant_option->product_option_id)
                                ->where('product_option_value_id',$variant_option->product_option_value_id)->first();
                        if ($size) $registration->size_id = $size->id;
                    }
                    
                    if(count($registration_columns) > 0 &&  
                       ((isset($registration_columns['customer_id']) && $registration_columns['customer_id'] != $registration->customer_id) ||
                        (isset($registration_columns['customer_employee_id']) && $registration_columns['customer_employee_id'] != $registration->customer_employee_id) ||
                        (isset($registration_columns['product_id']) && $registration_columns['product_id'] != $registration->product_id) || 
                        (isset($registration_columns['product_variation_id']) && $registration_columns['product_variation_id'] != $registration->product_variation_id) ||
                        (isset($registration_columns['size_id']) && $registration_columns['size_id'] != $registration->size_id)) ) {
                        // check if anything has changed
                        if (!isset($registration_columns['customer_id']) && $registration->customer_id) {
                            // if customer has been udpated, then use that id
                            $new_registration_columns['customer_id'] = $registration->customer_id;
                        }
                        if (!isset($registration_columns['customer_employee_id']) && $registration->customer_employee_id) {
                            // customer employee has been updated then use that id or null
                            if (isset($registration_columns['customer_id']) && $registration_columns['customer_id'] == $registration->customer_id){
                                $new_registration_columns['customer_employee_id'] = $registration->customer_employee_id;
                            }
                            else if (!isset($registration_columns['customer_id']) && $registration->customer_employee_id) {
                                $new_registration_columns['customer_employee_id'] = $registration->customer_employee_id;
                            }
                            else {
                                $new_registration_columns['customer_employee_id'] = null;
                            }                            
                        } 
                        if (!isset($registration_columns['product_id']) && $registration->product_id) { //product_id cant be empty if there is a variation_id and size_id
                            // update product size
                            $new_registration_columns['product_id'] = $registration->product_id;
                            $new_registration_columns['product_variation_id'] = $registration->product_variation_id;
                            $new_registration_columns['size_id'] = $registration->size_id;
                        } 
                        $hasChanges = true;
                        // $new_registration = $this->storeChipRegistration($new_registration_columns);
                        //end the previous registration
                        
                    }
                    else if (count($registration_columns) > 0 && (!isset($registration_columns['customer_employee_id']) && $registration->customer_employee_id)) {
                        $hasChanges = true;
                        $new_registration_columns['product_id'] = $registration->product_id;
                        $new_registration_columns['product_variation_id'] = $registration->product_variation_id;
                        $new_registration_columns['size_id'] = $registration->size_id;
                        // $new_registration = $this->storeChipRegistration($new_registration_columns);     

                    }
                    
                }
                else {
                    //its in the chips table but not yet registered or no active registration
                    $hasChanges = true;
                }
                // dd($hasChanges);
                if ($hasChanges) {
                    $new_registration = $this->storeChipRegistration($new_registration_columns);
                    if (!$new_registration){
                        return Response::json(['success'=>false,'message'=>'Validation error. Please fill out the required fields.']);
                        DB::rollback();
                    }
                    if ($registration) $registration->delete();
                    // create a new transaction log
                    ChipTransactionLog::create([
                        "chip_transaction_id" => $transaction->id,
                        "chip_registration_id" => $new_registration->id
                    ]);
                }                    
            }
            
            if($order && $request->storage_id && isset($new_registration)){
                $order->linkChipRegistration($new_registration->id);
                $orderProcessAmount++;
            }
            
            // remove chip from redis, so that the chip information is updated and the user can see the change
            $this->forgetChip($chip_no, ChipScannerType::TXT_REGISTRATION);

            $count ++;


        } //eof foreach chip numbers


        
        if($order && $request->storage_id && $orderProcessAmount > 0){
            $order->process($orderProcessAmount, $request->storage_id, $request->building_id);
        }
          
        //If request has storage_id then store chip information in regulate_chips table
        if (!$request->order_id && Input::has('storage_id')){

            $current_date = Carbon::now();
            $time = $current_date->toDateTimeString();
            $ip = $_SERVER['REMOTE_ADDR'];
            $userid=Auth::user()->id;

            // dd($new_registration_columns);
            $customer_id = $new_registration_columns['customer_id'];
            $employee_id = $new_registration_columns['customer_employee_id'];
            $product_id = $new_registration_columns['product_id'];
            $product_variant_id = $new_registration_columns['product_variation_id'];

            $product_attr = ProductAttribute::find($new_registration_columns['size_id']);
            $variant_option = VariantOption::firstOrCreate(['product_option_id'=>$product_attr->product_option_id,'product_option_value_id'=>$product_attr->product_option_value_id]);
            
            DB::transaction(function () use ($request, $customer_id, $employee_id, $product_id, $product_variant_id, $product_attr, $variant_option, $time, $ip, $userid, $chip_count)
            {
                $regulate_storage_id = InternalStorage::whereName('Regulate')->first()->storage->id;
                //Creating Regulate Chip entery
                RegulateChip::create([
                'customer_id' => $customer_id,
                'employee_id' => $employee_id,
                'product_id' => $product_id,
                'product_variant_id' => $product_variant_id,
                'variant_option_id' => $variant_option->id
                 ]);

                $transaction_id=InventoryTransaction::create([
                    'product_id' =>$product_id,
                    'product_variant_id' =>$product_variant_id,
                    'inventory_request_id' =>null,
                    'requested_quantity' =>null,
                    'from_storage_id' => $request->storage_id,
                    'to_storage_id' => $regulate_storage_id, 
                    'status' => 'Completed',
                    'completed_at' => Carbon::now(),
                ])->id;

                    

                DB::table('variant_options_transactions')->insert([
                          'optionable_id' => $transaction_id,
                          'optionable_type' => 'inventory_transaction',
                          'variant_options_id' => $variant_option->id
                      ]);
                
                
                $action= "REGULATE_DOWN";
              
               

                DB::table('inventory_transaction_logs')->insert([
                   'inventory_transaction_id' => $transaction_id,
                   'user_id' => $userid,
                   'action' =>$action,
                   'processed_quantity' => $chip_count,
                   'ip' => $ip,
                   'created_at' =>$time
                ]);
                
                
            });
         
        }
        $text = (count($missing_banned) > 0) ? '<br>Undtagen nogle manglende / forbudte chips:<br>'.implode('<br>', array_keys($missing_banned)) : ''; 
        // when are done we tell chip_transactions how many chips have been edited.
        if ($count > 0) {
            $transaction->total_amount = $count;
            $transaction->save();
            DB::commit();
            return Response::json(['success'=>true,'message'=>'Chipsene blev omprogrammeret.'.$text,'is_filtered'=>$is_filtered]);
        } else {
            DB::rollback();
            return Response::json(['success'=>false,'message'=>'Nothing to register.'.$text,'is_filtered'=>$is_filtered]);
        }
    }

    private function storeChipRegistration($inputs)
    {       
        $size_id = false;
        if (array_key_exists('size_id',$inputs)){
            if (isset($inputs['size_id'])) $size_id = $inputs['size_id'];

            unset($inputs['size_id']);
        }
        $validator = Validator::make($inputs, ChipRegistration::$rules);

        if ($validator->passes()) {
            // create a new registration
            $registration = ChipRegistration::create($inputs);

            if ($size_id){
                $product_attr = ProductAttribute::find($size_id);
                //for the size
                //1. add to variant options
                $variant_option = VariantOption::firstOrCreate(['product_option_id'=>$product_attr->product_option_id,'product_option_value_id'=>$product_attr->product_option_value_id]);
                $variant_option_transaction = VariantOptionTransaction::create(['optionable_id'=>$registration->id,'optionable_type'=>'chip_registration','variant_options_id'=>$variant_option->id]);
            }

            return $registration;

        } else return false;
    }

    private function removeIgnoredChips($chips, $ignoredChips)
    {
        if ($ignoredChips) {
            return array_diff($chips, $ignoredChips);
        }
        return $chips;
    }

    public function getReprogramPoll()
    {
        $since = Input::get('since', null);
        $order_id = Input::get('order_id', null);

        $scannerId = Input::get('scannerId');
        $is_filtered = (bool) Input::get('is_filtered', 0);
        
        $response = $this->callChipAPI($scannerId);
        // return Response::json($chips);
        $chips = json_decode($response['content'], true);
       
        $chip_NOs = ($chips) ? array_keys($chips): [];

        // remove ignored keys
        // return Response::json(Input::all());
        $chip_NOs = $this->removeIgnoredChips($chip_NOs,Input::get('ignoredChips'));
        // return Response::json($chip_NOs);
        $scans = [];
        $filtered = []; 
        $banned = [];
        $missing = [];
        $chipsFromRedis = $this->getArrayChips($chip_NOs, ChipScannerType::TXT_REGISTRATION);

        $order = ($order_id) ? Order::find($order_id) : null;
        
        foreach ($chipsFromRedis as $chip_no => $registered) {
            
            if ($registered){
                if ($order && $order->action() == 'TAKE' && ((isset($registered['is_missing']) && $registered['is_missing']) || (isset($registered['is_banned']) && $registered['is_banned']))) {
                    //this is from the order page processing
                    $filtered[] = $chip_no;
                }
                else if (!$order && ((isset($registered['is_missing']) && $registered['is_missing']) || (isset($registered['is_banned']) && $registered['is_banned']))) {
                    //this is from manual chips programming
                    //instead of filtering it autoamatically, show it in the list and with a notification that there are banned or missing chips included
                    if($registered['is_missing']) $missing[] = $chip_no;
                    else if($registered['is_banned']) $banned[] = $chip_no;

                    if (!$is_filtered) $scans[] = $registered;
                    else $filtered[] = $chip_no;
                }
                else if ( $is_filtered ) {
                    $filtered[] = $chip_no;
                }
                else $scans[] = $registered; //original            
            } 
            else {
                
                $arr = [
                    'product_id'=>null, 'product_name'=>null,'product_size'=>null, 'product_size_id'=>null, 'customer_id'=> null,
                    'customer_name'=>null, 'customer_employee_id'=>null, 'employee_no'=>null, 'employee_name'=>null, 
                    'chip_no'=>$chip_no, 'chip_id'=>null
                ];
                
                if ($is_filtered) {
                    $in_aflex = Chip::getChipByNo($chip_no);

                    if ($in_aflex && !$in_aflex->active) $filtered[] = $chip_no;
                    else $scans[] = $arr;
                }
                else {
                    $scans[] = $arr;
                }
                
                // //orignal code below
                // $arr = [
                //     'product_id'=>null, 'product_name'=>null,'product_size'=>null, 'product_size_id'=>null, 'customer_id'=> null,
                //     'customer_name'=>null, 'customer_employee_id'=>null, 'employee_no'=>null, 'employee_name'=>null, 
                //     'chip_no'=>$chip_no, 'chip_id'=>null
                // ];
                // $scans[] = $arr;
                // //orignal code above
            }
        }


        $product_ids = [];
        $customer_ids = [];
        $employee_ids = [];

        $data = ['chips' => [], 'filtered_chips'=>$filtered, 'banned'=>$banned, 'missing'=>$missing];
        // dd($scans);
        foreach ($scans as $scan) {
            if (isset($scan['product_id'])) $product_ids[] = $scan['product_id'];
            if (isset($scan['customer_id'])) $customer_ids[] = $scan['customer_id'];
            if (isset($scan['customer_employee_id'])) $employee_ids[] = $scan['customer_employee_id'];
            
            $data['chips'][$scan['chip_no']] = $scan;
        }

        if (count($data['chips'])) {
            $data['product_ids'] = array_values(array_unique($product_ids));
            $data['customer_ids'] = array_values(array_unique($customer_ids));
            $data['employee_ids'] = array_values(array_unique($employee_ids));
        }

        return Response::json($data)->setCallback(Input::get('callback'));
    }


    public function getProductSizes()
    {
        $variant_id = Input::get('variant_id');
        $sizes = [];

        if ($variant_id){
            $variant = ProductVariant::find($variant_id);
            $variant_attrs = $variant->getVariantAttributes();
            foreach($variant_attrs as $attr) {
                $sizes[$attr->id] = $attr->productOptionValue->optionValue->value;
            }
        }       

        return response()->json(['sizes'=>$sizes]);
    }

    public function getProductSizesWithSeam()
    {
        $variant_id = Input::get('variant_id');
        $size = Input::get('order_size');
        $product_variant = ProductVariant::find($variant_id);
        $product = $product_variant->product()->first();
        // dd($product);
        $sizes = [];
        $number = [];
        $seam_group_id = 0;
        $seam_list = [];
        $next_seam_sizes = [];

        $check_seam_group = SeamSizeGroup::where('product_variant_id', $variant_id)->get();
        // dd($check_seam_group);
        $seam_groups = SeamSizeGroup::whereNull('product_variant_id')->get();

        if(count($check_seam_group) > 0 ) {
            $seam_groups = $check_seam_group;
        } 

        if($seam_groups->count() > 0) {
            foreach ($seam_groups as $group) {
                $number[$group->id] = range($group->seam_from,$group->seam_to);
            }
        }

        $order_size_before_slash = substr($size, 0, strrpos( $size, '/'));
        $order_seam = substr($size, strrpos($size, '/') + 1);
        foreach ($number as $key => $value) {
            if(in_array($order_seam, $value)){
                $seam_group_id  = $key;
                $seam_list  = $value;
            }
        }

        $related_option_values = ($product->variantOption) ? $product->variantOption->getOptionValuesSeam($variant_id, $order_size_before_slash) : [];
                   
        foreach ($related_option_values as $key => $value) {
            // dd($key);
            $related_seam = (int)substr($value, strrpos($value, '/') + 1);
            // dd($related_seam);
            if(in_array($related_seam, $seam_list)){
            
                $next_seam_sizes [$key] = $value;
            }
        }
        // dd($next_seam_sizes);
        if ($variant_id){
            $variant = ProductVariant::find($variant_id);
            $variant_attrs = $variant->getVariantAttributes();
             foreach ($next_seam_sizes as $key_next_seam => $value_next_seam) {
                # code...
                foreach ($variant_attrs as $key_attr => $attr) {
                    $att_size = $attr->productOptionValue->optionValue->value;
                    if($att_size == $value_next_seam) {
                        $sizes[$attr->id] = $attr->productOptionValue->optionValue->value;
                        break; 
                    }
                }
            }
        }       
        return response()->json(['sizes'=>$sizes]);
    }

    public function resetScan()
    {
        $scannerId = Input::get('scannerId');
        $this->clearRedisFolder(ChipScannerType::TXT_REGISTRATION);
        $response = $this->callChipAPI($scannerId, true);
        if ($response['status'] == 500) {
            return response()->json(['success'=>'false','message' => 'There was an issue in resetting the scanner.']);
        }
        return response()->json(['success'=>true]);
    }

    public function showImport(Request $request)
    {    
        $assens = [6944,6943,6942,6941,6940,6939,6938,6937,6936,6935,6934,6933,6932,6931,
                    6930,6929,6928,6927,6926,6925,6924,6923,6922,6921,6911,7027,7029,7028];
        $drs = [6980,6873,6872,6844,6843,6842,6841,6840,6839,6838];
        $assens_drs = array_merge($assens,$drs);

        $customers = Customer::all();   
        foreach ($customers as $key => $cu) {
            if ($cu->subscriptions()->where('chip_based',1)->count() <= 0) {
                $customers->forget($key);
            }
        }

        $imported_customers = [];
        $file_string = "chips_log/imported_customers_do_not_delete_new.txt";

        //create a temp file to record the imported customers
        $imported_customers_file = AppStorage::has($file_string);
        // $imported_customers_file = base_path().'\files\imported_customers_do_not_delete.txt';
        // $imported_customers_file = base_path().'\files\imported_customers_do_not_delete_drs.txt';
        if (!$imported_customers_file){
            AppStorage::put($file_string, '');
        } 

        $file_content = AppStorage::get($file_string);

        if ($request->session()->has('imported_customers')) {
            if (isset($session_imported_customers)){
                $imported_customers = $session_imported_customers;
            }
            else if ($file_content) $imported_customers = explode(',', $file_content);
          
        } 
        else if ($file_content){
            $imported_customers = explode(',', $file_content);
            $request->session()->put('imported_customers', $imported_customers);
        }
        // $request->session()->forget('imported_customers');

        return view('chips.import.index',compact('customers', 'imported_customers'));
    }

    public function doInitialImport(Request $request)
    {        
        // return $this->doInitialSortingImport($request);

        $input = Input::except('_token','customers_table_length');
        $input['check_all'] = isset($input['check_all']) ? true : false;
        $customer_ids = $input['customer_ids'];
        // $customer_id = $input['id'];
        $old_chips = $old_customer_ids = [];
        if (count($customer_ids) <= 0) return; 
        $customers = Customer::whereIn('id',$customer_ids)->get();
        foreach ($customers as $customer) {
            $old_customer = CustomerImportRepository::withNewReference($customer->id);
            if ($old_customer) $old_customer_ids[] = $old_customer->old_customer_id;
        }

        if (count($old_customer_ids) > 0) {
            $old_chip_data =  DB::connection('mysql3')
            ->table('chips as c')->select('cd.id')
            ->join('chip_data as cd','c.chip_data_id','=','cd.id')
            ->where(function ($query) use ($old_customer_ids) {
                $query->whereIn('cd.customer_id', $old_customer_ids)
                      ->orWhereIn('cd.permanent_customer_id', $old_customer_ids);
            })
            // ->groupBy('c.chip_no')
            ->get();
            $temp_arr = [];
            foreach ($old_chip_data as $data) {
                $old_chips[] = $data->id;
            }
            // dd($old_chips);
            $old_chips = array_chunk($old_chips, 20);
        }

        $file_string = "chips_log/imported_customers_do_not_delete_new.txt";
        if (!$request->session()->has('imported_customers')) {
            $request->session()->put('imported_customers', $old_customer_ids);
            AppStorage::put($file_string, implode(',',$old_customer_ids));
            // File::put($imported_customers_file, implode(',',$customer_ids));
        }else {
            $session_customers = $request->session()->pull('imported_customers');
            $session_customers = array_filter(array_merge($session_customers, $old_customer_ids));
            $request->session()->put('imported_customers', $session_customers);
            // File::put($imported_customers_file, implode(',',$session_customers));
            AppStorage::put($file_string, implode(',',$session_customers));
        }
        
        return $old_chips;
    }

    public function import(Request $request)
    {
        // return $this->importSorting($request);
        $input = Input::only('data');
        $old_chip_data_ids = $input['data'];
        if (count($old_chip_data_ids) <= 0) return; 
        
        // $old_chip_data_ids = ["52019"];

        $not_imported_chips = [];
        
        $old_chip_data = DB::connection('mysql3')->table('chip_data as cd')
                        ->select(['cd.*','c.chip_no','c.created_at as chip_created_at','ps.size'])
                        ->join('chips as c','c.chip_data_id','=','cd.id')
                        ->leftJoin('product_sizes as ps','ps.id','=','cd.product_size_id')
                        // ->where('c.chip_no','300ED89F3350006000055312')
                        ->whereIn('cd.id', $old_chip_data_ids)->get();

        $chip_nos = [];
        foreach ($old_chip_data as $old_data) {  
            DB::transaction(function () use($old_data, &$not_imported_chips, &$chip_nos) {
                if ($old_data->customer_id)
                    $new_customer = CustomerImportRepository::withOldReference($old_data->customer_id);
                else 
                    $new_customer = CustomerImportRepository::withOldReference($old_data->permanent_customer_id);

                //find the new product id
                $new_product = ProductRepository::withOldReference($old_data->product_id);

                if ($new_product) {
                    
                    //new chips 
                    $chipExist = Chip::getChipByNo($old_data->chip_no);  
                    $new_chip = ($chipExist) ? $chipExist : Chip::create(['created_by'=>Auth::id(), 'chip_no' => $old_data->chip_no, 'active' => 1, 'created_at'=>$old_data->chip_created_at]); 
                    $chip_nos[] = $new_chip->chip_no; 
                    if ($new_chip) {

                        $employeeExist = false;
                        $emp_id = null;
                        //find employee
                        if ($old_data->employee_id) {
                            $new_employee = CustomerImportRepository::getNewEmployeeWithOldReference($old_data->employee_id); 
                            $employeeExist = ($new_employee) ? CustomerEmployee::find($new_employee->employee_id) : false;
                            $emp_id = ($employeeExist) ? $new_employee->employee_id : null;
                        }                        
                        
                        //then the chip data to chip registrations                        
                        $chip_registration = ChipRegistration::withTrashed()
                                            ->where('chip_id',$new_chip->id)
                                            ->where('customer_id',$new_customer->customer_id)                                            
                                            ->where('product_id',$new_product->id)
                                            ->where('product_variation_id', $new_product->product_variant_id);                                           

                        if ($emp_id) $chip_registration->where('customer_employee_id',$emp_id);
                        $chip_registration = $chip_registration->orderBy('created_at','desc')->first();
                        if(!$chip_registration) {
                            $chip_registration = ChipRegistration::create([
                                                    'chip_id' => $new_chip->id,'customer_id' => $new_customer->customer_id,'customer_employee_id' => $emp_id,
                                                    'product_id' => $new_product->id,'product_variation_id' => $new_product->product_variant_id,
                                                    'created_by' => Auth::id(),'created_at' =>$old_data->created_at
                                                ]); 
                        }
                        //get size
                        if ($new_product->product_variant_id) {
                            $variant = ProductVariant::find($new_product->product_variant_id);                           

                            if (count($variant->getVariantSizes()) > 0) {
                                $sizes = $variant->getVariantSizes()->pluck('size')->toArray();
                                if (in_array(strtolower($old_data->size), array_map('strtolower', $sizes))){
                                    foreach ($variant->getVariantSizes() as $attribute) {
                                        if ($attribute->size == $old_data->size || strtolower($attribute->size) == strtolower($old_data->size)){
                                            $variantOption = VariantOption::firstOrCreate(['product_option_id'=>$attribute->product_option_id, 'product_option_value_id'=>$attribute->product_option_value_id]);
                                            VariantOptionTransaction::firstOrCreate(['optionable_id'=>$chip_registration->id, 'optionable_type'=>'chip_registration', 'variant_options_id'=>$variantOption->id]);
                                            break;
                                        }
                                    }
                                }else{
                                    //run only when product has options for variations
                                    if ($variant->product->variantOption) {
                                        $productOptions = array_map('strtolower', $variant->product->variantOption->productValuesToArray());
                                        // dd($variant->product->variantOption);
                                        //check if the size is in the products options
                                        if (in_array(strtolower($old_data->size), $productOptions)){
                                            //if its in the product add it in the variation options
                                            $option_value_id = array_search(strtolower($old_data->size), $productOptions);
                                            $product_option_value = ProductOptionValue::where('product_option_id',$variant->product->variantOption->id)->where('option_value_id',$option_value_id)->first();                                        
                                        }else {
                                            //if its not in the product
                                            //add in option values
                                            $option_value = OptionValue::firstOrCreate(['option_id'=>$variant->product->variantOption->option_id,'value'=>strtoupper($old_data->size)]);
                                            //add to product option value
                                            $product_option_value = ProductOptionValue::firstOrCreate(['product_option_id'=>$variant->product->variantOption->id,'option_value_id'=>$option_value->id]);
                                        }
                                        //add it in the variation and register it with the chip
                                        if ($product_option_value) {
                                            //add the size to the variant options first
                                            ProductAttribute::firstOrCreate(['product_variant_id'=>$variant->id,'product_option_id'=>$variant->product->variantOption->id, 'product_option_value_id'=>$product_option_value->id]);
                                            //then add it in the registration for the chip
                                            $variantOption = VariantOption::firstOrCreate(['product_option_id'=>$variant->product->variantOption->id, 'product_option_value_id'=>$product_option_value->id]);
                                            VariantOptionTransaction::firstOrCreate(['optionable_id'=>$chip_registration->id, 'optionable_type'=>'chip_registration', 'variant_options_id'=>$variantOption->id]);
                                        }
                                    } else{
                                        $not_imported_chips[] = $old_data->chip_no;  
                                    }                               
                                } 
                            }                                                       
                        }                              
                    }
                }else 
                    $not_imported_chips[] = $old_data->chip_no;   

            }); //eof db transactions
        }//eof foreach

        // dd($not_imported_chips);
        //remove the existing transactions in case of re-import
        // $transactions = ChipTransaction::select('chip_transactions.*')
        //         ->join('chip_transaction_logs as ctl','ctl.chip_transaction_id','=','chip_transactions.id')
        //         ->join('chip_registrations as cr','cr.id','=','ctl.chip_registration_id')
        //         ->join('chips as c','c.id','=','cr.chip_id')
        //         ->whereIn('chip_transactions.scanner_type',[ChipScannerType::PACKING,ChipScannerType::SORTING])
        //         ->whereIn('c.chip_no',$chip_nos)->delete();
        
        DB::transaction(function () use($chip_nos) {
            //get the chip packing data            
            $chip_tmp = DB::connection('mysql5')->table('chip_tmp')->whereIn('chip_id',$chip_nos)->orderBy('created_at')->get();  
            $this->importTransactions($chip_tmp,ChipScannerType::TXT_PACKING);
        });
        
        DB::transaction(function () use($chip_nos) {           
            //get the chip sorting data            
            $chip_sorting_tmp = DB::connection('mysql5')->table('chip_sorting_tmp')->whereIn('chip_id',$chip_nos)->orderBy('created_at')->get();   

            $this->importTransactions($chip_sorting_tmp,ChipScannerType::TXT_SORTING);
        });
        
        return response()->json(['success'=>true,'not_imported_chips'=>$not_imported_chips]);
    }

    private function importTransactions($temp_data = [], $scannerType=null) 
    {        
        foreach ($temp_data as $tmp) {
            if(!$tmp->scanned_by) $tmp->scanned_by = '10.2.0.6'; //drs default

            $scanner = ChipScanner::where('ip',$tmp->scanned_by)->first();
            if (!$scanner) ChipScanner::create(['ip'=>$tmp->scanned_by]);

            // $chip_reg = Chip::where('chip_no',$tmp->chip_id)->first()->registration();
            $chip_reg = ChipRegistration::withTrashed()->select('chip_registrations.*')
                            ->join('chips as c', 'c.id','=','chip_registrations.chip_id')
                            ->where('c.chip_no',$tmp->chip_id)
                            ->where('chip_registrations.created_at', '<=', $tmp->created_at)
                            ->orderBy('chip_registrations.created_at','desc')
                            ->first();
                      
            if (!$chip_reg) {
                //check for any earliest registration
                $chip_reg = ChipRegistration::withTrashed()->select('chip_registrations.*')
                            ->join('chips as c', 'c.id','=','chip_registrations.chip_id')
                            ->where('c.chip_no',$tmp->chip_id)
                            ->orderBy('chip_registrations.created_at','desc')
                            ->first();
            }

            $scanner_type = ChipScannerType::where('type',$scannerType)->first();
            if (!$scanner_type) ChipScanner::create(['type'=>$scannerType]);
            // if($scannerType == ChipScannerType::TXT_SORTING) dd($chip_reg);
            if ($chip_reg) {
                $ldate = date('Y-m-d',strtotime($tmp->created_at));
                $tran_log = ChipTransactionLog::join('chip_transactions as ct','ct.id','=','chip_transaction_logs.chip_transaction_id')
                                ->where('ct.scanner_type', $scanner_type->id)
                                ->where('chip_transaction_logs.chip_registration_id',$chip_reg->id)
                                ->whereRaw("DATE_FORMAT(chip_transaction_logs.created_at,'%Y-%m-%d') = '$ldate'")
                                ->first();
                // $tlog = with(clone $tran_log_query)->where('chip_transaction_logs.created_at',$tmp->created_at)->first();
                // $tlogs = with(clone $tran_log_query)->fi();
                // if(count($tlogs) > 0) continue;
                if ($tran_log) continue;

                // $done = false;
                // $created_date = date('Y-m-d',strtotime($tmp->created_at));
                // foreach ($tlogs as $key => $l) {
                //     $ldate = date('Y-m-d',strtotime($l->created_at));
                //     if ($ldate == $created_date) {
                //         $done = true;
                //         break;
                //     }
                // }
                // if ($done) continue;

                //get first delivery
                // $deliveries = Delivery::select('deliveries.id','deliveries.delivery_date','deliveries.is_packed')
                //             ->join('subscription_schedules as ss','ss.id','=','deliveries.subscription_schedule_id')
                //             ->join('subscriptions as s','s.id','=','ss.subscription_id')
                //             ->where('deliveries.customer_id',$chip_reg->customer_id)
                //             ->where('s.product_id',$chip_reg->product_id)
                //             ->where('s.product_variant_id',$chip_reg->product_variation_id)
                //             ->orderBy('deliveries.delivery_date')->get();                
                // dd($deliveries);

                $transaction = ChipTransaction::create(['total_amount'=> 1, 'created_at' => $tmp->created_at,'scanner_type'=>$scanner_type->id, 'chip_scanner_id'=>$scanner->id]);
                ChipTransactionLog::create(['chip_transaction_id'=>$transaction->id, 'chip_registration_id'=>$chip_reg->id, 'created_at' => $tmp->created_at]);
                //record the changes to the deliveries and what transaction it belongs
                //no delivery yet for drs
                // foreach ($deliveries as $key => $delivery) {
                //     if ($scannerType == ChipScannerType::TXT_PACKING) {
                //         if (Carbon::parse(date('Y-m-d',strtotime($tmp->created_at)))->lt(Carbon::parse($delivery->delivery_date))){
                //             ChipDeliveryTransaction::create(['chip_transaction_id'=> $transaction->id, 'delivery_id'=>$delivery->id, 'created_at' => $tmp->created_at]);
                //             if ($delivery->is_packed == 0) $delivery->update(['is_packed'=>1,'status'=>'delivered']);

                //             break;
                //         }
                //     }else {
                //         //ChipScannerType::TXT_SORTING
                //         if (Carbon::parse(date('Y-m-d',strtotime($tmp->created_at)))->gte(Carbon::parse($delivery->delivery_date)) &&
                //             Carbon::parse(date('Y-m-d',strtotime($tmp->created_at)))->lt(Carbon::parse($deliveries[$key+1]->delivery_date))){

                //             ChipDeliveryTransaction::create(['chip_transaction_id'=> $transaction->id, 'delivery_id'=>$delivery->id, 'created_at' => $tmp->created_at]);
                //             if ($delivery->is_packed == 0) $delivery->update(['is_packed'=>1,'status'=>'delivered']);

                //             break;
                //         }
                //     }
                // }
            }               
        }
        return true;
    }

    //generate deliveries from chip history (for DRS)
    public function generateDeliveries()
    {
        $repo = new DeliveryRepository;
        $customer_id = Input::get('id');
        $customer = Customer::with('subscriptions')->find($customer_id);

        $subscriptions = $customer->subscriptions()->with('schedules')->whereNull('termination_date')->where('status','ACTIVE')->get();
        $num_generated = 0;
        foreach ($subscriptions as $key => $subscription) {
            $subscriptionSchedule = $subscription->schedules()->first();
            if ($subscriptionSchedule) {
                $subscriptionSchedule->c_route_schedule_id = $customer->route_schedule_id;

                $no = $repo->generate($subscriptionSchedule, $subscription->getOriginal()['start_date'], date('Y-m-d'));  //generate deliveries from the start of subscription up to present
                $num_generated += $no;  
            }                
               
        }
        $msg = "Generated $num_generated deliveries for customer ". $customer->dist_name ." ($customer_id).";
        return response()->json(['result'=>true, 'message'=> $msg]);
    }

    public function registrationList()
    {
        return view('chips.registration_list');
    }

    public function registrationDatatable()
    {
        // dd(Input::all());
        $registrations = ChipRegistration::select(DB::raw('chip_registrations.*,c.chip_no,cu.dist_name as customer,
                        ce.name as customer_employee, p.name as product, pv.name as product_variant'))
                        ->join('chips as c','c.id','=','chip_registrations.chip_id')
                        ->leftJoin('customers as cu','cu.id','=','chip_registrations.customer_id')
                        ->leftJoin('customer_employees as ce','ce.id','=','chip_registrations.customer_employee_id')
                        ->leftJoin('products as p','p.id','=','chip_registrations.product_id')
                        ->leftJoin('product_variants as pv','pv.id','=','chip_registrations.product_variation_id');

        // dd(Input::get('customer_id'));
        if (Input::get('customer_id')) $registrations->where('chip_registrations.customer_id',Input::get('customer_id'));
        if (Input::get('product_id')) $registrations->where('chip_registrations.product_id',Input::get('product_id'));
        if (Input::get('product_variant_id')) $registrations->where('chip_registrations.product_variation_id',Input::get('product_variant_id'));
        if (Input::get('customer_employee_id')) $registrations->where('chip_registrations.customer_employee_id',Input::get('customer_employee_id'));

        // $registrations = $registrations->get();

        return Datatables::of($registrations)
            ->editColumn('size',function($registrations){
                return $registrations->productVariantSize();
                })
           ->make(true);
    }




    public function transactions(Request $request)
    {
        $chip_registrations = [];
        $sortings = [];
        $packings = [];
        $chip_no = trim($request->chip_no);
        $customer_id = trim($request->customer_id);
        $employee_id = trim($request->employee_id);
        $employee_name = trim($request->employee_name);
        $product_id = trim($request->product_id);
        $product_nr = trim($request->product_nr);

        if(isset($request->customer_id)){
            $chip_registrations_query =  DB::connection('mysql4')->table('chip_data as cd')
            ->select(DB::raw('c.chip_no, cd.*, p.name as product,s.size,cu.name as customer,ce.name as employee, p.product_nr, c.chip_data_id, c.updated_at'))
            ->join('chips as c','c.id','=','cd.chip_id')
            ->join('products as p','cd.product_id','=','p.id')
            ->leftJoin('product_sizes as s','cd.product_size_id','=','s.id')
            ->leftJoin('customers as cu','cu.id','=', DB::raw("(IF(cd.customer_id, cd.customer_id,cd.permanent_customer_id))"))
            ->leftJoin('customer_employees as ce','cd.employee_id','=','ce.id');
        
        
            if($request->chip_no){
                $chip_registrations_query =  $chip_registrations_query->where('c.chip_no', $chip_no);
            }
            
            if($request->customer_id){
                $chip_registrations_query =  $chip_registrations_query->where('cd.customer_id', $customer_id);
            }
            
            if($request->employee_id){
                $chip_registrations_query =  $chip_registrations_query->where('cd.employee_id', $employee_id);
            }
            
            if($request->employee_name){
                $chip_registrations_query =  $chip_registrations_query->where('ce.name', 'LIKE', "%$employee_name%");
            }
            
            if($request->product_id){
                $chip_registrations_query =  $chip_registrations_query->where('cd.product_id', $product_id);
            }
            
            if($request->product_nr){
                $chip_registrations_query =  $chip_registrations_query->where('p.product_nr', $product_nr);
            }
                
            $chip_registrations =  $chip_registrations_query->get();
            $chip_nos = $chip_registrations_query->orderBy('cd.id')->lists('chip_no');

            $sortings = DB::connection('mysql5')->table('chip_sorting_tmp')
                ->whereIn('chip_id', $chip_nos)
                ->get();

            $packings = DB::connection('mysql5')->table('chip_tmp')
                ->whereIn('chip_id', $chip_nos)
                ->get();
        }
        

        return view('chips.transactions', compact('chip_registrations','packings','sortings','search'));
    }

    public function getChipTransactions(Request $request, $param_chips=null)
    {  
        if ($param_chips) {
            $chip_nos = array_filter(explode(',', $param_chips));
        }else      
            $chip_nos = array_filter(explode(',', $request->chip_no));
        
        if (count($chip_nos) > 0) {
            $chips = Chip::with(['registrations'=>function($query){$query->withTrashed();}])->whereIn('chip_no', array_map('trim',$chip_nos))->get();
            
            foreach($chips as $chip) {
                $chip_registrations = $chip->registrations;
                $latest_cr = $chip->registration(); 
                $registrations = collect([]);
                $sortings = collect([]);
                $packings = collect([]);
                foreach ($chip_registrations as $key => $registration) {
                    $registration = ChipRegistration::withTrashed()
                                        ->with('chip','customer', 'customerEmployee', 'product', 'productVariant')
                                        ->select('chip_registrations.*', 'size.value as size')
                                        ->leftJoin(DB::raw("(
                                                            select vot.optionable_id, ov.value
                                                            from variant_options_transactions vot
                                                            join variant_options vo on vo.id = vot.variant_options_id
                                                            join product_option_values pov on pov.id = vo.product_option_value_id
                                                            join option_values ov on ov.id = pov.option_value_id
                                                            where vot.optionable_type = 'chip_registration'
                                                            ) as size"), 'size.optionable_id', '=', 'chip_registrations.id')
                                        ->where('chip_registrations.id', $registration->id)
                                        ->orderBy('created_at', 'desc')->first();

                    if($registration) $registrations->push($registration); 

                    $baseQuery = ChipTransactionLog::with(['chipRegistration'=>function($query){ $query->withTrashed(); }, 'chipRegistration.chip', 'chipTransaction.chipScanner'])
                                                    ->select('chip_transaction_logs.*')
                                                    ->join('chip_transactions as ct', 'ct.id', '=', 'chip_transaction_logs.chip_transaction_id')
                                                    ->where('chip_registration_id', $registration->id)
                                                    ->orderBy('chip_transaction_logs.created_at');

                    $_packings = with(clone $baseQuery)->join('chip_scanner_types as cst','cst.id','=','ct.scanner_type')
                                                      ->where('cst.type', ChipScannerType::TXT_PACKING)
                                                      ->get();

                    if(count($_packings) > 0) $packings = $packings->merge($_packings);

                    $_sortings = with(clone $baseQuery)->join('chip_scanner_types as cst','cst.id','=','ct.scanner_type')
                                                      ->where('cst.type', ChipScannerType::TXT_SORTING)
                                                      ->get();

                    if(count($_sortings) > 0) $sortings = $sortings->merge($_sortings);
                }

                $chip->chip_registrations = $registrations->unique('id');
                $chip->sortings = $sortings->unique('id');
                $chip->packings = $packings->unique('id');
             
                $status = '';
                if ($this->isBurned($chip->id)) $status = 'Brændt';
                else if ($this->isMissing($chip->chip_no)) $status = 'Mangler';
                else if ($latest_cr && $this->isBanned($latest_cr)) $status = 'Banned';
                $chip->status = $status; 
            }

        }else $chips = [];   

        return view('chips.transactions_data', compact('chips','search', 'param_chips'));
    }

    public function regulateChipUp($chip_scanner_id=null)
    {
        $scanners = ChipScanner::all();

        $internal_storages = InternalStorage::where('name', 'Nytlager')->orWhere('name', 'Brugtlager')->lists('name', 'id'); 
        $chip_scanner = false;
        if ($chip_scanner_id) {
        //     $chip_scanner_id = 5;//default scanner
            $chip_scanner = ChipScanner::where('api_id',$chip_scanner_id)->first();
        }
        else if(Auth::user()->chipScanner){
            //user default scanner
            $chip_scanner = Auth::user()->chipScanner;
            $chip_scanner_id = $chip_scanner->api_id;
        }
        

        $since = DB::selectOne("SELECT NOW() AS now")->now;
        return view('chips.regulate.regulate_chips',compact('since','chip_scanner_id', 'chip_scanner', 'scanners', 'internal_storages'));
    }

    

    public function sortingImport()
    {
        $assens = ['6944','6943','6942','6941','6940','6939','6938','6937','6936','6935','6934','6933','6932','6931',
                    '6930','6929','6928','6927','6926','6925','6924','6923','6922','6921','6911','7027','7029','7028'];
        // $assens = ['7027','7029','7028'];
        $customers = Customer::whereIn('id',$assens)->get();    

        if (Input::get('data')){
            $input = Input::only('data');
            $chips = $input['data'];//
            $not_imported_chips = [];

            // $this->fixSortingData($chips);
          

            // // $chips = ['300ED89F3350004000D46656'];
            $chip_sorting_tmp = DB::connection('mysql5')->table('chip_sorting_tmp')->whereIn('chip_id',$chips)
                                ->where('created_at','>','2016-09-23 00:00:01')
                                ->orderBy('created_at')->get(); 
                     
            // // DB::beginTransaction();    

            foreach ($chip_sorting_tmp as $key => $sorting) {
                $scanner =  DB::connection('v3_prod')->table('chip_scanners')->where('ip',$sorting->scanned_by)->first();

                $chip_reg = DB::connection('v3_prod')->table('chip_registrations')->select('chip_registrations.*')
                                ->join('chips as c', 'c.id','=','chip_registrations.chip_id')
                                ->where('c.chip_no',$sorting->chip_id)
                                ->where('chip_registrations.created_at', '<=', $sorting->created_at)
                                ->orderBy('chip_registrations.created_at','desc')
                                ->first();
                             
                if (!$chip_reg) {
                    //check for any earliest registration
                    $chip_reg = DB::connection('v3_prod')->table('chip_registrations')->select('chip_registrations.*')                                
                                ->join('chips as c', 'c.id','=','chip_registrations.chip_id')
                                ->where('c.chip_no',$sorting->chip_id)
                                // ->where('chip_registrations.created_at', '<=', $sorting->created_at),
                                ->orderBy('chip_registrations.created_at','desc')
                                ->first();
                } 

                if ($chip_reg){
                    $tran_log_query = DB::connection('v3_prod')->table('chip_transaction_logs')
                                    ->join('chip_transactions as ct','ct.id','=','chip_transaction_logs.chip_transaction_id')
                                    ->where('ct.scanner_type', ChipScannerType::SORTING)
                                    ->where('chip_transaction_logs.chip_registration_id',$chip_reg->id);

                    $tlog = with(clone $tran_log_query)->where('chip_transaction_logs.created_at',$sorting->created_at)->first();
                    if ($tlog) continue;

                    $done = false;
                    $created_date = date('Y-m-d',strtotime($sorting->created_at));
                    $tlogs = with(clone $tran_log_query)->get();
                    foreach ($tlogs as $key => $l) {
                        $ldate = date('Y-m-d',strtotime($l->created_at));
                        if ($ldate == $created_date) {
                            $done = true;
                            break;
                        }
                    }
             

                    if ($done) continue;
                    //get first delivery
                    $deliveries = DB::connection('v3_prod')->table('deliveries')->select('deliveries.*')
                                ->where('customer_id',$chip_reg->customer_id)
                                ->where('product_id',$chip_reg->product_id)
                                ->where('product_variant_id',$chip_reg->product_variation_id)
                                ->orderBy('delivery_date')->get();  


                    $transaction_id = DB::connection('v3_prod')->table('chip_transactions')->insertGetId(['total_amount'=> 1, 'created_at' => $sorting->created_at,'scanner_type'=>ChipScannerType::SORTING, 'chip_scanner_id'=>$scanner->id]);
                    DB::connection('v3_prod')->table('chip_transaction_logs')->insert(['chip_transaction_id'=>$transaction_id, 'chip_registration_id'=>$chip_reg->id, 'created_at' => $sorting->created_at]);
                    //record the changes to the deliveries and what transaction it belongs
                    foreach ($deliveries as $key => $delivery) {
                        if (Carbon::parse(date('Y-m-d',strtotime($sorting->created_at)))->gte(Carbon::parse($delivery->delivery_date)) &&
                            Carbon::parse(date('Y-m-d',strtotime($sorting->created_at)))->lt(Carbon::parse($deliveries[$key+1]->delivery_date))){
                           
                            DB::connection('v3_prod')->table('chip_delivery_transactions')->insert(['chip_transaction_id'=> $transaction_id, 'delivery_id'=>$delivery->id, 'created_at' => $sorting->created_at]);
                          
                            // if ($delivery->is_packed == 0) {
                            //     DB::connection('v3_prod')->table('deliveries')->where('id',$delivery->id)
                            //                             ->update(['is_packed'=>1,'status'=>'delivered']);
                            // }

                            break;
                        }
                    }
                }
                else {
                    $not_imported_chips[] = $sorting->chip_id;
                }    






                // $scanner = ChipScanner::firstOrCreate(['ip'=>$sorting->scanned_by]);
             
                // $chip_reg = ChipRegistration::withTrashed()->select('chip_registrations.*')
                //                 ->join('chips as c', 'c.id','=','chip_registrations.chip_id')
                //                 ->where('c.chip_no',$sorting->chip_id)
                //                 ->where('chip_registrations.created_at', '<=', $sorting->created_at)
                //                 ->orderBy('chip_registrations.created_at','desc')
                //                 ->first();
                            
                // if (!$chip_reg) {
                //     //check for any earliest registration
                //     $chip_reg = ChipRegistration::withTrashed()->select('chip_registrations.*')// DB::connection('mysql6')->table('chip_registrations')                                
                //                 ->join('chips as c', 'c.id','=','chip_registrations.chip_id')
                //                 ->where('c.chip_no',$sorting->chip_id)
                //                 // ->where('chip_registrations.created_at', '<=', $sorting->created_at),
                //                 ->orderBy('chip_registrations.created_at','desc')
                //                 ->first();
                // }
              
                // if ($chip_reg){

                //     $tran_log_query = ChipTransactionLog::join('chip_transactions as ct','ct.id','=','chip_transaction_logs.chip_transaction_id')
                //                     // ->join('chip_transactions as ct','ct.id','=','chip_transaction_logs.chip_transaction_id')
                //                     ->where('ct.scanner_type', ChipScannerType::SORTING)
                //                     ->where('chip_transaction_logs.chip_registration_id',$chip_reg->id);

                //     $tlog = with(clone $tran_log_query)->where('chip_transaction_logs.created_at',$sorting->created_at)->first();
                                   
                //     if ($tlog) continue;

                //     $done = false;
                //     $created_date = date('Y-m-d',strtotime($sorting->created_at));
                //     $tlogs = with(clone $tran_log_query)->get();
                //     foreach ($tlogs as $l) {
                //         $ldate = date('Y-m-d',strtotime($l->created_at));
                
                //         if ($ldate == $created_date) {
                //             $done = true;
                //             break;
                //         }
                //     }
             
                //     if ($done) continue;

                //     //get first delivery
                //     $deliveries = Delivery::select('deliveries.*') //DB::connection('mysql6')->table('deliveries')
                //                 ->where('deliveries.customer_id',$chip_reg->customer_id)
                //                 ->where('deliveries.product_id',$chip_reg->product_id)
                //                 ->where('deliveries.product_variant_id',$chip_reg->product_variation_id)
                //                 ->orderBy('deliveries.delivery_date')->get();  

                //     $transaction = ChipTransaction::create(['total_amount'=> 1, 'created_at' => $sorting->created_at,'scanner_type'=>ChipScannerType::SORTING, 'chip_scanner_id'=>$scanner->id]);

                //     ChipTransactionLog::create(['chip_transaction_id'=>$transaction->id, 'chip_registration_id'=>$chip_reg->id, 'created_at' => $sorting->created_at]);
                //     //record the changes to the deliveries and what transaction it belongs
                //     foreach ($deliveries as $key => $delivery) {
                //         if (Carbon::parse(date('Y-m-d',strtotime($sorting->created_at)))->gte(Carbon::parse($delivery->delivery_date)) &&
                //             Carbon::parse(date('Y-m-d',strtotime($sorting->created_at)))->lt(Carbon::parse($deliveries[$key+1]->delivery_date))){
                           
                //             ChipDeliveryTransaction::create(['chip_transaction_id'=> $transaction->id, 'delivery_id'=>$delivery->id, 'created_at' => $sorting->created_at]);
                //             if ($delivery->is_packed == 0) {
                //                 $delivery->update(['is_packed'=>1,'status'=>'delivered']);
                //             }

                //             break;
                //         }
                //     }
                // } else {
                //     $not_imported_chips[] = $sorting->chip_id;
                // }
                    
            }
            // DB::commit();    
            return response()->json(['success'=>true,'not_imported_chips'=>$not_imported_chips]);
        }
          
        
        return view('chips.sorting.sorting_import', compact('customers'));
    }

    private function fixSortingData($chips)
    {
        // $chips = ['300ED89F33500060001E228E','300ED89F3350004000A73B51'];//['300ED89F3350004000A73B51'];
        // foreach ($chips as $key => $chip) {
        //     $sortings = ChipTransactionLog::select('chip_transaction_logs.*','c.chip_no','cr.created_at as registration_date', 'cr.deleted_at as registration_end_date')
        //             ->join('chip_registrations as cr','cr.id','=','chip_transaction_logs.chip_registration_id')
        //             ->join('chip_transactions as ct','ct.id','=','chip_transaction_logs.chip_transaction_id')
        //             ->join('chips as c','c.id','=','cr.chip_id')
        //             ->where('c.chip_no',$chip)
        //             ->where('ct.scanner_type', ChipScannerType::SORTING)
        //             ->get();

        //     if (count($sortings) <= 0) continue;

        //     //remove multiple sorting in a day
        //     $sorting_date = [];
        //     $final_sortings = [];
        //     foreach ($sortings as $key => $s) {
        //         $date = date('Y-m-d',strtotime($s->created_at));
        //         if (!$sorting_date || !isset($sorting_date[$s->chip_registration_id])){
        //             $sorting_date[$s->chip_registration_id] = [];
        //             $sorting_date[$s->chip_registration_id][] = $date;
        //             $final_sortings[] = $s;
        //         }
        //         else{
        //             if (in_array($date, $sorting_date[$s->chip_registration_id]))
        //                 $s->delete();
        //             else{
        //                 $sorting_date[$s->chip_registration_id][] = $date;
        //                 $final_sortings[] = $s;
        //             }
        //         }
        //     }

        //     foreach ($final_sortings as $k => $s) {

        //         if ($s->registration_end_date && (strtotime($s->created_at) > strtotime($s->registration_end_date))) {
        //             $registration = ChipRegistration::withTrashed()
        //                             ->select('chip_registrations.*')
        //                             ->join('chips as c','c.id','=','chip_registrations.chip_id')
        //                             ->where('c.chip_no',$chip)
        //                             ->where('chip_registrations.created_at','<=',$s->created_at)
        //                             ->orderBy('chip_registrations.created_at','desc')
        //                             ->first();

        //             if ($registration) $s->update(['chip_registration_id'=>$registration->id]);     
        //         }

        //     }

        // }



        foreach ($chips as $key => $chip) {
            $sortings = DB::connection('v3_prod')->table('chip_transaction_logs')
                    ->select('chip_transaction_logs.*','c.chip_no','cr.created_at as registration_date', 'cr.deleted_at as registration_end_date')
                    ->join('chip_registrations as cr','cr.id','=','chip_transaction_logs.chip_registration_id')
                    ->join('chip_transactions as ct','ct.id','=','chip_transaction_logs.chip_transaction_id')
                    ->join('chips as c','c.id','=','cr.chip_id')
                    ->where('c.chip_no',$chip)
                    ->where('ct.scanner_type', ChipScannerType::SORTING)
                    ->get();

            if (count($sortings) <= 0) continue;

            //remove multiple sorting in a day
            $sorting_date = [];
            $final_sortings = [];
            foreach ($sortings as $key => $s) {
                $date = date('Y-m-d',strtotime($s->created_at));
                if (!$sorting_date || !isset($sorting_date[$s->chip_registration_id])){
                    $sorting_date[$s->chip_registration_id] = [];
                    $sorting_date[$s->chip_registration_id][] = $date;
                    $final_sortings[] = $s;
                }
                else{
                    if (in_array($date, $sorting_date[$s->chip_registration_id])){

                        DB::table('trashed_logs')->insert([
                                'chip_registration_id' => $s->chip_registration_id,
                                'chip_transaction_id' => $s->chip_transaction_id,
                                'cr_transaction_id' => ($s->cr_transaction_id) ? $s->cr_transaction_id: NULL,
                                'created_at' => $s->created_at
                            ]);
                        DB::connection('v3_prod')->table('chip_transaction_logs')
                                                ->where('id',$s->id)
                                                ->delete();

                    }
                        
                    else{
                        $sorting_date[$s->chip_registration_id][] = $date;
                        $final_sortings[] = $s;
                    }
                }
                
            }

            //update the registration issues
            foreach ($final_sortings as $k => $s) {

                if ($s->registration_end_date && (strtotime($s->created_at) > strtotime($s->registration_end_date))) {

                    $registration = DB::connection('v3_prod')->table('chip_registrations')->select('chip_registrations.*')
                                    ->join('chips as c','c.id','=','chip_registrations.chip_id')
                                    ->where('c.chip_no',$chip)
                                    ->where('chip_registrations.created_at','<=',$s->created_at)
                                    ->orderBy('chip_registrations.created_at','desc')
                                    ->first();

                    if ($registration) {
                        DB::connection('v3_prod')->table('chip_transaction_logs')
                                                ->where('id',$s->id)
                                                ->update(['chip_registration_id'=>$registration->id]);
                    }     
                }

            }

        }

    }

    public function doInitialSortingImport(Request $request)
    {
        $input = Input::except('_token','customers_table_length');
        $input['check_all'] = isset($input['check_all']) ? true : false;
        $customer_ids = $input['customer_ids'];
        // $customer_id = $input['id'];
        $old_chips = $old_customer_ids = [];
        if (count($customer_ids) <= 0) return; 
        $customers = Customer::whereIn('id',$customer_ids)->get();
        foreach ($customers as $customer) {
            $old_customer = CustomerImportRepository::withNewReference($customer->id);
            if ($old_customer) $old_customer_ids[] = $old_customer->old_customer_id;
        }
        if (count($old_customer_ids) > 0) {

            $old_chip_data =  DB::connection('mysql3')
            ->table('chips as c')->select('cd.id')
            ->join('chip_data as cd','c.chip_data_id','=','cd.id')
            ->whereIn('cd.customer_id', $old_customer_ids)
            // ->groupBy('c.chip_no')
            ->get();
            $temp_arr = [];
            foreach ($old_chip_data as $data) {
                $old_chips[] = $data->id;
            }
            // dd($old_chips);
            $old_chips = array_chunk($old_chips, 100);
        }

        $aflex_chips = ChipRegistration::withTrashed()->with('chip')
                ->select(DB::raw("c.chip_no"))
                ->join('chips as c','c.id','=','chip_registrations.chip_id')
                ->whereIn('chip_registrations.customer_id',$customer_ids)
                // ->where('c.chip_no','300ED89F3350004000A73B18')
                ->groupBy('c.chip_no')->get();
        $aflex_chips_arr = $aflex_chips->pluck('chip_no')->toArray();
        $chips = array_chunk($aflex_chips_arr, 15);
       

        // $imported_customers_file = base_path().'\files\imported_customers_do_not_delete.txt';
        // $imported_customers_file = base_path().'\files\imported_customers_do_not_delete_new.txt';
        $file_string = "chips_log/imported_customers_do_not_delete_new.txt";
        if (!$request->session()->has('imported_customers')) {
            $request->session()->put('imported_customers', $customer_ids);
            AppStorage::put($file_string, implode(',',$customer_ids));
            // File::put($imported_customers_file, implode(',',$customer_ids));
        }else {
            $session_customers = $request->session()->pull('imported_customers');
            $session_customers = array_filter(array_merge($session_customers, $customer_ids));
            $request->session()->put('imported_customers', $session_customers);
            // File::put($imported_customers_file, implode(',',$session_customers));
            AppStorage::put($file_string, implode(',',$session_customers));
        }
        // dd($chips);
        return $chips;
    }

    private function importSorting()
    {
        $input = Input::only('data');
        $chips = $input['data'];//
        $not_imported_chips = [];
        $chip_sorting_tmp = DB::connection('mysql5')->table('chip_sorting_tmp')->whereIn('chip_id',$chips)
                            ->where('created_at','>','2016-09-22 00:00:00')
                            ->orderBy('created_at')->get(); 
                    
        DB::beginTransaction();                    
        foreach ($chip_sorting_tmp as $key => $sorting) {
            $scanner = ChipScanner::firstOrCreate(['ip'=>$sorting->scanned_by]);

            $chip_reg = ChipRegistration::withTrashed()->select('chip_registrations.*')
                            ->join('chips as c', 'c.id','=','chip_registrations.chip_id')
                            ->where('c.chip_no',$sorting->chip_id)
                            ->where('chip_registrations.created_at', '<=', $sorting->created_at)
                            ->orderBy('chip_registrations.created','desc')
                            ->first();

            if (!$chip_reg) {
                //check for any earliest registration
                $chip_reg = ChipRegistration::withTrashed()->select('chip_registrations.*')
                            ->join('chips as c', 'c.id','=','chip_registrations.chip_id')
                            ->where('c.chip_no',$sorting->chip_id)
                            // ->where('chip_registrations.created_at', '<=', $sorting->created_at),
                            ->orderBy('chip_registrations.created_at','desc')
                            ->first();
            }
          
            if ($chip_reg){

                $tran_log_query = ChipTransactionLog::join('chip_transactions as ct','ct.id','=','chip_transaction_logs.chip_transaction_id')
                                ->where('ct.scanner_type', ChipScannerType::SORTING)
                                ->where('chip_transaction_logs.chip_registration_id',$chip_reg->id);

                $tlog = with(clone $tran_log_query)->where('chip_transaction_logs.created_at',$sorting->created_at)->first();
                if ($tlog) continue;

                $done = false;
                $created_date = date('Y-m-d',strtotime($sorting->created_at));
                $tlogs = with(clone $tran_log_query)->get();
                foreach ($tlogs as $key => $l) {
                    $ldate = date('Y-m-d',strtotime($l->created_at));
                    if ($ldate == $created_date) {
                        $done = true;
                        break;
                    }
                }
                if ($done) continue;

                //get first delivery
                $deliveries = Delivery::select('deliveries.*')
                            ->join('subscription_schedules as ss','ss.id','=','deliveries.subscription_schedule_id')
                            ->join('subscriptions as s','s.id','=','ss.subscription_id')
                            ->where('deliveries.customer_id',$chip_reg->customer_id)
                            ->where('s.product_id',$chip_reg->product_id)
                            ->where('s.product_variant_id',$chip_reg->product_variation_id)
                            ->orderBy('deliveries.delivery_date')->get();  

                $transaction = ChipTransaction::create(['total_amount'=> 1, 'created_at' => $sorting->created_at,'scanner_type'=>ChipScannerType::SORTING, 'chip_scanner_id'=>$scanner->id]);

                ChipTransactionLog::create(['chip_transaction_id'=>$transaction->id, 'chip_registration_id'=>$chip_reg->id, 'created_at' => $sorting->created_at]);
                //record the changes to the deliveries and what transaction it belongs
                foreach ($deliveries as $key => $delivery) {
                    if (Carbon::parse(date('Y-m-d',strtotime($sorting->created_at)))->gte(Carbon::parse($delivery->delivery_date)) &&
                        Carbon::parse(date('Y-m-d',strtotime($sorting->created_at)))->lt(Carbon::parse($deliveries[$key+1]->delivery_date))){

                        ChipDeliveryTransaction::create(['chip_transaction_id'=> $transaction->id, 'delivery_id'=>$delivery->id, 'created_at' => $sorting->created_at]);
                        if ($delivery->is_packed == 0) $delivery->update(['is_packed'=>1,'status'=>'delivered']);

                        break;
                    }
                }
            } else {
                $not_imported_chips[] = $sorting->chip_id;
            }
                
        }
        DB::commit();    
        return response()->json(['success'=>true,'not_imported_chips'=>$not_imported_chips]);
    }
    public function showChipRegisration(Request $request)
    {
       
        $product_nr_list = ProductVariant::lists('product_nr', 'id');
        $employee_list = CustomerEmployee::select(
                            DB::raw("CONCAT(id,' --  ', name) AS employee, id")
                            )->lists('employee', 'id');

        $customer_list = Customer::select(
                            DB::raw("CONCAT(id,' -  ', dist_name) AS name, id")
                            )->lists('name', 'id');
        // dd($customer_list);

        $chip_registrations = [];
        $sortings = [];
        $packings = [];
        $chip_no = trim($request->chip_no);
        $sel_employees = [];
        $registrations = [];
        // $customer_id = trim($request->customer_id);
        // $employee_id = trim($request->employee_id);
        // $employee_name = trim($request->employee_name);
        $product_id = trim($request->product_id);
        // $product_nr = trim($request->product_nr);
        $size = trim($request->size);
       
        if(isset($request->customer_id)){
            $subscriptions = Customer::find($request->customer_id)->subscriptions()->get();
            // dd($subscriptions);
            foreach ($subscriptions as $subscription) {
                $chip_registrations_query = ChipRegistration::join('chips as c', 'chip_registrations.chip_id', '=', 'c.id')

                    ->leftJoin('chip_transaction_logs as ctl', 'chip_registrations.id', '=', 'ctl.chip_registration_id')
                    // ->leftJoin('chip_registration_transactions as crt', 'crt.id', '=', 'chip_registrations.latest_cr_transaction_id')
                    // ->leftJoin('chip_transactions as ct', function($query){
                    //     $query->on('ctl.chip_transaction_id', '=', 'ct.id');
                    // })
                    ->join('products as p', 'p.id', '=', 'chip_registrations.product_id')
                    ->leftJoin('product_variants as pv', 'pv.id', '=', 'chip_registrations.product_variation_id')
                    // ->join('customers as cu', 'cu.id', '=', 'chip_registrations.customer_id')
                    // ->leftJoin('customer_employees as ce', 'ce.id', '=', 'crt.employee_id')
                     ->leftJoin('variant_options_transactions as vot', function($query)
                        {
                            $query->on('vot.optionable_id', '=', 'chip_registrations.id')->where('vot.optionable_type', '=', 'chip_registration');
                        })
                    ->leftJoin('variant_options as vo', 'vo.id', '=', 'vot.variant_options_id')
                    ->leftJoin('product_option_values as pov', 'pov.id', '=', 'vo.product_option_value_id')
                    ->leftJoin('option_values as ov', 'ov.id', '=', 'pov.option_value_id')

                    ->leftJoin('chip_transaction_logs as ctl2', function($query){
                        $query->on('ctl.chip_registration_id', '=', 'ctl2.chip_registration_id');
                        $query->on('ctl.created_at', '<', 'ctl2.created_at');
                    })
                    ->leftJoin('chip_transactions as ct', 'ctl.chip_transaction_id', '=', 'ct.id')
                    ->leftJoin('chip_scanner_types as cst', 'cst.id', '=', 'ct.scanner_type')
                    ->leftJoin('chip_missing as cm',function($query){
                        $query->on('cm.chip_registration_id','=','chip_registrations.id')->whereNull('cm.deleted_at');
                    })
                    ->leftJoin('broken_chips as bc',function($query){
                        $query->on('bc.chip_id','=','c.id')->where('bc.status','=','burned');
                    })
                    ->leftJoin('banned_chips', 'banned_chips.chip_registration_id', '=', 'chip_registrations.id')
                    ->leftJoin('banned_reasons', 'banned_reasons.id', '=', 'banned_chips.banned_reason_id')
                    // LEFT JOIN `chip_transactions` AS `ct` ON `ctl`.`chip_transaction_id` = `ct`.`id`
                    ->whereNull('ctl2.created_at')
                    // ->where('crt.customer_id', $request->customer_id)
                    // ->whereIn('crt.employee_id', $request->employee_id)
                    
                   
                    ->select('c.chip_no as chip_no', 'cu.name as customer', 'ce.name as employee', 'pv.name as product', 'pv.product_nr', 'ov.value as size', 'chip_registrations.created_at as created_at', 'chip_registrations.deleted_at','cu.id as customer_id', 
                        'ce.id as employee_id','pv.product_id','pv.id as product_variant_id', 'chip_registrations.id as chip_registration_id', 'cst.type', 'ctl.created_at as seen_date', 'vo.product_option_id as vo_product_option_id', 
                        'chip_registrations.product_id', DB::raw('IF(cm.chip_registration_id, 1, 0) as is_missing, IF(bc.id, 1, 0) as is_burned'), 'banned_reasons.name as banned_reason')
                    
                    // ->groupBy('c.chip_no')
                    ->orderBy('ctl.created_at', 'c.chip_no', 'DESC');
                if($subscription->take_from_subscription_id) {
                    $chip_registrations_query = $chip_registrations_query->leftJoin('chip_registration_transactions as crt', 'crt.id', '=', 'chip_registrations.latest_cr_transaction_id')
                                                ->join('customers as cu', 'cu.id', '=', 'crt.customer_id')
                                                ->leftJoin('customer_employees as ce', 'ce.id', '=', 'crt.employee_id');
                    if($request->customer_id){   
                            $chip_registrations_query =  $chip_registrations_query->where('crt.customer_id', $request->customer_id);
                    } 
                    if($request->employee_id){   
                      
                        $chip_registrations_query =  $chip_registrations_query->whereIn('crt.employee_id', $request->employee_id);
                     
                        $sel_employees = CustomerEmployee::select(DB::raw("concat(no,' - ', name) as text, id"))->whereIn('id', $request->employee_id)->get()->toArray();
                    } 
                        
                } if($subscription->take_from_subscription_id == null) {

                    $chip_registrations_query = $chip_registrations_query->join('customers as cu', 'cu.id', '=', 'chip_registrations.customer_id')
                                                ->leftJoin('customer_employees as ce', 'ce.id', '=', 'chip_registrations.customer_employee_id');
                    if($request->customer_id){   
                            $chip_registrations_query =  $chip_registrations_query->where('chip_registrations.customer_id', $request->customer_id);
                    } 
                    if($request->employee_id){   
                      
                        $chip_registrations_query =  $chip_registrations_query->whereIn('chip_registrations.customer_employee_id', $request->employee_id);
                     
                        $sel_employees = CustomerEmployee::select(DB::raw("concat(IF(no,concat(no,' - '),''), name) as text, id"))->whereIn('id', $request->employee_id)->get()->toArray();
                    } 
                }

             
                if($request->chip_no){
                    $array = explode(',', $request->chip_no);
                    // dd($array);
                    $chip_registrations_query =  $chip_registrations_query->whereIn('c.chip_no', $array);
                }
                
                
                if($request->product_id){
                    $chip_registrations_query =  $chip_registrations_query->where('chip_registrations.product_id', $product_id);
                }

                if($request->size){
                    $chip_registrations_query =  $chip_registrations_query->where('ov.value', 'LIKE', "$size");
                }
                
                if($request->product_nr){
                     
                    $chip_registrations_query =  $chip_registrations_query->whereIn('pv.id', $request->product_nr);
                }

                if($request->type){
                    if($request->type !== ""){

                        if ($request->type == 'br'){
                            $chip_registrations_query =  $chip_registrations_query->whereNotNull('bc.id');
                        }                            
                        else 
                            $chip_registrations_query =  $chip_registrations_query->where('cst.type', $request->type);
                    }
                }

                if($request->date_from && $request->date_to =="") {
                    $chip_registrations_query = $chip_registrations_query->where('chip_registrations.created_at','>=',$request->date_from.' 23:59:00');
                }
                if($request->date_to && $request->date_from =="") {
                    $chip_registrations_query = $chip_registrations_query->where('chip_registrations.created_at','<=',$request->date_to.' 23:59:00');
                }
                if($request->date_from  !=="" && $request->date_to !== "") {
                    $chip_registrations_query = $chip_registrations_query->where('chip_registrations.created_at','>=',$request->date_from.' 23:59:00')->where('chip_registrations.created_at','<=',$request->date_to.' 23:59:00');
                }


                $chip_registrations =  $chip_registrations_query->withTrashed();

                if( in_array($request->unregistered, ['yes', 'no']) ) {
                    $chip_registrations_query =  ($request->unregistered == 'no')? 
                                                  $chip_registrations_query->where('chip_registrations.deleted_at', null): 
                                                  $chip_registrations_query->whereNotNull('chip_registrations.deleted_at');   
                } 

                $chip_registrations =  $chip_registrations_query->get();

                foreach ($chip_registrations as $cr) {
                    $registrations [] = $cr;
                }
            } //endforeach

            // dd($registrations);
       
            
        }

        
        return view('chips.show_registration_customer', compact('registrations','search', 'product_nr_list', 'employee_list','customer_list','sel_employees'));
        // return view('chips.show_registration_customer');
    }

    public function getEmployeesChipRegistration()
    {
        $customer_id = Input::get('customer_id');
        $term = Input::get('q');

        $employees = CustomerEmployee::where('customer_id', $customer_id)->select(DB::raw("concat(IF(no,concat(no,' - '),''), name) as text, id"))
                    ->where(function($query){
                        $query->whereNull('end_date')->orWhere('end_date', '>', 'now()');
                    });

        if ($term) {
            $employees->where(function($query) use ($term) {
                $query->where('name','like',"%$term%")->orWhere('no','like',"%$term%");
            });
        }
        $employees = $employees->orderBy('no','desc')->get()->toArray();
        
        return response()->json($employees);
    }

    public function DatatableChipRegistraion()
    {
        // return "hello";

            $chip_registrations_query = ChipRegistration::join('chips as c', 'chip_registrations.chip_id', '=', 'c.id')
                ->join('products as p', 'p.id', '=', 'chip_registrations.product_id')
                ->leftJoin('product_variants as pv', 'pv.id', '=', 'chip_registrations.product_variation_id')
                ->join('customers as cu', 'cu.id', '=', 'chip_registrations.customer_id')
                ->leftJoin('customer_employees as ce', 'ce.id', '=', 'chip_registrations.customer_employee_id')
                 ->leftJoin('variant_options_transactions as vot', function($query)
                    {
                        $query->on('vot.optionable_id', '=', 'chip_registrations.id')->where('vot.optionable_type', '=', 'chip_registration');
                    })
                ->leftJoin('variant_options as vo', 'vo.id', '=', 'vot.variant_options_id')
                ->leftJoin('product_option_values as pov', 'pov.id', '=', 'vo.product_option_value_id')
                ->leftJoin('option_values as ov', 'ov.id', '=', 'pov.option_value_id')
                ->select('c.chip_no as chip_no', 'cu.name as customer', 'ce.name as employee', 'pv.name as product', 'pv.product_nr', 'ov.value as size', 'chip_registrations.created_at as created_at', 
                    'chip_registrations.deleted_at','cu.id as customer_id', 'ce.id as employee_id','p.id as product_id', 'pv.id as product_variant_id', 'chip_registrations.id as chip_registration_id')
                // ->withTrashed()
                // ->groupBy('c.chip_no');
               ->get();
               dd($chip_registrations_query);
             // $customer = Customer::all();

           return Datatables::of($chip_registrations_query)->make(true);
            // $chip_registrations =  $chip_registrations_query ->withTrashed()->get();
    
    }

    public function unRegisterChipManualy(Request $request) 
    {
        // dd($request->all());

        $chip_registration = ChipRegistration::select('chip_registrations.*','size.value as size', 'c.chip_no')
                            ->join('chips as c', 'c.id','=', 'chip_registrations.chip_id')
                            ->leftJoin(DB::raw("(
                                        select variant_options_transactions.optionable_id, ov.value 
                                        from variant_options_transactions
                                        join variant_options vo on vo.id = variant_options_transactions.variant_options_id
                                        join product_option_values pov on pov.id = vo.product_option_value_id
                                        join option_values ov on ov.id = pov.option_value_id
                                        where variant_options_transactions.optionable_type = 'chip_registration'
                                    ) as size"), 'size.optionable_id', '=', 'chip_registrations.id')
                            ->where('c.chip_no',$request->chip_no)
                            ->where('chip_registrations.product_variation_id',$request->product_variant_id)
                            ->where('size.value',$request->size)
                            ->where('chip_registrations.customer_id',$request->customer_id)
                            ->where('chip_registrations.customer_employee_id',$request->employee_id)
                            ->first();

            if($chip_registration){
                $banned_reason = DB::table('banned_reasons')->where('name', 'MANUALLY')->pluck('id');
                BannedChip::firstOrCreate(['chip_registration_id'=>$chip_registration->id, 'banned_reason_id' => $banned_reason]);
               //deprogram the chip
                $chip_registration->delete();
                $this->forgetChip($request->chip_no, ChipScannerType::TXT_REGISTRATION);


                return response()->json(['result'=>true, 'message'=>'Chip has been successfully deprogrammed.']);
            }
        // dd($chip_registration);
    }

    public function UndoUnRegisterChipManualy(Request $request)
    {
        $response = array('result'=>true,'message'=> '');
        $restore = ChipRegistration::withTrashed()->where('id',$request->chip_registration_id)->restore();

        $chip_registration = ChipRegistration::select('chip_registrations.*','size.value as size', 'c.chip_no')
        ->join('chips as c', 'c.id','=', 'chip_registrations.chip_id')
        ->leftJoin(DB::raw("(
                    select variant_options_transactions.optionable_id, ov.value 
                    from variant_options_transactions
                    join variant_options vo on vo.id = variant_options_transactions.variant_options_id
                    join product_option_values pov on pov.id = vo.product_option_value_id
                    join option_values ov on ov.id = pov.option_value_id
                    where variant_options_transactions.optionable_type = 'chip_registration'
                ) as size"), 'size.optionable_id', '=', 'chip_registrations.id')
          ->where('chip_registrations.id',$request->chip_registration_id)
          ->first();
             $check_banned = BannedChip::where('chip_registration_id', $chip_registration->id)->first();
            //removes the chip id from the ’banned list’. 
            if($check_banned){
                BannedChip::find($check_banned->id)->delete();
            }
            $this->forgetChip($chip_registration->chip_no, ChipScannerType::TXT_REGISTRATION);
        // }
        $response['message'] = "Chip registration is active again.";
        return $response;
    }

    public function changeRegistrationManually(Request $request)
    {
         $response = array('result'=>true,'message'=> '');
        // dd($request->all());
        if($request->registrations){
            $registrations = $request->registrations;
            // dd($registrations);
            $generated_registration_count = 0;
            foreach($registrations as $k => $registration){
                switch ($request->action) {
                    case 'unregister':
                        // $chip_registration = ChipRegistration::find($registration['id']);
                         $chip_registration = ChipRegistration::select('chip_registrations.*','size.value as size', 'c.chip_no')
                            ->join('chips as c', 'c.id','=', 'chip_registrations.chip_id')
                            ->leftJoin(DB::raw("(
                                        select variant_options_transactions.optionable_id, ov.value 
                                        from variant_options_transactions
                                        join variant_options vo on vo.id = variant_options_transactions.variant_options_id
                                        join product_option_values pov on pov.id = vo.product_option_value_id
                                        join option_values ov on ov.id = pov.option_value_id
                                        where variant_options_transactions.optionable_type = 'chip_registration'
                                    ) as size"), 'size.optionable_id', '=', 'chip_registrations.id')
                              ->where('chip_registrations.id',$registration['id'])
                              ->first();
                        // dd($chip_registration);
                        if($chip_registration){
                            // Unregister chips
                            $banned_reason = DB::table('banned_reasons')->where('name', 'MANUALLY')->pluck('id');
                            BannedChip::firstOrCreate(['chip_registration_id'=>$chip_registration->id, 'banned_reason_id' => $banned_reason]);
                           //deprogram the chip
                            $chip_registration->delete();
                            $this->forgetChip($chip_registration->chip_no, ChipScannerType::TXT_REGISTRATION);
                        }
                        $response['message'] = "Chip has been successfully deprogrammed.";
                        break;

                    case 'undo':
                        
                        $restore = ChipRegistration::withTrashed()->where('id',$registration['id'])->restore();

                        $chip_registration = ChipRegistration::select('chip_registrations.*','size.value as size', 'c.chip_no')
                        ->join('chips as c', 'c.id','=', 'chip_registrations.chip_id')
                        ->leftJoin(DB::raw("(
                                    select variant_options_transactions.optionable_id, ov.value 
                                    from variant_options_transactions
                                    join variant_options vo on vo.id = variant_options_transactions.variant_options_id
                                    join product_option_values pov on pov.id = vo.product_option_value_id
                                    join option_values ov on ov.id = pov.option_value_id
                                    where variant_options_transactions.optionable_type = 'chip_registration'
                                ) as size"), 'size.optionable_id', '=', 'chip_registrations.id')
                          ->where('chip_registrations.id',$registration['id'])
                          ->first();
                        $check_banned = BannedChip::where('chip_registration_id', $chip_registration->id)->first();
                        //removes the chip id from the ’banned list’. 
                        if($check_banned){
                            BannedChip::find($check_banned->id)->delete();
                        }
                        $this->forgetChip($chip_registration->chip_no, ChipScannerType::TXT_REGISTRATION);
                    
                        $response['message'] = "Chip registration is active again.";
                        break;
                   
                    case 'missing':
                        $chip_registration = ChipRegistration::select('chip_registrations.*','size.value as size', 'c.chip_no')
                        ->join('chips as c', 'c.id','=', 'chip_registrations.chip_id')
                        ->leftJoin(DB::raw("(
                                    select variant_options_transactions.optionable_id, ov.value 
                                    from variant_options_transactions
                                    join variant_options vo on vo.id = variant_options_transactions.variant_options_id
                                    join product_option_values pov on pov.id = vo.product_option_value_id
                                    join option_values ov on ov.id = pov.option_value_id
                                    where variant_options_transactions.optionable_type = 'chip_registration'
                                ) as size"), 'size.optionable_id', '=', 'chip_registrations.id')
                          ->where('chip_registrations.id',$registration['id'])
                          ->first();
                          // dd($chip_registration);
                        $set_chip_missing = ChipMissing::firstOrCreate([
                                'chip_id' => $chip_registration->chip_id,
                                'chip_no' => $chip_registration->chip_no,
                                'chip_registration_id' => $chip_registration->id,
                                'cr_transaction_id'=>$chip_registration->latest_cr_transaction_id
                                ]);

                        $pulje_customers = config('customer.pulje');
                        if ($chip_registration->latest_cr_transaction_id || in_array($chip_registration, $pulje_customers)) {
                            //if the product is pooled, just remove the transaction id
                            $chip_registration->update(['latest_cr_transaction_id'=>null]);
                        }else {
                            //remove the registration for the personal products(not pulje)
                            $chip_registration->delete();
                        }
                        
                        $this->forgetChip($request->chip_no, ChipScannerType::TXT_REGISTRATION);
                        $response['message'] = "Chip setted to missing.";
                        break;

                    case 'flag_missing': 
                        $missing_action = $request->todo; 
                        $result = $this->flagChipAsMissing($registration['id'], $missing_action);
                        if (!$result) return array('result'=>false,'message'=> 'There was an error in your transaction.');
                        
                        $response['message'] = "Chip(s) markeres nu som mangler.";
                        break;
                    default:
                }
            }
            
            
        }

        return $response;
    }

    private function flagChipAsMissing($chip_registration_id, $todo)
    {
        //todo affects only those non-pulje chips 

        DB::beginTransaction(); 
        $registration = ChipRegistration::find($chip_registration_id);
        // check if its a pooled item 
        $pulje_customers = config('customer.pulje');
        $scanned_by = (Auth::user()) ? Auth::user()->id : NULL;  
        $size = $registration->getSize();
        $reg_size = ($size) ? $size->size : null; 
        $chip_no = $registration->chip->chip_no; 

        if ($registration->latest_cr_transaction_id || in_array($registration->customer_id, $pulje_customers)) {
            $cr_transaction = $registration->latestChipRegistrationTransaction;
            if ($todo == 'reduce') {
                $subscription = $cr_transaction->customer->subscriptions()
                        ->select(DB::raw('id, take_from_subscription_id,IF(take_from_subscription_id IS NOT NULL and take_from_subscription_id > 0, 1, 0) as is_pooled'))->where('product_variant_id', $registration->product_variation_id)->first(); 
                $detail = [
                    'customer_id'   => $cr_transaction->customer_id,
                    'employee_id'   => $cr_transaction->employee_id,
                    'order_date'    => Carbon::now(),
                    'product_id'    => $registration->product_id,
                    'product_variant_id'=> $registration->product_variation_id,
                    'quantity'      => 1,
                    'approved'      => 1,
                    'processed_at' => Carbon::now(),
                    'status' => 'Pending',
                    'user_id' => Auth::user()->id,
                    'is_inventory_order' => 1,
                    'is_pooled'     => 1,
                    'subscription_id' => ($subscription) ? $subscription->id : null
                ];
                $variant_options = ($registration->variant_option_transaction) ? [$registration->variant_option_transaction->variant_options_id] : [];
                $building = ($cr_transaction->customer->building_id) ? $cr_transaction->customer->building_id : 2;  
                $return_order = $cr_transaction->customer->orders()->select('orders.*')->pending()->action('ADD')
                                ->leftJoin(DB::raw("(SELECT optionable_id, optionable_type, variant_options_id, ov.value AS size, vo.product_option_id, vot.id, ov.value, vo.product_option_value_id
                                        FROM variant_options_transactions vot
                                        JOIN variant_options AS vo ON vo.id = vot.variant_options_id
                                        JOIN product_option_values AS pov ON pov.id = vo.product_option_value_id
                                        JOIN option_values AS ov ON ov.id = pov.option_value_id
                                        WHERE optionable_type = 'inventory_order_transaction') AS vot"), 'vot.optionable_id','=','orders.id')
                                ->where('employee_id', $cr_transaction->employee_id)
                                ->where('product_variant_id', $registration->product_variation_id)
                                ->where('vot.size',$reg_size)->where('is_pooled',1)->first();
                if (!$return_order) {                    
                    $return_order = $cr_transaction->customer->generateOrder($detail, $variant_options, 'ADD');
                }                
                if ($return_order) {
                    $existing_comment = $return_order->comments;
                    $return_order->update(['comments'=>$existing_comment.'<br> Missing chip/Mangler chip: '.$chip_no]);               
                    $storage_id = ($subscription) ? $subscription->takeFromSubscription->customer->storage->id : null;      
                    $return_order->process(1, $storage_id, $building);
                }  
            }
            //for refill and just setting to missing 
            //add sorting to reduce the amount they have without changing the inventory                
            // if the chip already was sorted today, skip it
            $isAlreadyScanned = $this->isAlreadyScanned($chip_no,ChipScannerType::TXT_SORTING); 
            if (!$isAlreadyScanned) {                
            
                $transaction = ChipTransaction::create(['scanner_type'=>ChipScannerType::SORTING, 'scanned_by' => $scanned_by, 'total_amount'=>1]);
                $transaction_log = ChipTransactionLog::create(["chip_registration_id" => $registration->id,"chip_transaction_id" => $transaction->id, 'cr_transaction_id'=>$registration->latest_cr_transaction_id]);                
                //check if this chip was lended to some temporary employee
                // if yes, end the record in the temporary table
                $tempEmployeeTransaction = TemporaryEmployeeTransaction::where('chip_registration_id',$registration->id)
                                            ->where('chip_registration_transaction_id',$registration->latest_cr_transaction_id)
                                            ->whereNull('ended_at')->first();
                if ($tempEmployeeTransaction) $tempEmployeeTransaction->update(['ended_at'=>Carbon::now()]);

                $latest_packed_delivery = Delivery::select(DB::raw("deliveries.*"))
                                        ->where('deliveries.is_termination_order',0)
                                        ->where('deliveries.customer_id',$cr_transaction->customer_id )
                                        ->where('deliveries.product_variant_id', $registration->product_variation_id)
                                        ->whereRaw('deliveries.delivery_date <= now()')
                                        ->where('is_extra_order',0)->whereNotIn('deliveries.type',['pickup'])
                                        ->orderBy('deliveries.delivery_date','desc')->orderBy('deliveries.is_extra_order')->first();

                if($latest_packed_delivery){
                    ChipDeliveryTransaction::firstOrCreate(['chip_transaction_id'=> $transaction->id, 'delivery_id'=>$latest_packed_delivery->id]);                    
                }
            }
              

            //add to missing table
            $chip_missing = ChipMissing::firstOrCreate([ 'chip_id' => $registration->chip_id, 'chip_no' => $chip_no, 'chip_registration_id' => $registration->id,'cr_transaction_id'=>$registration->latest_cr_transaction_id ]);
            $registration->update(['latest_cr_transaction_id'=>null]); //transferred the value in the chip missing table
        } // eof pooled checking
        else {
            //regular items (non pulje)
            //actions 
            //create a return order and process it
            if (in_array($todo, ['refill','reduce'])) {
                $detail = [
                    'customer_id'   => $registration->customer_id,
                    'employee_id'   => $registration->customer_employee_id,
                    'order_date'    => Carbon::now(),
                    'product_id'    => $registration->product_id,
                    'product_variant_id'=> $registration->product_variation_id,
                    'quantity'      => 1,
                    'approved'      => 1,
                    'processed_at' => Carbon::now(),
                    'status' => 'Pending',
                    'user_id' => Auth::user()->id,
                    'is_inventory_order' => 1
                ];
                $variant_options = ($registration->variant_option_transaction) ? [$registration->variant_option_transaction->variant_options_id] : [];
                $building = ($registration->customer->building_id) ? $registration->customer->building_id : 2;

                $return_order = $registration->customer->orders()->select('orders.*')->pending()->action('ADD')
                                ->leftJoin(DB::raw("(SELECT optionable_id, optionable_type, variant_options_id, ov.value AS size, vo.product_option_id, vot.id, ov.value, vo.product_option_value_id
                                        FROM variant_options_transactions vot
                                        JOIN variant_options AS vo ON vo.id = vot.variant_options_id
                                        JOIN product_option_values AS pov ON pov.id = vo.product_option_value_id
                                        JOIN option_values AS ov ON ov.id = pov.option_value_id
                                        WHERE optionable_type = 'inventory_order_transaction') AS vot"), 'vot.optionable_id','=','orders.id')
                                ->where('employee_id', $registration->customer_employee_id)
                                ->where('product_variant_id', $registration->product_variation_id)
                                ->where('vot.size',$reg_size)->first();

                if (!$return_order) {                    
                    $return_order = $registration->customer->generateOrder($detail, $variant_options, 'ADD');
                }

                if ($return_order) {
                    $existing_comment = $return_order->comments;
                    $return_order->update(['comments'=>$existing_comment.'<br> Missing chip/Mangler chip: '.$chip_no]);                    
                    $storage = Storage::select('storages.*')
                                ->join('internal_storages as is', 'is.id', '=', 'storages.owner_id')
                                ->where('is.name', 'Brugtlager')
                                ->where('storages.owner_type', 'InternalStorage')->first();                           
                          
                    $return_order->process(1, $storage->id, $building);
                    $return_order->linkChipRegistration($registration->id);
                }  

                if ($todo == 'refill') {
                    //replace with a new one
                    $new_order = $registration->customer->generateOrder($detail, $variant_options, 'TAKE');
                    if ($new_order) {
                        $new_order->update(['comments'=>'Missing chip replacement.']);  
                    }
                }
            }

            //add to missing table
            $chip_missing = ChipMissing::firstOrCreate([ 'chip_id' => $registration->chip_id, 'chip_no' => $chip_no, 'chip_registration_id' => $registration->id ]);
            $registration->delete(); 
        }       

        //removed cached chip information
        $this->forgetChip($chip_no);

        DB::commit(); 
        return true; 
    }

    public function show() {
        // this function only exists so laravel will stop complaining
        // feel free to change this method if it is ever needed
        abort(404);
    }

    public function checkChipNos()
    {
        set_time_limit(0);
        /**
        * avask = system.a-vask.dk
        * aflex = system.a-vask.com
        */
        DB::beginTransaction(); 
        $avask_db = (app()->environment() == 'production') ? 'mysql3' : 'old';
        $avask_chips = DB::connection($avask_db)->table('chips')->select('chips.chip_no', 'chips.chip_data_id','cd.*')
                    ->leftJoin('chip_data as cd','cd.id','=','chips.chip_data_id')//->where('chip_no','300ED89F3350004000A73B9B')
                    ->whereNull('chips.deleted_at')
                    ->get();

        $avask_chips = collect($avask_chips)->chunk(5000);    
        
        $with_probs = [];$a=0;$b=0;$c=0;$d=0;$e=0;$total=0;
        foreach ($avask_chips as $chunk_chip) {
            $ochips = $chunk_chip->pluck('chip_no')->toArray(); 
            
            //get all the avask chips that exists in aflex
            $aflex_chips = Chip::select('chip_no','chips.created_at as chip_create','cr.*')
                        ->join('chip_registrations as cr','cr.chip_id','=','chips.id')
                        ->whereNull('cr.deleted_at')->whereIn('chip_no', $ochips)
                        ->get(); 
            $total += count($aflex_chips);
            $afw_probs= []; 
            foreach ($aflex_chips as $afchip) {
                $avchip = $chunk_chip->where('chip_no', $afchip->chip_no)->first();
                
                if ($avchip && !$avchip->chip_data_id && $afchip->id) {
                    //the chip exist both in avask and aflex, does not have an active in chip_data avask but has an active registration in aflex                    
                    // delete the chip from the avask 
                    $this->deleteAvaskChip($avchip->chip_no);
                    $a++;
                }
                else if ($avchip && $this->isEmptyAvChipData($avchip)){
                    //the chip has active chip_data but the chip_data info is empty, no customer_id,permanent_customer_id,product_id, product_size_id, storage_id
                    // delete the chip from avask 
                    $this->deleteAvaskChip($avchip->chip_no);
                    $b++;
                }
                else if ($avchip && !$avchip->customer_id && !$avchip->permanent_customer_id){
                    //the chip has active chip_data but the chip_data info has no customer_id and permanent_customer_id, meaning it is not assigned to any customer but is assigned to a product
                    //check if the previous chip_data are already imported to aflex
                    $prev_cd = DB::connection($avask_db)->table('chips')->select('chips.chip_no', 'cd.*')
                                    ->join('chip_data as cd','cd.chip_id','=','chips.id')
                                    ->where('chips.chip_no', $avchip->chip_no)
                                    ->whereNull('chips.deleted_at')->where('cd.id','!=',$avchip->chip_data_id)
                                    ->where(function($query){ 
                                        $query->whereNotNull('customer_id')->orWhereNotNull('permanent_customer_id');
                                    })
                                    ->orderBy('id','desc')->first();
                    
                    $imported = true;
                    if ($prev_cd && (($prev_cd->customer_id && !$this->customerImported($prev_cd->customer_id)) || ($prev_cd->permanent_customer_id && !$this->customerImported($prev_cd->permanent_customer_id)))) $imported = false; 

                    if ($imported) {
                        //if it is indeed already imported copy the transactions, copy the packing/sorting transactions that were made after the afchip creation
                        $this->copyAvaskChipTransactionsToAflex($avchip->chip_no, $afchip->chip_create);
                        //then delete the avchip 
                        $this->deleteAvaskChip($avchip->chip_no);                        
                        $c++;
                    }else {
                        // if the previous owners are not in aflex ?????
                        $afw_probs[] = $afchip->chip_no; 
                    }
                }
                //check if the current chip_data customer id is already imported to aflex
                //check if the current chip_data permanent_customer_id (if there is no customer_id) is already imported to aflex
                else if ($avchip && (($avchip->customer_id && $this->customerImported($avchip->customer_id)) || ($avchip->permanent_customer_id && $this->customerImported($avchip->permanent_customer_id)))){
                    //if it is indeed already imported copy the transactions, copy the packing/sorting transactions that were made after the afchip creation
                    $this->copyAvaskChipTransactionsToAflex($avchip->chip_no, $afchip->chip_create);
                    //then delete the avchip 
                    $this->deleteAvaskChip($avchip->chip_no); 
                    $d++;
                } 
                else if ($this->hasChipTransactions($afchip->chip_no, 'aflex') && !$this->hasChipTransactions($avchip->chip_no, 'avask')){
                    //if the chip is both active in aflex and avask, but there are no transactions(sorting,packing) in avask but has transactions in aflex
                    //the chip_data is from a customer not imported in aflex
                    //remove avask chip
                    // $this->deleteAvaskChip($avchip->chip_no);        
                    // dd($afchip->chip_no);
                    $afw_probs[] = $afchip->chip_no; 
                    $e++;
                }
                else {
                    //chip is active both in avask and aflex with transactions and the avask customer is not yet imported to aflex ?????
                    $afw_probs[] = $afchip->chip_no;                    
                }
            } // eof foreach aflex_chips

            $with_probs = array_merge($with_probs, $afw_probs); 
            // $with_probs = array_merge($with_probs, $aflex_chips->pluck('chip_no')->toArray()); 
        } //eof foreach chun chips avask

        echo $total.' - total chips that are in avask & aflex <br>';
        echo '<h4>breakdown:</h4>';
        echo $a.' - does not have an active chip_data in avask but has an active registration in aflex <br>';
        echo $b.' - active chip_data but info is empty, no customer_id,permanent_customer_id,product_id, product_size_id, storage_id <br>';
        echo $c.' - active chip_data but info has no customer_id and permanent_customer_id, meaning it is not assigned to any customer but is assigned to a product <br>';
        echo $d.' - current chip_data customer_id/permanent_customer_id was already imported to aflex <br>';
        echo $e.' - no transactions(sorting,packing) in avask but has transactions in aflex <br>';
        echo '<br>chips with problems (both in avask and aflex): <br>';
        dd($with_probs);
        DB::commit(); 
    }

    private function copyAvaskChipTransactionsToAflex($chip_no, $start_date)
    {
        //get all packing transactions 
        $packing = DB::connection('mysql5')->table('chip_tmp')
                    ->where('chip_id', $chip_no)->where('created_at', '>', $start_date)
                    ->orderBy('created_at')->get();
        $this->importTransactions($packing, ChipScannerType::TXT_PACKING); 

        //get all sorting transactions 
        $sorting = DB::connection('mysql5')->table('chip_sorting_tmp')
                    ->where('chip_id', $chip_no)->where('created_at', '>', $start_date)
                    ->orderBy('created_at')->get();
        $this->importTransactions($sorting, ChipScannerType::TXT_SORTING); 
        return true; 
    }

    private function deleteAvaskChip($chip_no)
    {
        $avask_db = (app()->environment() == 'production') ? 'mysql3' : 'old';
        return DB::connection($avask_db)->table('chips')
            ->where('chip_no', $chip_no)
            ->update(['deleted_at'=>date('Y-m-d H:i:s'), 'chip_data_id'=>NULL]);
        return true; 
    }

    private function isEmptyAvChipData($chip)
    {
        if (!$chip->product_id && !$chip->product_size_id && !$chip->customer_id && !$chip->permanent_customer_id && !$chip->storage_id && !$chip->employee_id)
            return true; 
        else 
            return false; 
    }

    private function customerImported($cid)
    {
        $customer= Customer::find($cid);
        if ($customer) return true; 
        else return false; 
    }

    private function hasChipTransactions($chip_no, $where)
    {
        $transactions = null; 
        if ($where == 'avask') {
            $transactions = DB::connection('mysql5')->table('chip_tmp as ct')
                        ->leftJoin('chip_sorting_tmp as cst','cst.chip_id','=','ct.chip_id')
                        ->where('ct.chip_id', $chip_no)->get();
        }
        else if($where == 'aflex') {
            $transactions = ChipRegistration::join('chips as c','c.id','=','chip_registrations.chip_id')
                            ->join('chip_transaction_logs as ctl','ctl.chip_registration_id','=','chip_registrations.id')
                            ->where('c.chip_no', $chip_no)
                            ->get(); 
        }
        if ($transactions && count($transactions) > 0) return true; 
        return false;         
    }
}