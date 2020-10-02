<?php 

namespace Avask\Traits;

use Avask\Models\Invoices\Invoice;
use Avask\Models\Payments\PaymentMethod;
use Avask\Models\Deliveries\Delivery;
use Avask\Traits\Delivery\DeliveryTrait;
use Avask\Traits\Inventory\InventoryTrait;
use Avask\Models\Customers\CustomerEmployee;
use Avask\Models\Setting;
use Auth;
use AppStorage;
use Carbon\Carbon;
use DB;

trait CustomerTrait
{
    use InventoryTrait;
    use DeliveryTrait;
    
    private $subscription_import_ignore_list;
    
    public function __construct()
    {
        parent::__construct(); 
        $this->subscription_import_ignore_list = config('customer.subscription_import_ignore_list');
    }
    
    public function generateEmployeeNo($type = "regular")
    {
        if ($type === null) {
            $type = "regular";
        }
        $no = 1;
        $system = '';
        $number_system = $this->numberSystem;
        $highest = config('temporary_employee.container_start');
        $lowest = 1;
        
        if($number_system){
            $no = ($number_system->from) ? $number_system->from : $no;
            $highest = $number_system->to;
            $system = $number_system->number_system;
        }

        if ($type === "container") {
            $no = config('temporary_employee.container_start');
            $highest = config('temporary_employee.container_end');
            $system = config('temporary_employee.container_system');
        }
        else if ($type === "temporary") {
            $no = config('temporary_employee.temporary_employee_start');
            $highest = config('temporary_employee.temporary_employee_end');
            $system = config('temporary_employee.temporary_employee_system');
        }

        $employees = CustomerEmployee::where('customer_id', $this->id)
            ->where('type', $type)
            ->get();

        if(count($employees) > 0){
            $no_list = $employees
            // to make sure the regular employee do not get an employee number inside the container space
            ->lists('no')
            ->toArray();
            $missing = array_diff(range($no, max($no_list)), $no_list);

            switch ($system) {
                case 'empty':
                    $no = null;
                    break;
                case 'continuous':
                    $no = max($no_list)+1;
                    break;
                case 'lowest_available':
                    if($missing){
                        $no = min($missing);
                    }else{
                        $no = max($no_list)+1;
                    }
                    $no = ($highest && $no > $highest) ? '' : $no;
                    break;
                default:
                    if($missing){
                        $no = min($missing);
                    }else{
                        $no = max($no_list)+1;
                    }
                    break;
            }
        }

        return $no;
    }

    public function hasEmployees()
    {
        if(count($this->employees) > 0) {
            return true;
        }
        return false;
    }
    
    public function hasSubscriptions()
    {
        if(count($this->subscriptions) > 0) {
            return true;
        }
        return false;
    }

    public function isEnded() {
        if ($this->end_date && Carbon::parse($this->end_date)->lte(Carbon::today())) return true; 
        return false; 
    }
    
    public function isActive()
    {
        if ($this->end_date && Carbon::parse($this->end_date)->lte(Carbon::today())) return false; 

        if($this->hasSubscriptions()) {
            return true;
        }
        return false;
    }

    public function stop() 
    {
        if (!$this->end_date || Carbon::parse($this->end_date)->gt(Carbon::today())) return false; 
        $this->update(['active'=> 0]);

        //stop all subscriptions 
        $subscriptions = $this->subscriptions()->where(function($query) {
            // $query->whereNull('end_date')->orWhere('end_date','>=', Carbon::today());
        })->get(); 
        foreach ($subscriptions as $s) {
            $s->schedules()->update(['termination_date'=>$this->end_date, 'status'=>'INACTIVE']);
            $s->update(['termination_date'=>$this->end_date,'end_date'=>$this->end_date, 'status'=>'INACTIVE']);
        }      
        
        $this->subscriptionPackage()->update(['end_date'=>$this->end_date, 'active'=>0]);

        $this->deliveries()->whereRaw('delivery_date >= "'.$this->end_date.'"')->forceDelete(); 

    }
    
    public function invoice($invoice_period = [], $use_recent_dirty = 0, $invoice_date = null, $customers = [])
    {
        $invoice_date = ($invoice_date) ? $invoice_date : Carbon::now();
        $start_date = $invoice_period['start_date'];
        $end_date = $invoice_period['end_date'];
        
        DB::beginTransaction();
        $invoice = new Invoice;
        $invoice->setNextID();
        $invoice->invoice_date = $invoice_date;
        $invoice->start_date = to_human_date($start_date);
        $invoice->end_date = to_human_date($end_date);
        $invoice->customer_id = $this->id;
        $invoice->payment_method_id = $this->payment_method_id;
        $invoice->via_pbs = (bool)$this->subscribed_to_pbs;
        $invoice->use_tax_rate = (bool)$this->use_tax;
        $invoice->invoice_fee = $this->invoice_fee;
        $invoice->include_overview = $this->invoice_include_overview;
        $invoice->payee_id = $this->getPayeeID();
        $invoice->use_recent_dirty = $use_recent_dirty;
        $invoice->save();
        
        if($invoice){
            //Attach invoice settings and details
            $invoice->setDueDate();
            $invoice->addDefaultSettings();
            $invoice->addInvoiceDetails($customers);
        }
        
        DB::commit();

        return $invoice;
    }
    
    public function getPayeeID()
    {
        $payment_method_id = $this->payment_method_id;
        
        if($payment_method_id){
            return PaymentMethod::find($payment_method_id)->payee_id;
        }
        
        return null;
    }
    
    public function getSettingsValueFor($name)
    {
        $setting = Setting::get($name);
        if($setting){
            $customer_settings= $this->settings()->where('setting_id', $setting->id)->first();
            if($customer_settings){
                return $customer_settings->value;
            }else{
                return $setting->value();
            }
        }
        
        return null;
    }
    
    public function hasSettingsValueFor($name)
    {
        $setting = Setting::get($name);
        if($setting){
            $customer_settings= $this->settings()->where('setting_id', $setting->id)->first();
            if($customer_settings){
                return true;
            }
        }
        
        return false;
    }
    
    public function getAllRelatedCustomers($relatedCustomers = [])
    {
        if($this->relatedCustomers){
            foreach($this->relatedCustomers as $customer){
                array_push($relatedCustomers, $customer);
                
                if($customer->relatedCustomers->count() > 0){
                    $relatedCustomers = $customer->getAllRelatedCustomers($relatedCustomers);
                }
            }
        }

        return $relatedCustomers;
    }
    
    public function getHierarchyParent()
    {
        $parent = $this->parentCustomer;

        if($parent && $parent->parentCustomer != null){
            $parent = $parent->parentCustomer;
            $parent->getHierarchyParent();
        }
        
        return $parent;
    }
    
    public function addToSubscriptionImportAlertIgnoreList()
    {
        $filename = $this->subscription_import_ignore_list;
        $contents = [];

        if(AppStorage::has($filename)){
            $ignore_list = AppStorage::get($filename);
            if(!empty($ignore_list))
            $contents = explode(',',$ignore_list);
        }

        if(!in_array($this->id, $contents)){
            array_push($contents, $this->id);
            
            $file_saved = AppStorage::put($filename, implode(',', $contents));
            if(!$file_saved) 
                return false;
            else
                return true;
        }
        
        return true;
    }
    
    public function isCustomerOnSubscriptionIgnoreList(){
        $filename = $this->subscription_import_ignore_list;
        if(AppStorage::has($filename)){
            $contents = AppStorage::get($filename);
            return in_array($this->id,explode(',',$contents));
        }
        return false;
    }
    
    public function hasSubscriptionPackage()
    {
        if ($this->subscriptionPackage()->count() > 0) {
            return true;
        }
        return false;
    }
    
    public function viewLink()
    {
        if(Auth::user()->hasPermissions(["customers_list_view"], true)){
            return '<a href="'.route('customers.show', $this->id).'">'.$this->id.' - '.$this->dist_name.'</a>';
        }
        return $this->id.' - '.$this->dist_name;
    }

    public function statusUpdate() 
    {
        if ($this->end_date && Carbon::parse($this->end_date)->lte(Carbon::today())) {
            $this->stop(); 
        }
        else $this->update(['active'=> 1]);
    }

    public function getCustomerSetting($setting_name) 
    {
        $setting = Setting::get($setting_name);
        if($setting){
            $customer_settings= $this->settings()->where('setting_id', $setting->id)->first();
            if($customer_settings){

                return $customer_settings;
            }
        }
        
        return false;
    }
}