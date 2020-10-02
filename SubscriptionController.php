<?php

namespace Avask\Http\Controllers\Subscription;

use Avask\Delivery;
use Avask\SalesLog;
use Illuminate\Http\Request;

use Avask\Http\Requests;
use Avask\Http\Controllers\Controller;
use Avask\Models\Subscriptions\Subscription;
use Avask\Models\Subscriptions\SubscriptionSize;
use Avask\Models\Subscriptions\SubscriptionSchedule;
use Avask\Models\Subscriptions\SubscriptionTransaction;
use Avask\Models\Products\Product;
use Avask\Models\Products\ProductVariant;
use Avask\Models\Products\ProductPricing;
use Avask\Models\Utilities\VariantOptionTransaction;
use Avask\Models\Products\ProductOptionValue;
use Avask\Models\Products\ProductAttribute;
use Avask\Models\Deliveries\RouteSchedule;
use Avask\Models\Schedule;
use Avask\User;
use Avask\CustomerMessage;
use Avask\Repositories\Product\PricingRepository;
use Avask\Repositories\Deliveries\DeliveryRepository;
use RRule\RRule;
use Avask\Models\Utilities\ImportLog;
use Avask\Models\Fees\Fee;
use Avask\Traits\OrderTrait;
use Avask\Models\Inventory\InventoryTransaction;
use Avask\Models\Deliveries\Delivery as Deliveri;
use Avask\Repositories\Deliveries\GenerateDeliveriesRepository;

/*USED*/
use Avask\Models\Customers\Customer;
use Avask\Models\Customers\CustomerGroup;

use DB;
use Datatables;
use View;
use Response;
use Input;
use Validator;
use Redirect;
use Carbon\Carbon;
use Session;
use Auth;
use Config;
use Search;


class SubscriptionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */

    public function index()
    {
        $search = $this->subscriptionSearchForm();
        return view('subscriptions.index', compact('search'));
    }

    private function subscriptionSearchForm() {
        $statusOptions= [
            ['value' => 'ACTIVE', 'label' => 'Aktive'],
            ['value' => 'INACTIVE', 'label' => 'Ikke aktive'],
            ['value' => '', 'label' => 'Alle', 'checked']
        ];

        $pooled_option = [
            ['value' => 4471, 'label' => 'Puljetøj'],
            ['value' => 7235, 'label' => 'A-vask Vest'],
            ['value' => '', 'label' => 'Alle', 'checked']
        ];

        $customer_id = Input::get('customer_id'); 
        
        return Search::open('salescustomer-table')
        ->addInteger('Kundenr', 'c.id',['value'=>$customer_id])
        ->addString('Produkt', 'product', ['where' => ['p.name', 'pv.name', 'pv.product_nr']])
        ->addInteger('Abonnementnr.', 'subscriptions.id')
        ->addRadioGroup('Pulje', 'pooled_subscription.customer_id', $pooled_option)
        ->addRadioGroup('Status', 'subscriptions.status', $statusOptions);
    }

    public function datatable() {
      /*  $search = $this->subscriptionSearchForm();
        $pooled_info = $search->get('pooled_info');

        $pooled_info = $pooled_info['value'];*/
        // dd($pooled_info);
        $subscriptions = Subscription::join('products as p', 'subscriptions.product_id', '=', 'p.id')
                                    ->join('product_variants as pv', 'subscriptions.product_variant_id', '=', 'pv.id')
                                    ->join('customers as c', 'subscriptions.customer_id', '=', 'c.id')
                                    ->leftJoin('subscriptions as pooled_subscription',function($join){
                                        $join->on('pooled_subscription.id', '=', 'subscriptions.take_from_subscription_id')->whereNotNull('subscriptions.take_from_subscription_id');
                                        
                                    })
                                    ->select('subscriptions.*', 'c.dist_name as customer_name', 'p.name as product_name', 'pv.product_nr', 'pv.name as variant_name', 'c.id as customer_id', 'pooled_subscription.customer_id as pooled_from',
                                        'c.dist_street', 'c.dist_zipcode', 'c.dist_city'
                                        );
        
        $this->subscriptionSearchForm()->addWheres($subscriptions);

        return Datatables::of($subscriptions)
            ->addColumn('test', function($subscriptions){
                if ($subscriptions->usage_both) return '';
                return '<input class="chk-selected" type="checkbox" name="sales_id[]" value="'.$subscriptions->id.'">';
            })
            ->addColumn('customer_details', function($subscriptions){
                return "$subscriptions->customer->dist_street <br>$subscriptions->customer->dist_zipcode $subscriptions->customer->dist_city";
            })
            ->editColumn('start_date', function($subscriptions){
                return $subscriptions->start_date;
            })
            ->editColumn('end_date', function($subscriptions){
                return $subscriptions->end_date;
            })
            ->addColumn('latest_delivery_date', function($subscriptions){
                // return $subscriptions->latestDeliveryDate();
            })
            ->addColumn('initial_delivery_amount', function($subscriptions){
                return $subscriptions->amountSubscribed();
            })
            // ->addColumn('secondary_delivery_amount', function($subscriptions){
            //     if(!$subscriptions->employeeBased() || !$subscriptions->pooled())
            //         return $subscriptions->getSecondaryAmount();
            //     else
            //         return '';
            // })
            ->addColumn('status', function($subscriptions){
                // if ($subscriptions->terminated())
                    // return '<span class="label label-danger">TERMINATED</span>';
                // else if (!$subscriptions->started())
                    // return '<span class="label label-warning">NOT STARTED</span>';
                // else if ($subscriptions->ended())
                    // return '<span class="label label-danger">ENDED</span>';
                // else if ($subscriptions->active())
                    // return '<span class="label label-success">ACTIVE</span>';
                // else
                if($subscriptions->status == 'ACTIVE')
                    return '<span class="label label-success">ACTIVE</span>';
                else
                    return '<span class="label label-warning">'.$subscriptions->status.'</span>';
            })
            ->addColumn('from_pooled', function($subscriptions){
                return ($subscriptions->fromPooled()) ? '<i class="fa fa-check"></i>' : '';
            })
            ->editColumn('visible_on_timetable', function($subscriptions){
                return $subscriptions->visible_on_timetable ? '<i class="fa fa-check"></i>' : '';
            })
            ->editColumn('visible_on_foldinglist', function($subscriptions){
                return $subscriptions->visible_on_foldinglist ? '<i class="fa fa-check"></i>' : '';
            })
            ->editColumn('visible_on_finalizelist', function($subscriptions){
                return $subscriptions->visible_on_finalizelist ? '<i class="fa fa-check"></i>' : '';
            })
            ->editColumn('visible_on_invoice', function($subscriptions){
                return $subscriptions->visible_on_invoice ? '<i class="fa fa-check"></i>' : '';
            })
            ->editColumn('visible_on_delivery_note', function($subscriptions){
                return $subscriptions->visible_on_delivery_note ? '<i class="fa fa-check"></i>' : '';
            })
            ->editColumn('employee_based', function($subscriptions){
                return $subscriptions->employee_based ? '<i class="fa fa-check"></i>' : '';
            })
            ->addColumn('customer_details', function($subscriptions){
                return "<h4><a href='".route('customers.show', $subscriptions->customer->id)."'>$subscriptions->customer->dist_name</a></h4>$subscriptions->customer->dist_street <br>$subscriptions->customer->dist_zipcode $subscriptions->customer->dist_city";
            })
            ->addColumn('action', function($subscriptions){
                if ($subscriptions->usage_both) return '';

                $action = '<div class="btn-group"><a class="btn btn-mini dropdown-toggle" data-toggle="dropdown" href="#"><i class="fa fa-cog"></i><span class="caret"></span></a><ul class="dropdown-menu dropdown-menu-right">';
                $action .='<li><a href="'.route('subscriptions.edit',array('id'=>$subscriptions->id)).'">Rediger</a></li>';
                if(!$subscriptions->employeeBased())
                    $action .='<li><a href="'.route('subscriptions.newtransaction',array('id'=>$subscriptions->id)).'">Tilføj/træk mængde..</a></li>';
                $action .='<li><a href="#" class="delete-sale" data-sale-id="'.$subscriptions->id.'">Slet</a><li></ul></div>';
                return $action;
            })
            ->addColumn('pooled_for', function($subscriptions){
                $pooled_for = '';
                if($subscriptions->is_pooled) {
                    $pooled_for = '<i class="fa fa-check"></i>';
                }
                return $pooled_for;
            })
            ->editColumn('pooled_from', function($subscriptions){
                $pooled_from = Customer::find($subscriptions->pooled_from);
                if($pooled_from) {
                    
                    return $pooled_from->name;
                }
            })
            ->make(true);

    }


    public function subscriptionOverview(Request $request) 
    {
        $customer_list = Customer::select(DB::raw("CONCAT(id,' -  ', dist_name) AS name, id"))->lists('name', 'id');
        if($request->customer_id){

            $customer = Customer::find($request->customer_id);
            $pooled_subscriptions = [];
            if($customer) {
                
                $subscriptions = $customer->subscriptions()
                    ->join('product_variants as pv', 'pv.id', '=', 'subscriptions.product_variant_id')
                    ->select('subscriptions.*', 'pv.name as product_name', 'pv.product_nr')
                    ->get();
                $customer = $customer;
            }

            foreach ($subscriptions as $subscription) {
              $pooled_subscriptions[$subscription->id]  = Subscription::join('customers','customers.id','=','subscriptions.customer_id')
                ->where('product_id', $subscription->product_id)
                ->where('product_variant_id', $subscription->product_variant_id)
                ->where('is_pooled', 1)
                ->where('customer_id', '!=' , $subscription->customer_id)
                ->groupBy('subscriptions.customer_id')
                ->lists('customers.dist_name','subscriptions.id')->toArray();

            }
        }
        return view('subscriptions.flags', compact('subscriptions', 'customer', 'pooled_subscriptions', 'customer_list'));
    }
    public function subscriptionFlag($customer_id) 
    {
        $customer_list = Customer::select(DB::raw("CONCAT(id,' -  ', dist_name) AS name, id"))->lists('name', 'id');
        $customer = Customer::find($customer_id);
        $pooled_subscriptions = [];
        if($customer) {
            
            $subscriptions = $customer->subscriptions()
                ->join('product_variants as pv', 'pv.id', '=', 'subscriptions.product_variant_id')
                ->select('subscriptions.*', 'pv.name as product_name', 'pv.product_nr')
                ->get();
            $customer = $customer;
        }

        foreach ($subscriptions as $subscription) {
          $pooled_subscriptions[$subscription->id]  = Subscription::join('customers','customers.id','=','subscriptions.customer_id')
            ->where('product_id', $subscription->product_id)
            ->where('product_variant_id', $subscription->product_variant_id)
            ->where('is_pooled', 1)
            ->where('customer_id', '!=' , $subscription->customer_id)
            ->groupBy('subscriptions.customer_id')
            ->lists('customers.dist_name','subscriptions.id')->toArray();

        }
        // dd($pooled_subscriptions);
         
        return view('subscriptions.flags', compact('subscriptions', 'customer', 'pooled_subscriptions','customer_list'));
    }

    public function subscriptionDelivery(Request $request)
    {
        $customer_list = Customer::select(DB::raw("CONCAT(id,' -  ', dist_name) AS name, id"))->lists('name', 'id');
        if($request->customer_id){

            $customer = Customer::find($request->customer_id);
            $pooled_subscriptions = [];
            if($customer) {
                
                $subscriptions = $customer->subscriptions()
                    ->join('product_variants as pv', 'pv.id', '=', 'subscriptions.product_variant_id')
                    ->select('subscriptions.*', 'pv.name as product_name', 'pv.product_nr')
                    ->get();
                $customer = $customer;
            }

            foreach ($subscriptions as $subscription) {
              $pooled_subscriptions[$subscription->id]  = Subscription::join('customers','customers.id','=','subscriptions.customer_id')
                ->where('product_id', $subscription->product_id)
                ->where('product_variant_id', $subscription->product_variant_id)
                ->where('is_pooled', 1)
                ->where('customer_id', '!=' , $subscription->customer_id)
                ->groupBy('subscriptions.customer_id')
                ->lists('customers.dist_name','subscriptions.id')->toArray();

            }
        }
        return view('subscriptions.flags', compact('subscriptions', 'customer', 'pooled_subscriptions', 'customer_list'));

    }

    

    public function viewCustomerSales()
    {
        return View::make('subscriptions.sales')->with(array('customer_id'=>Input::get('id')));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        return view('subscriptions.create');
    }

    public function createNew()
    {
        $product_variants = ProductVariant::all();
        $customers        = Customer::get(['id', 'name']);
        $customer_group   = CustomerGroup::get(['id', 'name']);
        // return view('subscriptions.create_new', compact('product_variants', 'customers'));
        return view('subscriptions.version2.index', compact('product_variants', 'customers', 'customer_group'));
    }    

    // jg
    public function getProductList()
    {
        $product_variants = ProductVariant::all();

        $products = [];
        foreach ($product_variants as $key => $value) {

            $pv = ProductVariant::leftJoin('products as p','p.id','=','product_variants.product_id')
                  ->leftJoin('product_options as po', 'po.product_id', '=', 'product_variants.product_id')
                  ->leftJoin('product_attributes as pa', function($query) {
                      $query->on('pa.product_variant_id', '=', 'product_variants.id')->on('pa.product_option_id', '=', 'po.id');
                  })
                  ->leftJoin('product_option_values as pov', 'pov.id', '=', 'pa.product_option_value_id')
                  ->leftJoin('option_values as ov', 'ov.id', '=', 'pov.option_value_id')
                  ->leftJoin('options as o', 'o.id', '=', 'po.option_id')
                  ->where('product_variants.id',$value->id)
                  ->where('po.is_used_for_variation',0)
                  ->select('product_variants.*', 
                            DB::raw('CASE when ov.sort_order is null then GROUP_CONCAT(ov.value) else GROUP_CONCAT(CONCAT (pov.id,"-", ov.value)) END option'))
                  ->first();

            $products[$value->id]['product_id']        = $value->product_id;
            $products[$value->id]['product_nr']        = $value->product_nr;
            $products[$value->id]['product_name']      = $value->name;
            $products[$value->id]['product_variant_id']= $value->id;

            // Pricing
            if($value->getPrice()) {
                $products[$value->id]['pricing']['rent_price']        = $value->getPrice()->rent_price;
                $products[$value->id]['pricing']['base_price']        = $value->getPrice()->price;
                $products[$value->id]['pricing']['wash_price']        = $value->getPrice()->wash_price;
                $products[$value->id]['pricing']['replacement_price'] = $value->getPrice()->replacement_price;
                $products[$value->id]['pricing']['procurement_price'] = $value->getPrice()->procurement_price;
            } else {
                $products[$value->id]['pricing']['rent_price']        = '0,00';
                $products[$value->id]['pricing']['base_price']        = '0,00';
                $products[$value->id]['pricing']['wash_price']        = '0,00';
                $products[$value->id]['pricing']['replacement_price'] = '0,00';
                $products[$value->id]['pricing']['procurement_price'] = '0,00';
            }

            foreach (explode(',', $pv->option) as $k => $s) {
                if($s) {
                    $v = explode('-', $s);
                    $products[$value->id]['attributes'][$k]['size'] = (count($v)==2)? $v[1]: $v[0];
                    $products[$value->id]['attributes'][$k]['pov'] =  (count($v)==2)? $v[0]: 0;
                } else {
                    $products[$value->id]['attributes'][$k]['size'] = 'No Size';
                    $products[$value->id]['attributes'][$k]['pov'] =  '';
                }
                $products[$value->id]['attributes'][$k]['beholdning'] = '';
                $products[$value->id]['attributes'][$k]['buffer'] = '';
            }
        
        }

        return response()->json($products);
    } 
    
    /**
     * ½ a newly created resource in storage.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        // dd($request->all());
        $error = "";
        DB::beginTransaction();
        $sproducts = ($request->subscriptions['product_id']) ? $request->subscriptions['product_id'] : []; 
        if (count($sproducts) <= 0) $error = 'No product selected.';

        $grpID = isset($request->group_id)? (array)$request->group_id: [];
        $customers_from_group = array_flatten(Customer::whereIn('customer_group_id', $grpID)->get(['id'])->toArray());
     
        $custID = isset($request->data['customer_id'])? (array)$request->data['customer_id']: [];
        $customer_ids = array_merge((array)$request->child_ids, $custID, $customers_from_group);

        foreach ($customer_ids as $c_id) {
            foreach($sproducts as $key => $value){
                $variant_option_id = [];
                $variant_option = null;
                $product_attribute_id = null;
                $variant_id = ($request->subscriptions['product_variant_id'][$key]) ? $request->subscriptions['product_variant_id'][$key] : null;
                $product_option_value_id = ($request->subscriptions['product_option_value'][$key] && $request->subscriptions['product_option_value'][$key] != 'null') ? $request->subscriptions['product_option_value'][$key] : null;
                $employee_based = (isset($request->subscriptions['employee_based'][$key])) ? $request->subscriptions['employee_based'][$key] : 0;
                $chip_based = (isset($request->subscriptions['chip_based'][$key])) ? $request->subscriptions['chip_based'][$key] : 0;
                $is_pooled = (isset($request->subscriptions['is_pooled'][$key])) ? $request->subscriptions['is_pooled'][$key] : 0;
                $take_from_subscription_id = (isset($request->subscriptions['take_from_subscription_id'][$key])) ? $request->subscriptions['take_from_subscription_id'][$key] : 0;
                $perform_inventory_check = (isset($request->subscriptions['perform_inventory_check'][$key])) ? $request->subscriptions['perform_inventory_check'][$key] : 0;
               // dd($request->all(),$chip_based);

                $data = array_merge($request->data,
                    [
                        'product_id' => $value,
                        'product_variant_id' => $variant_id,
                        'created_by' => null,
                        'start_date' => $request->transaction['start_date'],
                        'end_date' => $request->transaction['end_date'],
                        'employee_based' => $employee_based,
                        'chip_based' => $chip_based,
                        'is_pooled' => $is_pooled,
                        'take_from_subscription_id' => $take_from_subscription_id,
                        'perform_inventory_check' => $perform_inventory_check,
                        'delivery_amount_type' => $request->subscriptions['delivery_amount_type'][$key],
                        'customer_id' => $c_id
                    ]
                );


                // Added by JG
                $data['visible_on_employee_list'] = ($data['show_on_regular_employee_list'] + $data['show_on_container_employee_list'])? "1": "0";

                $customer = Customer::find($c_id);
                $route_schedule_id = ($customer && $customer->route_schedule_id) ? $customer->route_schedule_id : null;
                $subscription = Subscription::updateOrCreate(['product_variant_id' => $variant_id, 'customer_id' => $c_id], $data);
                
                if($subscription){
                    
                    $schedule = Schedule::firstOrCreate($request->schedule);

                    if($variant_id){
                        $product_attribute = ProductAttribute::where('product_variant_id', $variant_id)->where('product_option_value_id', $request->subscriptions['product_option_value'][$key])->first();
                        if($product_attribute)
                            $product_attribute_id = $product_attribute->id;
                    }
                    
                    $pricingRepository = new PricingRepository;
                    $filters = [
                        'product_id' => $request->subscriptions['product_id'][$key],
                        'product_variant_id' => $variant_id,
                        'customer_id' => $c_id,
                        'schedule_id' => $schedule->id,
                        "product_attribute_id" => $product_attribute_id,
                    ];

                    $product_pricing = $pricingRepository->findPrice($filters,['product_id', 'product_variant_id', 'schedule_id', 'customer_id', 'product_attribute_id']);
                    
                    if($product_pricing) {
                        // no need to use the product pricing since the prices is an input field
                        // $price = $product_pricing->price;
                        // $wash_price = $product_pricing->wash_price;
                        // $replacement_price = $product_pricing->replacement_price;
                        // $procurement_price = $product_pricing->procurement_price;
                        $pricing_attribute = ($product_pricing->product_attribute_id) ? $product_attribute_id : null;
                    }else{
                        // $price = $request->subscriptions['price'][$key];
                        // $wash_price = $request->subscriptions['wash_price'][$key];
                        // $replacement_price = $request->subscriptions['replacement_price'][$key];
                        // $procurement_price = $request->subscriptions['procurement_price'][$key];
                        $pricing_attribute = null;
                    }
                    
                    $pricing = $pricingRepository->savePrice([
                            'product_id' => $request->subscriptions['product_id'][$key],
                            'product_variant_id' => $variant_id,
                            'customer_id' => $c_id,
                            'schedule_id' => $schedule->id,
                            'product_attribute_id' => $pricing_attribute,
                            'price' => $request->subscriptions['price'][$key],
                            'rent_price' => $request->subscriptions['rent_price'][$key],
                            'wash_price' => $request->subscriptions['wash_price'][$key],
                            'replacement_price' => $request->subscriptions['replacement_price'][$key],
                            'procurement_price' => $request->subscriptions['procurement_price'][$key],
                    ]);
                    
                    $subscription_schedule = $subscription->schedules()->updateOrCreate(['schedule_id'=> $schedule->id], [
                        'schedule_id'         => $schedule->id,
                        'route_schedule_id'   => $route_schedule_id,
                        'product_pricing_id'  => $pricing->id,
                        'price_to_use'        => 'rent_price',
                        'latest_delivery_date' => Carbon::createFromFormat('d/m Y', $request->transaction['start_date'])->toDateString()
                    ]);

                    $initial_amnt = $request->subscriptions['qty'][$key]; 
                    $secondary_amnt = (isset($request->subscriptions['beholdning'][$key])) ? $request->subscriptions['beholdning'][$key] : 0;
                    
                    if ($initial_amnt > 0 || $secondary_amnt > 0) {
                        $subscription_transaction = $subscription_schedule->transactions()->create(array_merge(
                                            [ 'initial_delivery_amount' => $initial_amnt, 'secondary_delivery_amount' =>  $secondary_amnt], 
                                            $request->transaction));
                        // dd($option_value_id);
                        if($product_option_value_id){
                            $option_value = ProductOptionValue::find($product_option_value_id);
                            $variant_option = $option_value->addVariantOption();
                            
                            $variant_option_transaction = new VariantOptionTransaction();
                            $variant_option_transaction->variant_options_id = $variant_option->id;
                            $subscription_transaction->variantOptionTransaction()->save($variant_option_transaction);
                        }

                        // $deliveryReposity = new DeliveryRepository;
                        // $deliveryReposity->generate($subscription_schedule);
                        if((int)$is_pooled == 1) {
                            SubscriptionSize::create([
                                'subscription_id' => $subscription->id,
                                'product_variant_id' => $filters['product_variant_id'],
                                'product_option_value_id' => $product_option_value_id,
                                'buffer_size' => $request->subscriptions['buffer'][$key]
                            ]);
                        }
                        if((int)$employee_based == 0){
                            $is_pooled_order = ($take_from_subscription_id && $take_from_subscription_id>0) ? 1 : 0;
                            $customer_id= Customer::find($c_id);
                            if($variant_option)
                                $variant_option_id[]= $variant_option->id;
                           
                            $qty = $initial_amnt + $secondary_amnt;
                            if ($qty > 0) {
                                $detail = 
                                        ['customer_id' => $c_id,
                                        'employee_id' => null,
                                        'order_date'    => Carbon::now(),
                                        'product_id' => $filters['product_id'],
                                        'product_variant_id' => $filters['product_variant_id'],
                                        'quantity' => $qty,
                                        'approved' => 1,
                                        'processed_at' => Carbon::now(),
                                        'status' => 'Pending',
                                        'user_id' => Auth::id(),
                                        'subscription_id' => $subscription->id,
                                        'subscription_transaction_id' => $subscription_transaction->id,
                                        'is_inventory_order' => 1,
                                        'is_pooled'=>$is_pooled_order
                                    ];
                                
                                $order = $customer_id->generateOrder($detail,$variant_option_id,'TAKE');
                            }                            
                        }
                    }    
                    
                }else{
                    $error = "Error occured when saving subscription.";
                }
            }    
            DB::commit();            
        }

        if($error) {
            return Redirect::route('subscriptions.create')
            ->withErrors($error)
            ->with('message', $error);
        }else{
            Session::flash('message', 'Subscription added.');
            if($request->redirect_url){
                return redirect($request->redirect_url);
            }
            return Redirect::route('subscriptions.index');
        }

    }

    /* Backup code
    public function store(Request $request)
    {
        $error = "";

        DB::beginTransaction();
        $sproducts = ($request->subscriptions['product_id']) ? $request->subscriptions['product_id'] : []; 
        if (count($sproducts) <= 0) $error = 'No product selected.';
        
        foreach($sproducts as $key => $value){
            $variant_option_id = [];
            $variant_option = null;
            $product_attribute_id = null;
            $variant_id = ($request->subscriptions['product_variant_id'][$key]) ? $request->subscriptions['product_variant_id'][$key] : null;
            $product_option_value_id = ($request->subscriptions['product_option_value'][$key] && $request->subscriptions['product_option_value'][$key] != 'null') ? $request->subscriptions['product_option_value'][$key] : null;
            $employee_based = (isset($request->subscriptions['employee_based'][$key])) ? $request->subscriptions['employee_based'][$key] : 0;
            $chip_based = (isset($request->subscriptions['chip_based'][$key])) ? $request->subscriptions['chip_based'][$key] : 0;
            $is_pooled = (isset($request->subscriptions['is_pooled'][$key])) ? $request->subscriptions['is_pooled'][$key] : 0;
            $take_from_subscription_id = (isset($request->subscriptions['take_from_subscription_id'][$key])) ? $request->subscriptions['take_from_subscription_id'][$key] : 0;
            $perform_inventory_check = (isset($request->subscriptions['perform_inventory_check'][$key])) ? $request->subscriptions['perform_inventory_check'][$key] : 0;
           // dd($request->all(),$chip_based);

            $data = array_merge($request->data,
                [
                    'product_id' => $value,
                    'product_variant_id' => $variant_id,
                    'created_by' => null,
                    'start_date' => $request->transaction['start_date'],
                    'end_date' => $request->transaction['end_date'],
                    'employee_based' => $employee_based,
                    'chip_based' => $chip_based,
                    'is_pooled' => $is_pooled,
                    'take_from_subscription_id' => $take_from_subscription_id,
                    'perform_inventory_check' => $perform_inventory_check,
                    'delivery_amount_type' => $request->subscriptions['delivery_amount_type'][$key],
                    'customer_id' => $request->data['customer_id'],
                    'copy_to_child' => $request->transaction['copy_to_child'],
                ]
            );

            // Added by JG
            $data['visible_on_employee_list'] = ($data['show_on_regular_employee_list'] + $data['show_on_container_employee_list'])? "1": "0";

            $customer = Customer::find($request->data['customer_id']);
            $route_schedule_id = ($customer && $customer->route_schedule_id) ? $customer->route_schedule_id : null;
            $subscription = Subscription::updateOrCreate(['product_variant_id' => $variant_id, 'customer_id' => $request->data['customer_id']], $data);
            
            if($subscription){
                
                $schedule = Schedule::firstOrCreate($request->schedule);

                if($variant_id){
                    $product_attribute = ProductAttribute::where('product_variant_id', $variant_id)->where('product_option_value_id', $request->subscriptions['product_option_value'][$key])->first();
                    if($product_attribute)
                        $product_attribute_id = $product_attribute->id;
                }
                
                $pricingRepository = new PricingRepository;
                $filters = [
                    'product_id' => $request->subscriptions['product_id'][$key],
                    'product_variant_id' => $variant_id,
                    'customer_id' => $request->data['customer_id'],
                    'schedule_id' => $schedule->id,
                    "product_attribute_id" => $product_attribute_id,
                ];

                $product_pricing = $pricingRepository->findPrice($filters,['product_id', 'product_variant_id', 'schedule_id', 'customer_id', 'product_attribute_id']);
                
                if($product_pricing) {
                    // no need to use the product pricing since the prices is an input field
                    // $price = $product_pricing->price;
                    // $wash_price = $product_pricing->wash_price;
                    // $replacement_price = $product_pricing->replacement_price;
                    // $procurement_price = $product_pricing->procurement_price;
                    $pricing_attribute = ($product_pricing->product_attribute_id) ? $product_attribute_id : null;
                }else{
                    // $price = $request->subscriptions['price'][$key];
                    // $wash_price = $request->subscriptions['wash_price'][$key];
                    // $replacement_price = $request->subscriptions['replacement_price'][$key];
                    // $procurement_price = $request->subscriptions['procurement_price'][$key];
                    $pricing_attribute = null;
                }
                
                $pricing = $pricingRepository->savePrice([
                        'product_id' => $request->subscriptions['product_id'][$key],
                        'product_variant_id' => $variant_id,
                        'customer_id' => $request->data['customer_id'],
                        'schedule_id' => $schedule->id,
                        'product_attribute_id' => $pricing_attribute,
                        'price' => $request->subscriptions['price'][$key],
                        'rent_price' => $request->subscriptions['rent_price'][$key],
                        'wash_price' => $request->subscriptions['wash_price'][$key],
                        'replacement_price' => $request->subscriptions['replacement_price'][$key],
                        'procurement_price' => $request->subscriptions['procurement_price'][$key],
                ]);
                
                $subscription_schedule = $subscription->schedules()->updateOrCreate(['schedule_id'=> $schedule->id], [
                    'schedule_id'         => $schedule->id,
                    'route_schedule_id'   => $route_schedule_id,
                    'product_pricing_id'  => $pricing->id,
                    'price_to_use'        => 'rent_price',
                    'latest_delivery_date' => Carbon::createFromFormat('d/m Y', $request->transaction['start_date'])->toDateString()
                ]);
                
                $subscription_transaction = $subscription_schedule->transactions()->create(array_merge(
                    [ 'initial_delivery_amount' => $request->subscriptions['qty'][$key], 'secondary_delivery_amount' => (isset($request->subscriptions['beholdning'][$key])) ? $request->subscriptions['beholdning'][$key] : 0 ], 
                    $request->transaction));
                // dd($option_value_id);
                if($product_option_value_id){
                    $option_value = ProductOptionValue::find($product_option_value_id);
                    $variant_option = $option_value->addVariantOption();
                    
                    $variant_option_transaction = new VariantOptionTransaction();
                    $variant_option_transaction->variant_options_id = $variant_option->id;
                    $subscription_transaction->variantOptionTransaction()->save($variant_option_transaction);
                }
                
                // $deliveryReposity = new DeliveryRepository;
                // $deliveryReposity->generate($subscription_schedule);
                if((int)$is_pooled == 1) {
                    SubscriptionSize::create([
                        'subscription_id' => $subscription->id,
                        'product_variant_id' => $filters['product_variant_id'],
                        'product_option_value_id' => $product_option_value_id,
                        'buffer_size' => $request->subscriptions['buffer'][$key]
                    ]);
                }
                if((int)$employee_based == 0){
                    $is_pooled_order = ($take_from_subscription_id && $take_from_subscription_id>0) ? 1 : 0;
                    $customer_id= Customer::find($request->data['customer_id']);
                    if($variant_option)
                        $variant_option_id[]= $variant_option->id;
                   
                    $qty = $request->subscriptions['qty'][$key] + (isset($request->subscriptions['beholdning'][$key]) ? (int)$request->subscriptions['beholdning'][$key] : 0);
                    $detail = 
                            ['customer_id' => $request->data['customer_id'],
                            'employee_id' => null,
                            'order_date'    => Carbon::now(),
                            'product_id' => $filters['product_id'],
                            'product_variant_id' => $filters['product_variant_id'],
                            'quantity' => $qty,
                            'approved' => 1,
                            'processed_at' => Carbon::now(),
                            'status' => 'Pending',
                            'user_id' => Auth::id(),
                            'subscription_id' => $subscription->id,
                            'subscription_transaction_id' => $subscription_transaction->id,
                            'is_inventory_order' => 1,
                            'is_pooled'=>$is_pooled_order
                        ];
                    
                    $order = $customer_id->generateOrder($detail,$variant_option_id,'TAKE');
                }
            }else{
                $error = "Error occured when saving subscription.";
            }
            
        }    
        DB::commit();
        
        if($error){
            return Redirect::route('subscriptions.create')
            ->withErrors($error)
            ->with('message', $error);
        }else{
            Session::flash('message', 'Subscription added.');
            if($request->redirect_url){
                return redirect($request->redirect_url);
            }
            return Redirect::route('subscriptions.index');
        }
    }
    */
    private function cleanupRequest($request)
    {
        /*Checkboxes*/
        $checkboxes = [
                        'visible_on_foldinglist','visible_on_timetable',
                        'visible_on_finalizelist','visible_on_invoice','visible_on_delivery_note',
                        'visible_on_extra_order', 'visible_on_controllist', 'reveal_discount', 
                        'extra_order_lock_finalization', 'extra_order_equalize', 'visible_on_employee_list'
                    ];

        foreach($checkboxes as $checkbox){
            $request->merge([$checkbox => (isset($request->$checkbox)) ? 1:0]);
        }
        
        $request->merge(['created_by' => Auth::id()]);

        return $request;

    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit($id)
    {
        $subscription = Subscription::with(['schedules'])->find($id);        
        if(!$subscription) abort(404);
        $pooled_subscriptions = Subscription::join('customers','customers.id','=','subscriptions.customer_id')
            ->where('product_id', $subscription->product_id)
            ->where('product_variant_id', $subscription->product_variant_id)
            ->where('is_pooled', 1)
            ->where('customer_id', '!=' , $subscription->customer_id)
            ->groupBy('customer_id')
            ->lists('customers.dist_name','subscriptions.id')->toArray();

        $delivery_types = config('delivery.type');
        $fees = Fee::all();
        $total_antal = 0; 
        $sizes = [];
        $sub_orders = $subscription->orders()->where('quantity','!=',0)->get(); 
        // dd($sub_orders);
        $subscription->pending_orders_cnt = $sub_orders->where('status','Pending')->count(); 
 
        // if(!$subscription->employeeBased() && !$subscription->pooled()){
        //     $total_antal = $subscription->amountSubscribed();
        // }
        // else {          
            foreach ($sub_orders as $order) {
                if ($order->inventoryRequest->action == 'REPLACE') continue; 
                $dsize = $order->getTransactionProductSize(); 

                if ($dsize && !isset($sizes[$dsize->size]))  $sizes[$dsize->size] = 0; 

                if ($order->action() == 'ADD') {
                    $total_antal -= $order->amountProcessed(); 
                    if ($dsize && isset($sizes[$dsize->size])) $sizes[$dsize->size] -= $order->amountProcessed();
                }                    
                else {
                    $total_antal += $order->amountProcessed(); 
                    if ($dsize && isset($sizes[$dsize->size])) $sizes[$dsize->size] += $order->amountProcessed();
                }
                    
            }
        // }

        $subscription->total_antal = $total_antal; 

        return view('subscriptions.edit', compact('subscription', 'fees', 'pooled_subscriptions', 'delivery_types', 'sizes'));
    }

    public function updateFlag(Request $request)
    {
        $message = '';
        $repo = new GenerateDeliveriesRepository;
        $subscription = Subscription::find($request->subscription_id);
        $flag = ($request->flag == 'true') ? 1 : 0;
        switch ($request->action) {
            case 'employee_based':
                $result = $subscription->update(['employee_based' => $flag]);
                if($result){
                    $message = 'Updated subscription employee based';
                }
                break;
            case 'chip_based':
                $result = $subscription->update(['chip_based' => $flag]);
               
                if($result){
                    $deliveries = Deliveri::where('customer_id', $subscription->customer_id)->where('product_variant_id', $subscription->product_variant_id)->where('delivery_date','>=',date('Y-m-d'))->get();
                    if($deliveries->count() > 0){
                        foreach ($deliveries as $delivery) {
                            $delivery->update(['chip_based' => $flag]);
                        }
                    }
                    $message = 'Updated subscription chip based';
                }
                break;
            case 'pooled':
                $result = $subscription->update(['is_pooled' => $flag]);
                if($result){
                    $message = 'Updated subscription pooled for other customers';
                }
                break;
            case 'take_from_subscription_id':
                if($flag == 0) {
                    $flag = null;
                    //should not change the flag if there is no pulje customer added
                    $result = $subscription->update(['take_from_subscription_id' => $flag]);
                    if($result){
                        $message = 'Updated pool from other customer';
                    }  
                }
                break;
            case 'change_customer':
                $result = $subscription->update(['take_from_subscription_id' => $request->take_from_subscription_id]);
                if($result){
                    $message = 'Updated pooled customer';
                }
                break;
            case 'visible_on_extra_order':
                $result = $subscription->update(['visible_on_extra_order' => $flag]);
                if($result){
                    $message = 'Updated subscription visible on extra order';
                }
                break;
            case 'show_on_regular_employee_list':
                $result = $subscription->update(['show_on_regular_employee_list' => $flag]);
                if($subscription->show_on_regular_employee_list == 1 || $subscription->show_on_container_employee_list == 1) {
                    $subscription->update(['visible_on_employee_list' => 1]);
                } else {
                    $subscription->update(['visible_on_employee_list' => 0]);
                }
                if($result){
                    $message = 'Updated subscription show on regular employee list';
                }
                break;

            case 'show_on_container_employee_list':
                $result = $subscription->update(['show_on_container_employee_list' => $flag]);
                if($subscription->show_on_regular_employee_list == 1 || $subscription->show_on_container_employee_list == 1) {
                    $subscription->update(['visible_on_employee_list' => 1]);
                } else {
                    $subscription->update(['visible_on_employee_list' => 0]);
                }
                if($result){
                    $message = 'Updated subscription show on container employee list';
                }
                break;
            case 'change_route_schedule_id':
                $subscription_schedule = SubscriptionSchedule::find($request->subscription_schedule_id);
                if ($subscription_schedule) {
                    $deliveries = $subscription_schedule->deliveries()->whereRaw('delivery_date > now() and DATEDIFF(delivery_date, now()) > 5')->where('is_extra_order',0)->orderBy('delivery_date')->get();
                    // dd($deliveries);

                    if ($subscription_schedule->route_schedule_id != $request->route_schedule_id) {
                        //if the frequency and the route schedule is changed, removed the future deliveries starting from date of update
                        if(count($deliveries) > 0){

                            foreach ($deliveries as $d) {
                                $d->forceDelete(); //force delete the future deliveries 
                            }
                            //get the last/latest delivery date
                            $latest_delivery_entry = $subscription_schedule->deliveries()->select('id','delivery_date')->where('is_extra_order',0)->orderBy('delivery_date','desc')->first();                    
                            $latest_delivery_date =  ($latest_delivery_entry) ? $latest_delivery_entry->delivery_date : null;

                            //update the subscription schedule with the new route_schedule_id and schedule_id

                            $result = $subscription_schedule->update(['route_schedule_id' => $request->route_schedule_id, 'latest_delivery_date' => $latest_delivery_date]);

                            //generate new deliveries for the new route_schedule
                            $start_date = ($latest_delivery_entry) ? Carbon::parse($latest_delivery_entry->delivery_date)->addDay() : Carbon::now()->toDateString();
                            $limit = Carbon::now()->addMonths(3);//limit to 3 months generation
                            if (Carbon::parse($start_date)->lt($limit)){                
                                $no = $repo->run($subscription_schedule, $start_date, $limit);  
                            } 
                        } else {
                            $result = $subscription_schedule->update(['route_schedule_id' => $request->route_schedule_id]);
                        }

                        if($result){
                            $message = 'Updated route schedule';
                        }
                    }
                }else {
                    $message = 'Subscription schedule not found.';
                }
                
                
                /*if($subscription_schedule){
                    $result = $subscription_schedule->update(['route_schedule_id' => $request->route_schedule_id]);
                    if($result){
                        $message = 'Updated route schedule';
                    }
                } */
                break;
            case 'update_invoice_option':
                $subscription_schedule = SubscriptionSchedule::find($request->subscription_schedule_id);
                switch ($request->invoice_option) {
                    case 'wash':
                        $price_to_use = 'wash_price';
                        break;
                    case 'rental':
                        $price_to_use = 'rent_price';
                        break;                    
                    default:
                        $price_to_use = 'price';
                        break;
                }
                $result = $subscription_schedule->update(['invoice_option' => $request->invoice_option, 'price_to_use'=>$price_to_use]);
                if($result){
                    $message = 'Updated invoice option price';
                }
                break;
            case 'update_delivery_amount_type':
                $result = $subscription->update(['delivery_amount_type' => $request->delivery_amount_type]);
                if($result){
                    $message = 'Updated subscription delivery amount type';
                }
                break;
            case 'update frequency':
                $subscription_schedule = SubscriptionSchedule::find($request->subscription_schedule_id);
                $schedule = Schedule::firstOrCreate(['frequency' => $request->frequency, 'interval' => $request->interval]);
                $deliveries = $subscription_schedule->deliveries()->whereRaw('delivery_date > now() and DATEDIFF(delivery_date, now()) > 5')->where('is_extra_order',0)->orderBy('delivery_date')->get();
                // dd($deliveries);
                if ($subscription_schedule->schedule_id != $schedule->id) {
                    //if the frequency is changed, removed the future deliveries starting from date of update
                    if(count($deliveries) > 0){

                        foreach ($deliveries as $d) {
                            $d->forceDelete(); //force delete the future deliveries 
                        }
                        //get the last/latest delivery date
                        $latest_delivery_entry = $subscription_schedule->deliveries()->select('id','delivery_date')->where('is_extra_order',0)->orderBy('delivery_date','desc')->first();                    
                        $latest_delivery_date =  ($latest_delivery_entry) ? $latest_delivery_entry->delivery_date : null;

                        //update the subscription schedule with the new route_schedule_id and schedule_id

                        $result = $subscription_schedule->update(['schedule_id' => $schedule->id, 'latest_delivery_date' => $latest_delivery_date]);

                        //generate new deliveries for the new route_schedule
                        $start_date = ($latest_delivery_entry) ? Carbon::parse($latest_delivery_entry->delivery_date)->addDay() : Carbon::now()->toDateString();
                        $limit = Carbon::now()->addMonths(3);//limit to 3 months generation
                        if (Carbon::parse($start_date)->lt($limit)){                
                            $no = $repo->run($subscription_schedule, $start_date, $limit);  
                        } 
                    } else {
                        $result = $subscription_schedule->update(['schedule_id' => $schedule->id]);
                    }

                    if($result){
                        $message = 'Updated route schedule';
                    }
                }
              /* $subscription_schedule = SubscriptionSchedule::find($request->subscription_schedule_id);
               $schedule = Schedule::firstOrCreate(['frequency' => $request->frequency, 'interval' => $request->interval]);
               $result = $subscription_schedule->update(['schedule_id' => $schedule->id]);
                if($result){
                    $message = 'Updated frequency';
                }*/
                break;
            default:
                break;
        }

        return response()->json(['message'=>$message]);

    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        //Added by JG. This is used to auto set the value of visible_on_employee_list based on the value in show_on_regular_employee_list and show_on_container_employee_list
        $auto_set = ($request->show_on_regular_employee_list + $request->show_on_container_employee_list)? "1": "0";
        Input::merge(['visible_on_employee_list' => $auto_set]);

        $this->validate($request, [
            'product_id'            => 'required|integer|exists:products,id',
            'product_variant_id'    => 'integer|exists:product_variants,id',
            'customer_id'           => 'required|integer|exists:customers,id'
        ]);
        
        $input = $request->except('sub_schedule','fees', 'take_from_storage_id_check', 'credit_based', 'DataTables_Table_0_length');
        if (!$request->has('take_from_storage_id_check') || (bool) !$request->take_from_storage_id_check) {
            $input['take_from_subscription_id'] = null;
        }
        DB::beginTransaction();

        if (isset($input['only_extra_order']) && $input['only_extra_order']) {
            $input['visible_on_extra_order'] = 1; 
            $input['employee_based'] = 0; 
            $input['chip_based'] = 0; 
            $input['is_pooled'] = 0; 
            $input['show_on_regular_employee_list'] = 0; 
            $input['show_on_container_employee_list'] = 0; 
        }

        $repo = new GenerateDeliveriesRepository;
        $subscription = Subscription::find($id);
        $subscription->fill($input)->save();

        //update future deliveries information
        foreach ($subscription->schedules as $sched) {
            //get all future deliveries not earlier than 6 days after today  
            $deliveries = $sched->deliveries()->whereRaw('delivery_date > now() and DATEDIFF(delivery_date, now()) > 5')->where('is_extra_order',0)->orderBy('delivery_date')->get();
            // if (count($deliveries) <= 0) continue;

            if (isset($request->sub_schedule[$sched->id])) {
             
                $subsched_info = $request->sub_schedule[$sched->id];
                if (isset($subsched_info['data']['invoice_option'])) {
                    if ($subsched_info['data']['invoice_option'] == 'rental') $subsched_info['data']['price_to_use'] = 'rent_price';
                    else if ($subsched_info['data']['invoice_option'] == 'wash') $subsched_info['data']['price_to_use'] = 'wash_price';
                }

                // if (isset($subsched_info['schedule']['interval']) && $subsched_info['schedule']['interval'] <= 0)   {
                //     $error = "Cannot add a 0 frequency.";
                //     return Redirect::route('subscriptions.edit', $id)
                //         ->withErrors($error)
                //         ->with('message', $error);
                // }   

                if (!isset($subsched_info['data']['route_schedule_id']) || !$subsched_info['data']['route_schedule_id']) {
                    $error = "Please add a delivery route schedule.";
                    return Redirect::route('subscriptions.edit', $id)
                        ->withErrors($error)
                        ->with('message', $error);
                }

                $schedule = Schedule::firstOrCreate($subsched_info['schedule']);
                if ($sched->schedule_id != $schedule->id || $sched->route_schedule_id != $subsched_info['data']['route_schedule_id']) {
                    //if the frequency and the route schedule is changed, removed the future deliveries starting from date of update
                    foreach ($deliveries as $d) {
                        $d->forceDelete(); //force delete the future deliveries 
                    }
                    //get the last/latest delivery date
                    $latest_delivery_entry = $sched->deliveries()->select('id','delivery_date')->where('is_extra_order',0)->orderBy('delivery_date','desc')->first();                    
                    if ($latest_delivery_entry) $subsched_info['data']['latest_delivery_date'] = $latest_delivery_entry->delivery_date;

                    //update the subscription schedule with the new route_schedule_id and schedule_id
                    $sched->update(array_merge($subsched_info['data'], ['schedule_id' => $schedule->id]));

                    //generate new deliveries for the new route_schedule and frequency
                    $start_date = ($latest_delivery_entry) ? Carbon::parse($latest_delivery_entry->delivery_date)->addDay() : Carbon::now()->toDateString();
                    $limit = Carbon::now()->addMonths(3);//limit to 3 months generation
                    if (Carbon::parse($start_date)->lt($limit)){                
                        $no = $repo->run($sched, $start_date, $limit);  
                    } 
                }
                else {
                    $sched->update($subsched_info['data']);
                }

            }

            //get all the deliveries again because after the above code there are new deliveries
            // $deliveries = $sched->deliveries()->whereRaw('delivery_date > "'.Carbon::now()->endOfWeek().'"')->where('is_extra_order',0)->orderBy('delivery_date')->get(); //before it is the reflected on the next weeks deliveries, but as per mikkel it should be all deliveries in the future 2018-06-12
            $deliveries = $sched->deliveries()->whereRaw('delivery_date >  now()')->where('is_extra_order',0)->orderBy('delivery_date')->get();

            $main = ['chip_based'=>$input['chip_based'],'processing_days'=>$input['processing_days'], 'only_extra_order'=>$input['only_extra_order']];
            if ($sched->delivery_type == 'pickup') {
                $data = array_merge($main, ['visible_on_invoice'=>0,'visible_on_driver_statement'=>1,'delivery_amount'=>0,'total_qty'=>0,'total_price'=>0]);
            }                
            else {
                $data = array_merge($main, [
                    'visible_on_timetable'=>$input['visible_on_timetable'],
                    'visible_on_finalizelist'=>$input['visible_on_finalizelist'],
                    'visible_on_delivery_note'=>$input['visible_on_delivery_note'],
                    'visible_on_controllist'=>$input['visible_on_controllist'],
                    'visible_on_invoice'=>$input['visible_on_invoice']
                ]);
            }

            $data['show_recent_dirty'] = ($input['delivery_amount_type'] == 'fixed') ? 0 : 1;

            foreach ($deliveries as $key => $d) {
                $d->update($data);
            }
        }
        
        
        //addfee
        if(count($request->fees) > 0){
            foreach($request->fees as $fee_id => $value){
                $subscription->addFee($fee_id, $value);
            }
        }
        // dd($request->all());
        // foreach($request->sub_schedule as $sub_schedule_id => $sub_schedule){
        //     $schedule = Schedule::firstOrCreate($sub_schedule['schedule']);
        //     $subscription_schedule = SubscriptionSchedule::find($sub_schedule_id);
        //     if (!$subscription_schedule) continue;
        //     $subscription_schedule->update(array_merge($sub_schedule['data'], ['schedule_id' => $schedule->id]));
        // }

        DB::commit();
        
		Session::flash('flash_message', 'Update successful.');
		return redirect()->back();
        
    }

    private function logSales($oldsale,$input){
        // * Field-change history (like on source-forge)
        $body_lines = array();

        // * Fields to listen to for changes
        $listen_on_fields = array(
            "product_id" => "Produktnr.",
            "amount" => "Antal",
            "in_circulation" => "Beholdning",
            "discount" => "#Rabat",
            "extra_price" => "#Bestillingspris",
            "reveal_discount" => "?Vis rabat på faktura",
            "delivery_fee" => "#Leveringsgebyr",
            "dist_price" => "#Distributionspris",
            "not_delivered_fee" => "#Ikke leveret gebyr",
            "change_freq" => "Skiftefrekvens",
            "visible_on_foldinglist" => "?Vis på foldelisten",
            "visible_on_controllist" => "?Vis på kontrollisten",
            "visible_on_timetable" => "?Vis på kørselslisten",
            "visible_on_finalizelist" => "?Vis på indsorteringslisten.",
            "visible_on_invoice" => "?Vis på faktura",
            "visible_on_delivery_note" => "?Vis på følgeseddel",
            "show_recent_dirty" => "?Benyt seneste snavset",
            "use_dirty_as_deliv_amount" => "?Brug snavset som lev. mængde",
            "is_terminated" => "?Opsagt",
            "termination_date" => "@Dato for opsigelse",
            "start_date" => "@Start dato",
            "stop_date" => "@Stop dato",
            "last_delivered" => "@Seneste leveringsdato",
            "vacation_start" => "@Ferie start",
            "vacation_stop" => "@Ferie slut",
            "last_vacation_date" => "@Ferie gentagelses stop",
            "comments_packing" => "Pakkeliste kommentar",
            "comments_finalize" => "Indsorterings kommentar",
            "extra_order_equalize" => "?Udlign ekstra kørsler",
            "extra_order_lock_finalization" => "?Lås indsorteringslisten for ekstra kørsler",
            "visible_on_extra_order" => "Vis abb. ved bestilling"
        );

        foreach($listen_on_fields as $field => $field_name)
        {
            if(substr($field_name, 0, 1) == '#')
            {
                if(from_money($input[$field]) != $oldsale[$field]) // * boolean field
                    $body_lines[] = substr($field_name, 1).': '.$oldsale[$field].' => '.$input[$field];
            }
            else if(substr($field_name, 0, 1) == '@')
            {
                if (array_key_exists($field,$input)){
                    $idate = date_create_from_format('d/m Y',$input[$field]);
                    if ($idate){
                        $input[$field]=date_format($idate, 'Y-m-d');
                    }
                    if($input[$field] && $input[$field] != $oldsale[$field]) // * boolean field
                        $body_lines[] = substr($field_name, 1).': '.$oldsale[$field].' => '.$input[$field];
                }
            }
            else if(substr($field_name, 0, 1) == '?')
            {
                if(intval(stripslashes($input[$field])) != $oldsale[$field]) // * boolean field
                    $body_lines[] = substr($field_name, 1).': '.$oldsale[$field].' => '.$input[$field];
            }
            else if(stripslashes($input[$field]) != $oldsale[$field])
            {
                $body_lines[] = $field_name.': '.$oldsale[$field].' => '.$input[$field];
            }
        }

        if(count($body_lines) > 0)
        {
            $log = new SalesLog;
            $log->created = date('Y-m-d H:i:s');
            $log->is_from_system = 1;
            $log->user_id = Auth::user()->id;
            $log->sales_id = $oldsale['id'];
            $log->body = implode(",\n", $body_lines);
            return $log->save();
        }
        return true;
    }
    
    public function ajax(Request $request)
    {
        if($request->subscription_id){
            $subscriptions = $request->subscription_id;
            DB::beginTransaction();
            foreach($subscriptions as $subscription_id){
                $subscription = Subscription::find($subscription_id);
                switch ($request->action) {
                    case 'duplicate':
                        if($request->customer_id){
                            $customer = Customer::find($request->customer_id);
                            if (!$customer) return array('result'=>false,'message'=>"Customer not found.");
                            $exist = $customer->subscriptions()->where('product_variant_id',$subscription->product_variant_id)->where('product_id',$subscription->product_id)
                                    ->where(function($query) {
                                        $query->whereNull('termination_date')->orWhere('termination_date','>','now()');
                                    })->first();
                            if (!$exist) $subscription->duplicateSubscription($customer);
                            else return array('result'=>false,'message'=>'Cannot copy "'.$subscription->variant->name.'". Product already exists.');
                            // $new->fill(['customer_id' => $request->customer_id])->save();
                        }else{
                            return array('result'=>false,'message'=>"Please select customer!");
                        }
                        break;
                    case 'activate':
                        $subscription->activate();
                        break;
                    case 'deactivate':
                        $subscription->terminate();
                        break;
                    case 'delete':
                        // $subscription_schedule = SubscriptionSchedule::findOrFail($subscription_id);
                        // $deliveryReposity = new DeliveryRepository;
                        // $deliveryReposity->deleteSubscriptionFutureDeliveries($subscription_schedule);
                        $data = $this->destroy($subscription_id);
                        if (!$data['result']) return $data;
                        break;
                    default:
                        // code to be executed if n is different from all labels;
                }
            }
            DB::commit();
        }

        return array('result'=>true,'message'=>"Successfully ".$request->action."d subscription records");
        // return $request->action;
    }
    
    public function deleteSale(Request $request)
    {
        $response = array('result'=>true,'message'=>"Successfully deleted subscription");
        $subscription = Subscription::find($request->id);
        
        if($subscription){
            if($subscription->employeeBased() || $subscription->pooled()){
                $subscribed_amount = $subscription->amountSubscribed();
                
                if($subscribed_amount > 0){
                    $response = array('result'=>false,'message'=>"Cannot delete an active subscription.");
                }
            }
        }else{
            $response = array('result'=>false,'message'=>"Subscription not found!");
        }
        
        if($response['result'] == true){
            $subscription->terminate();
            $subscription->delete();
        }
        
        return $response;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        $response = array('result'=>true,'message'=>"Successfully deleted subscription");
        $subscription = Subscription::find($id);
        if($subscription){
            if($subscription->employeeBased() || $subscription->pooled()){
                $subscribed_amount = $subscription->amountSubscribed();
                
                if($subscribed_amount > 0){
                    $response = array('result'=>false,'message'=>"Cannot delete an active subscription.");
                }
            }
        }else{
            $response = array('result'=>false,'message'=>"Subscription not found!");
        }
        
        if($response['result'] == true){
            foreach ($subscription->schedules as $schedule) {
                $start_date = Carbon::now();
                $route_day = $schedule->routeSchedule->day;
                if ($start_date->dayOfWeek <= 3 && $route_day <= 3) {
                    $start_date = $start_date->addWeek()->startOfWeek();
                }
                if ($start_date->dayOfWeek > 3 && $route_day <= 3) {
                    $start_date = $start_date->addWeeks(2)->startOfWeek();
                }
                else {
                    $start_date->addDay();
                }
                $schedule->deliveries()->where('delivery_date', '>',$start_date->toDateString())->forceDelete(); 
                $schedule->delete();
            }
            
            $subscription->terminate();
            $subscription->delete();
            
        }
        
        return $response;
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function createTransaction($id)
    {
        $subscription = Subscription::find($id);
        // $beholdnings = [];
        $subschedules = $subscription->schedules()->whereNotIn('delivery_type',['pickup'])->get();
        foreach ($subschedules as $sched) {
            if (!$sched->route_schedule_id && $subscription->customer->route_schedule_id) {
                //copy from the customer
                $sched->update(['route_schedule_id'=>$subscription->customer->route_schedule_id]);
            }
        }

        $beholdnings = InventoryTransaction::select(DB::raw("(SUM(IF(itl.action != 'REVOKE' and itl.action != 'DISCARDED', processed_quantity, 0)) - SUM(IF(itl.action = 'REVOKE' or itl.action = 'DISCARDED', processed_quantity, 0))) as total, IF(vot.id, vot.size, 0) as size"))
                    ->join('inventory_transaction_logs as itl',function($query){
                        $query->on('itl.inventory_transaction_id','=','inventory_transactions.id')->whereNull('itl.deleted_at');
                    })
                    ->join('inventory_requests as ir', function($query){
                        $query->on('ir.id','=','inventory_transactions.inventory_request_id')->where('ir.action', '!=','REPLACE');
                    })                                
                    ->join('orders as o','o.id','=','ir.order_id')
                    ->leftJoin('subscription_transactions as st', 'st.id','=','o.subscription_transaction_id')
                    ->leftJoin(DB::raw("(SELECT optionable_id, optionable_type, variant_options_id, ov.value as size, vo.product_option_id, vot.id, ov.value, vo.product_option_value_id
                         FROM variant_options_transactions vot
                         JOIN `variant_options` AS `vo` ON `vo`.`id` = `vot`.`variant_options_id`
                           JOIN `product_option_values` AS `pov` ON `pov`.`id` = `vo`.`product_option_value_id`
                           JOIN `option_values` AS `ov` ON `ov`.`id` = `pov`.`option_value_id` WHERE  optionable_type = 'inventory_order_transaction'
                       )  AS vot"),'vot.optionable_id','=','o.id')
                    ->where('inventory_transactions.product_id',$subscription->product_id)
                    ->where('inventory_transactions.product_variant_id',$subscription->product_variant_id)   
                    ->where('o.subscription_id', $subscription->id)    
                    ->whereRaw(DB::raw("(st.end_date is NULL or st.end_date > NOW())"))//->where('vot.size','L')           
                    ->groupBy('vot.size')->get()->keyBy('size')->toArray();
        // dd($beholdnings);
        
        // dd($beholdnings);
        // if($subscription->product->variantOption)
                return View::make('subscriptions.transactions.new', compact('subscription','beholdnings','subschedules'));
        // else 
        //     return View::make('subscriptions.transactions.new_nosizes')->with(['subscription'=>$subscription]);
    }

    /**
    * This is only applicable to non-employee based subscriptions
    *
    */
    public function storeTransaction(Request $request)
    {
        $subscription = Subscription::find($request->subscription_id);
        $customer_id = $subscription->customer_id;

        // dd($subscription->customer);
        // dd($subscription->customer_id); 
        $input = Input::except('_token');
       /* if(!Input::has('subscription_schedule_id'))
            abort("Fejl: Mangler abonnementsnr.");*/
        
        DB::beginTransaction();
        $subscription_schedule = SubscriptionSchedule::find($request->subscription_schedule_id);        
        
        $deliveryReposity = new DeliveryRepository;
        //Attach variant option
        foreach ($request->initial_delivery_amount as $variant_option => $quantity) {
            if($quantity == 0) continue;

            //create subscrtion transaction per size then order
            $end_date = ($request->stop_date) ? Carbon::createFromFormat('d/m Y', $request->stop_date)->toDateString() : null;
            $subscription_transaction = $subscription_schedule->transactions()->create([
                'initial_delivery_amount'   => $quantity,
                'start_date'                => Carbon::createFromFormat('d/m Y', $request->start_date)->toDateString(),
                'end_date'                  => $end_date,
                'created_by'                => $request->user()->id,
            ]);
            

            // if($product_option_value_id){
            //     $option_value = ProductOptionValue::find($product_option_value_id);
            //     $variant_option = $option_value->addVariantOption();
                
            //     $variant_option_transaction = new VariantOptionTransaction();
            //     $variant_option_transaction->variant_options_id = $variant_option->id;
            //     $subscription_transaction->variantOptionTransaction()->save($variant_option_transaction);
            // }

            //if product has size
            if ($variant_option && $variant_option > 0) {
                $option_value = ProductOptionValue::find($variant_option);
                $variant_option = $option_value->addVariantOption();
                
                $variant_option_transaction = new VariantOptionTransaction();
                $variant_option_transaction->variant_options_id = $variant_option->id;
                $subscription_transaction->variantOptionTransaction()->save($variant_option_transaction);

                $variant_option_id= ['variant_option_id' => $variant_option->id];
            }
            else {
                $variant_option_id= [];
            }
            

            if ($quantity < 0)
            {
                $request_type = "SUBSCRIPTION";
                $action = 'ADD';
                $initial_delivery_amount = ltrim($quantity, '-');
            }else{
                $request_type = "SUBSCRIPTION";
                $action = 'TAKE';
                $initial_delivery_amount = $quantity;
            }

            $detail = 
                [
                    'customer_id' => $subscription->customer_id,
                    'employee_id' => null,
                    'order_date'    => Carbon::now(),
                    'product_id' => $subscription->product_id,
                    'product_variant_id' => $subscription->product_variant_id,
                    'quantity' => $initial_delivery_amount,
                    'approved' => 1,
                    'processed_at' => Carbon::now(),
                    'status' => 'Pending',
                    'user_id' => Auth::id(),
                    'subscription_id' => $subscription->id,
                    'subscription_transaction_id' => $subscription_transaction->id,
                    'is_inventory_order' => 1,
                    'is_pooled' => ($subscription->take_from_subscription_id) ? 1 : 0,
                    'chip_based' => $subscription->chip_based
                ];
            
            $order = null; 
            if($initial_delivery_amount > 0){
                $order = $subscription->customer->generateOrder($detail,$variant_option_id,$action);
            }

            //update deliveries - this is already transferred to the order, when processing an order and inventory transaction was made 
            //listener, UpdateDeliveries
            //$deliveryReposity->updateDeliveryAfterSubscriptionTransaction($subscription_transaction);

            $rbuffer = ($request->buffer) ? $request->buffer : [];
            foreach ($rbuffer as $product_option_value_id => $buffer) {
                if($buffer !== 0){

                    $subscritpion_size = SubscriptionSize::where('subscription_id', $subscription->id)
                        ->where('product_variant_id', $subscription->product_variant_id)
                        ->where('product_option_value_id',$product_option_value_id)
                        ->first();
                    if($subscritpion_size){
                        $subscritpion_size->update(
                        ['buffer_size' => $buffer]);
                    } 
                    elseif($buffer > 0 && $subscritpion_size == null) {
                        SubscriptionSize::firstOrCreate(
                        ['subscription_id' => $subscription->id,
                        'product_variant_id' => $subscription->product_variant_id,
                        'product_option_value_id' => $product_option_value_id,
                        'buffer_size' => $buffer
                        ]);
                    }

                        
                }
            }
        }
        
        if ($request->has('generate_orders_now'))
        {
            //Update deliveries
            //does not go here
            $deliveryReposity = new DeliveryRepository;
            $deliveryReposity->updateDeliveryAfterSubscriptionTransaction($transaction);
            Session::flash('message', 'Added changes on the subscription.');
        }else{
            Session::flash('message', 'Tillæget er nu tilføjet, der blev ikke lavet nogle ekstra ordrer til fakturering.');
        }
                    
        DB::commit();
        
        if(Input::get('iframe')) {
            return redirect()->back()->with('flash_message', 'Tillæget er nu tilføjet, der blev ikke lavet nogle ekstra ordrer til fakturering.');
        } else {
            return redirect()->route('subscriptions.index', ['customer_id' => $subscription->customer_id]);
        }

    }

    public function getHistory($id)
    {
        $sale = Subscription::find($id);
        if(!$sale)
        {
            echo 'Abbonnementet eksisterer ikke.<br>';
            return false;
        }
        $history = SalesLog::select('*', 'u.firstname')
                ->join('users as u','u.id','=','sales_log.user_id')
                ->where('sales_log.sales_id','=',$sale->id)
                ->orderBy('created','desc');

        return Datatables::of($history)
            ->editColumn('created', function($history){
                $created = date_create($history->created);
                return date_format($created,'d/m Y H:i:s');
            })
            ->editColumn('user', function($history){
                return safe(wordwrap(nl2br(User::find($history->user_id)->fullname()), 50, "<br>\n", false));
            })
            ->editColumn('body', function($history){
                if($history->is_from_system)
                    return '<span style="color:blue">'.enhancetext($history->body).'</span>';
                elseif ($history->warning ==1)
                    return '<span style="color:red">'.enhancetext($history->body).'</span>';
                else
                    return enhancetext($history->body);
            })
            ->make(true);

    }

    public function multipleUpdate()
    {
        $fields = [
            "amount" => [
                "name" => "amount",
                "title" => "Antal",
                "unit" => "stk.",
                "options" => Config::get('constants.UM_TYPE_INTEGER')
            ],
            "in_circulation" => [
                "name" => "in_circulation",
                "title" => "Beholdning",
                "unit" => "stk.",
                "options" => Config::get('constants.UM_TYPE_INTEGER')
            ],
            "discount" => [
                "name" => "discount",
                "title" => "Rabat",
                "unit" => "kr.",
                "options" => Config::get('constants.UM_TYPE_DECIMAL')
            ],
            "delivery_fee" => [
                "name" => "delivery_fee",
                "title" => "Leveringsgebyr",
                "unit" => "kr.",
                "options" => Config::get('constants.UM_TYPE_DECIMAL')
            ],
            "dist_price" => [
                "name" => "dist_price",
                "title" => "Distributionspris",
                "unit" => "kr.",
                "options" => Config::get('constants.UM_TYPE_DECIMAL')
            ],
            "extra_price" => [
                "name" => "extra_price",
                "title" => "Bestillingspris",
                "unit" => "kr.",
                "options" => Config::get('constants.UM_TYPE_DECIMAL')
            ],
            "not_delivered_fee" => [
                "name" => "not_delivered_fee",
                "title" => "Ikke-leveret gebyr",
                "unit" => "kr.",
                "options" => Config::get('constants.UM_TYPE_DECIMAL')
            ],
            "change_freq" => [
                "name" => "change_freq",
                "title" => "Skiftefrekvens",
                "unit" => "dage",
                "options" => Config::get('constants.UM_TYPE_INTEGER')
            ],
            "product_id" => [
                "name" => "product_id.",
                "title" => "Produktnr.",
                "unit" => "",
                "options" => Config::get('constants.UM_TYPE_FOREIGN_KEY') | Config::get('constants.UM_NEUTER')
            ],
            "product_id_incldeliv" => [
                "name" => "product_id_incldeliv",
                "field" => "product_id",
                "title" => "Produktnr. (inkl. fremtidige leveringer)",
                "unit" => "",
                "options" => Config::get('constants.UM_TYPE_FOREIGN_KEY') | Config::get('constants.UM_NEUTER'),
                "incl_future_deliveries" => true,
            ],
        ];

        $actionRoute = 'sales.multipleupdate';
        $ids = (Input::has('hd_items') ? explode(',',Input::get('hd_items')) : Input::get('ids'));
        $inputs = Input::get();

        $field_key = (Input::has('change_field')? Input::get('change_field'): array_keys($fields)[0]);
        $change_type = (Input::has($field_key.'_change_type') ? Input::get($field_key.'_change_type') : 'relative');
        $change_relative_in = (Input::has($field_key.'_relative_in') ? Input::get($field_key.'_relative_in'): 'unit');
        $compact = array('ids','fields','field_key','change_type','change_relative_in','inputs','actionRoute');
        $extra_message = '';

        
        

        if(Input::has('preview') || Input::has('confirm')) {
            $change_value = Input::get($field_key.'_'.$change_type.'_value');            
            $change_value = $change_value ? $change_value : 0;
            $change_field = (isset($fields[Input::get('change_field')]['field']) ? $fields[Input::get('change_field')]['field'] : $field_key);
            
            $items_before = Subscription::join('products as p','p.id','=','sales.product_id')
                ->leftJoin('product_variants as pv','pv.id','=','sales.product_variant_id')
                ->select(DB::raw("sales.*,IF(sales.product_variant_id, concat(p.name,' - ',pv.name), p.name) as _preview_name,sales.$change_field as _value"))
                ->whereIn('sales.id',$ids)
                ->get();
            
            $preview_items = [];
            $preview_ids = [];
            foreach($items_before as $row) {
                $preview_ids []= $row['id'];
                $preview_items['row_'.$row['id']]['before'] = $row;
            }

            $compact[] = 'preview_items';

            // make the modification and display the results
            DB::beginTransaction();
            if($change_type == 'absolute') {
                DB::update("UPDATE sales SET ".$change_field." = ? WHERE id IN ('".implode("','", $ids)."')", array($change_value));
            } else if($change_type == 'relative' && $change_relative_in == 'unit') {
                DB::update("UPDATE sales SET ".$change_field." = ".$change_field." + ? WHERE id IN ('".implode("','", $ids)."')", array($change_value));
            } else if($change_type == 'relative' && $change_relative_in == 'pct') {
                $pct_ratio = str_replace(
                    array(' ', '+', '%'),
                    array('', '', ''),
                    $change_value
                );
                $pct_ratio = 1+(from_money($pct_ratio)/100);
                // Beware of decimal dragons!
                // If fields and parameters are not converted to DECIMAL with enough decimal points, rounding errors will occur since the DB does not round correctly (it truncates).
                DB::update("UPDATE sales SET ".$change_field." = ROUND(CONVERT(".$change_field.", DECIMAL(64,15)) * CONVERT(".$pct_ratio.", DECIMAL(64,15)), 2) WHERE id IN ('".implode("','", $ids)."')", array($change_value));
            }

            $change_msg = "";
            if($change_type == 'relative') {
                $change_msg .= "".ucfirst($fields[$field_key]['title'])." justeret med ".($change_value > 0 ? '+' : '').$change_value." ".($change_relative_in == 'pct' ? '%' : $fields[$field_key]['unit']);
            } else {
                $change_msg .= "".ucfirst($fields[$field_key]['title'])." sat til ".$change_value." ".$fields[$field_key]['unit'];
            }
            $compact[] = 'change_msg';

            if($change_field == 'product_id' && isset($fields[$field_key]['incl_future_deliveries'])) {
                $affected = DB::update("UPDATE deliveries SET product_id = ? WHERE delivery_date >= NOW() AND sales_id IN ('".implode("','",$ids)."')", [$change_value]);
                $extra_message .= count($ids).' fremtidige leveringer bliver også ændret.';
                // FIXME: changes to the updated deliveries are not logged properly yet
                $compact[] = 'extra_message';
            }

            if(Input::has('confirm')) {
                // Hard-code way to log the actual change
                foreach($ids as $sales_id) {
                    $saleLog = new SalesLog;
                    $saleLog->created = date('Y-m-d H:i:s');
                    $saleLog->is_from_system = 1;
                    $saleLog->user_id = Auth::user()->id;
                    $saleLog->sales_id = $sales_id;
                    $saleLog->body = $change_msg;
                    $saleLog->save();
                }
                DB::commit();
                Session::flash('message', $change_msg.' ('.count($ids).' stk.)');
                return Redirect::route('sales.index');
            } else { // _POST preview is set
                $preview = Input::has('preview');
                $compact[] = 'preview';
                $items_after = Subscription::join('products as p','p.id','=','sales.product_id')
                                ->leftJoin('product_variants as pv','pv.id','=','sales.product_variant_id')
                                ->select(DB::raw("sales.*,IF(sales.product_variant_id, concat(p.name,' - ',pv.name), p.name) as _preview_name,sales.$change_field as _value"))
                                ->whereIn('sales.id',$ids)
                                ->get();

                foreach($items_after as $row) {
                    $preview_items['row_'.$row->id]['after'] = $row;
                }

                DB::rollBack();
            }
        }

        return View::make('multiple_update',compact($compact));
    }

    public function multipleDuplicate()
    {
        $ids = (Input::has('ids') ? explode(',',Input::get('ids')) :'');

        $sales = Subscription::whereIn('id', $ids ?: [0])->first();
        $customer_id = $sales->customer_id;
        $start_date = $sales->start_date;

        return View::make('subscriptions.multiple_duplicate', compact('sample', 'customer_id', 'start_date','ids'));
    }

    public function saveDuplicate() {

        return DB::transaction(function() {
            $num = 0;
            $customer_id = Input::get('customer_id');
            $start_date = Input::get('start_date');
            $ids = Input::get('ids');
            $subscriptions = Subscription::whereIn('id', $ids ?: [0])->get();

            $rules = [
                'customer_id' => Subscription::$rules['customer_id'],
                'start_date' => 'required',
            ];

            $v = Validator::make(compact('customer_id', 'start_date'), $rules);
            if($v->passes()) {
                foreach($subscriptions as $s) {
                    $new = $s->replicate();
                    $new->created = date('Y-m-d H:i:s');
                    $new->start_date = $start_date;
                    $new->customer_id = $customer_id;
                    $new->save();
                    $num++;
                }

                Session::flash('message', $num.' abonnmenter blev kopieret til kundenr. '.$customer_id);
                return Redirect::route('sales.index');
            } else {
                return Redirect::back()->withErrors($v->errors());
            }
        });
    }
    
    public function search()
    {
		$name = Input::get('term');
		$customer_id = Input::get('customer_id');
		$is_pooled = Input::get('is_pooled');
        $subscriptions = Subscription::select(array('subscriptions.id', 'pv.name as text'))
            ->join('product_variants as pv','pv.id','=','subscriptions.product_variant_id');

        if(isset($name))
            $subscriptions->where('pv.name', 'like', "%$name%");
        
        if(isset($customer_id))
            $subscriptions->where('customer_id',$customer_id);
        
        if(isset($is_pooled))
            $subscriptions->where('is_pooled',$is_pooled);
        
        $subscriptions = $subscriptions->get();
        
        return $subscriptions;
    }
    
    public function customerHasPooled(Request $request)
    {
        $customer_id = $request->customer_id;
        $product_variant_id = $request->product_variant_id;

        $pooledSubscription = Subscription::where('is_pooled', 1)
            ->where('customer_id', '=' , $customer_id)
            ->where('product_variant_id', '=' , $product_variant_id)
            ->first();
            
        return response()->json(['data'=>$pooledSubscription]);
    }
    
    public function createPooledSubscription(Request $request)
    {
        $customer = Customer::find($request->customer_id);
        $product_variants = $request->product_variant_id;

        foreach($product_variants as $id){
            $product_variant = ProductVariant::find($id);
            $subscription = $customer->subscriptions()->where('product_variant_id', $request->product_variant_id)->first();

            if(!$subscription && $product_variant){
                DB::beginTransaction();
                $subscription = new Subscription;                    
                $subscription->product_id = $product_variant->product_id;
                $subscription->product_variant_id = $product_variant->id;
                $subscription->customer_id = $customer->id;
                $subscription->start_date = Carbon::now();
                $subscription->end_date = null;
                $subscription->employee_based = 0;
                $subscription->chip_based = 1;
                $subscription->is_pooled = 1;
                $subscription->status = 'ACTIVE';
                $subscription->created_by = $request->user()->id;
                $subscription->save();
                
                 //Save schedule
                $schedule = Schedule::firstOrCreate([
                    'frequency' => 'WEEKLY',
                    'interval' => 0,
                ]);

                $pricingRepository = new PricingRepository;

                $filters = [
                    'product_id' => $product_variant->product_id,
                    'product_variant_id' => $product_variant->id,
                    // 'customer_id' => $customer->id,
                    'schedule_id' => $schedule->id,
                ];

                $product_pricing = $pricingRepository->findPrice($filters,['product_id', 'product_variant_id', 'schedule_id', 'customer_id']);
                if($product_pricing == false) {
                    $product_pricing = $pricingRepository->savePrice([
                        'price' => 0,
                        'replacement_price' => 0,
                        'rent_price' => 0,
                        'wash_price' => 0,
                        'procurement_price' => 0,
                        'product_id' => $product_variant->product_id,
                        'product_variant_id' => $product_variant->id,
                        'customer_id' => $customer->id,
                        'schedule_id' => $schedule->id
                    ]);
                         
                }else{
                    $product_pricing = $pricingRepository->savePrice([
                        'price' => $product_pricing->price,
                        'replacement_price' => $product_pricing->replacement_price,
                        'rent_price' => $product_pricing->rent_price,
                        'wash_price' => $product_pricing->wash_price,
                        'procurement_price' => $product_pricing->procurement_price,
                        'product_id' => $product_variant->product_id,
                        'product_variant_id' => $product_variant->id,
                        'customer_id' => $customer->id,
                        'schedule_id' => $schedule->id
                            
                    ]);
                }
                
                //Subscription Schedule
                $subscription_schedule = $subscription->schedules()->create([
                    'schedule_id'               => $schedule->id,
                    'route_schedule_id'         => null,
                    'product_pricing_id'        => $product_pricing->id,
                    'extra_price'               => 0,
                    'delivery_fee'              => 0,
                    'wash_price'                => 0,
                    'price_to_use' => 'rent_price',
                    'invoice_option' => 'rental',
                ]);
                
                DB::commit();
            }
            
        }

        return array('result'=>true);
    }

    public function addNewSubscriptionSchedule()
    {
        $input = Input::except('_token');
        $data = [];
        foreach ($input as $name => $value) {
            $new_name = substr($name, 3);
            $data[$new_name] = $value;
        }

        $subscription = Subscription::find($data['subscription_id']);
        if (!$subscription) return response()->json(['result'=>false, 'message'=>'Subscription not found.']);

        $route_schedule = RouteSchedule::find($data['route_schedule_id']);
        $subrs = $subscription->schedules()->join('route_schedules as rs','rs.id','=','subscription_schedules.route_schedule_id')->get()->pluck('day')->toArray();
        // dd($subrs, $route_schedule);
        if (in_array($route_schedule->day, $subrs)) return response()->json(['result'=>false, 'message'=>'Subscription schedule on a '.strtoupper(config('delivery.week_nos')[$route_schedule->day]).' already exists.']);

        $schedule = Schedule::firstOrCreate(['frequency'=>$data['interval_type'], 'interval'=>$data['interval']]); 
        $samescheds = [];      
        if ($schedule) {
            $data['schedule_id'] = $schedule->id;
            $samescheds = Schedule::where('frequency',$data['interval_type'])->where('interval',$data['interval'])->get()->pluck('id')->toArray();
            unset($data['interval']); unset($data['interval_type']);
        }

        $scheds = $subscription->schedules()->whereIn('schedule_id',$samescheds )->where('route_schedule_id', $data['route_schedule_id'])->whereNull('deleted_at')->get();        
        if (count($scheds) > 0) return response()->json(['result'=>false, 'message'=>'Subscription schedule already exists.']);

        $pricingRepository = new PricingRepository;
        $filters = [
            'product_id' => $subscription->product_id,
            'product_variant_id' => $subscription->product_variant_id,
            'customer_id' => $subscription->customer_id,
            'schedule_id' => $schedule->id,
            "product_attribute_id" => null
        ];
        $product_pricing = $pricingRepository->findPrice($filters,['product_id', 'product_variant_id', 'schedule_id', 'customer_id', 'product_attribute_id']);
        if (!$product_pricing) {
            //if theres no current product pricing for that schedule, use the existing pricing and copy 
            unset($filters['schedule_id']);
            $product_pricing = $pricingRepository->findPrice($filters,['product_id', 'product_variant_id', 'customer_id', 'product_attribute_id']);
        }
        else if (in_array($schedule->id, [1,3]) && is_null($product_pricing->customer_id)) { //same schedule
            $filters['schedule_id'] = ($schedule->id == 1) ? 3 : 1; //this is not ideal but basically 3 and 1 are the same weekly 
            $product_pricing = $pricingRepository->findPrice($filters,['product_id', 'product_variant_id', 'schedule_id', 'customer_id', 'product_attribute_id']);
        }

        $pricing_schedule = (in_array($schedule->id, [1,3])) ? $product_pricing->schedule_id : $schedule->id;
        $pricing_attribute = ($product_pricing && $product_pricing->product_attribute_id) ? $product_attribute_id : null;            
        $pricing = $pricingRepository->savePrice([
                'product_id' => $subscription->product_id,
                'product_variant_id' => $subscription->product_variant_id,
                'customer_id' => $subscription->customer_id,
                'schedule_id' => $pricing_schedule,
                'product_attribute_id' => $pricing_attribute,
                'price' => $product_pricing->price,
                'rent_price' => $product_pricing->rent_price,
                'wash_price' => $product_pricing->wash_price,
                'replacement_price' => $product_pricing->replacement_price,
                'procurement_price' => $product_pricing->procurement_price,
        ]);
        if ($pricing) {
            $data['schedule_id'] = $pricing_schedule;
            $data['product_pricing_id'] = $pricing->id;
            $data['price_to_use'] = 'rent_price';
            $data['invoice_option'] = 'rental';
        }

        DB::beginTransaction();
        $subschedule = SubscriptionSchedule::firstOrCreate($data);
        if ($subschedule) {
            DB::commit(); 
            return response()->json(['result'=>true, 'message'=>'New subscription schedule was successfully created.']);
        }
        else {
            DB::rollBack(); 
            return response()->json(['result'=>false, 'message'=>'There was an error in your transaction.']);
        }
    }

    public function deleteSubscriptionSchedule()
    {
        $subschedule = SubscriptionSchedule::find(Input::get('id'));
        if (!$subschedule) return response()->json(['result'=>false, 'message'=>'Schedule not found.']);
        $start_date = Carbon::now(); 
        $route_day = $subschedule->routeSchedule->day;
        if ($start_date->dayOfWeek <= 3 && $route_day <= 3) {
            $start_date = $start_date->addWeek()->startOfWeek();
        }
        if ($start_date->dayOfWeek > 3 && $route_day <= 3) {
            $start_date = $start_date->addWeeks(2)->startOfWeek();
        }
        else {
            $start_date->addDay();
        }
        
        $subschedule->deliveries()->where('delivery_date', '>',$start_date->toDateString())->forceDelete(); 
        $true = $subschedule->delete(); 
        if ($true) return response()->json(['result'=>true, 'message'=>'Subscription schedule was successfully deleted.']);

        return response()->json(['result'=>false, 'message'=>'There was an error in your transaction']);
    }

    public function getCustomers()
    {
        return Customer::select('id', DB::raw("concat(id,' - ',dist_name) as name"))->get();
    }
}
