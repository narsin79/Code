<?php

namespace Avask\Http\Controllers\Subscription;

use Illuminate\Http\Request;

use Avask\Http\Requests;
use Avask\Http\Controllers\Controller;
use Avask\Models\Subscriptions\SubscriptionTransaction;
use Avask\Models\Deliveries\Delivery;
use DB;
use Session;
use \Carbon\Carbon;

class SubscriptionTransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $transaction = SubscriptionTransaction::find($id);
        return view('subscriptions.transactions.edit', ['transaction' => $transaction]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //updating a subscription transaction is only applicable if the order is not yet processed
        //any changes to the subscription transaction should reflect the equivalent order

        $this->validate($request, [
            'subscription_schedule_id'  => 'required|integer|exists:subscription_schedules,id',
            'initial_delivery_amount'   => 'integer',
            'secondary_delivery_amount' => 'integer',
        ]);
        DB::beginTransaction();
        $input = $request->all();
        $transaction = SubscriptionTransaction::find($id);
        $subscription = $transaction->schedule->subscription; 
        
        $old_initial = $transaction->initial_delivery_amount;
        $old_secondary = $transaction->secondary_delivery_amount;
        $old_total = $transaction->totalTransactionAmount();

        if (!isset($input['secondary_delivery_amount'])) $input['secondary_delivery_amount'] = 0;

        $new_total = $input['initial_delivery_amount'] + $input['secondary_delivery_amount'];
        if ($transaction->order) {
          $amount_processed = $transaction->order->amountProcessed(); 
          if ($new_total < $amount_processed) {
            Session::flash('error_message', 'Cannot change quantity less than the amount processed.');
            return redirect()->back();
          }
        }


        $transaction->start_date = Carbon::createFromFormat('d/m Y', $input['start_date']);
        $transaction->end_date = (isset($input['end_date']) && $input['end_date']) ? Carbon::createFromFormat('d/m Y', $input['end_date']) : NULL;
        $transaction->initial_delivery_amount = $input['initial_delivery_amount'];
        $transaction->secondary_delivery_amount = $input['secondary_delivery_amount'];
        //update with the new information
        $transaction->save();

        $diff_initial = $old_initial - $input['initial_delivery_amount'];
        $diff_secondary = $old_secondary - $input['secondary_delivery_amount'];
        $diff_total = $old_total - ($input['initial_delivery_amount'] + $input['secondary_delivery_amount']);
        //update deliveries
        $stop_date = ($transaction->end_date) ? Carbon::createFromFormat('d/m Y', $transaction->end_date)->toDateString() : null; 
        $start_date = Carbon::createFromFormat('d/m Y H:i:s', $transaction->start_date)->toDateString(); 
        // if (strtotime($start_date) < strtotime('today')){
        //     $start_date = date('Y-m-d');
        // }
        if ($diff_initial > 0) $diff_initial = -1 * abs($diff_initial);// there was a reduction of the current subscription transaction amount
        else if ($diff_initial < 0) $diff_initial = abs($diff_initial);//this is an addition to the current subscription transaction amount

        if ($diff_secondary > 0) $diff_secondary = -1 * abs($diff_secondary);// there was a reduction of the current subscription transaction amount
        else if ($diff_secondary < 0) $diff_secondary = abs($diff_secondary);//this is an addition to the current subscription transaction amount   

        if ($diff_total > 0) $diff_total = -1 * abs($diff_total);// there was a reduction of the current subscription transaction amount
        else if ($diff_total < 0) $diff_total = abs($diff_total);//this is an addition to the current subscription transaction amount   

        $guess_order = Delivery::where('subscription_schedule_id','=', $transaction->schedule->id)
            ->where('delivery_date','<', $transaction->getOriginal()['start_date'])
            ->orderBy('delivery_date','desc')
            ->first();

        if($guess_order){
            $guess_order->num_clean += $diff_total;
            $guess_order->save();
        }

        //update all future deliveries
        $future_deliveries = Delivery::where('subscription_schedule_id','=', $transaction->schedule->id)
          ->where('delivery_date','>=', $transaction->getOriginal()['start_date'])
          ->get();
        
        foreach($future_deliveries as $delivery){            
            $delivery->delivery_amount += $diff_initial;
            $delivery->in_circulation += $diff_secondary;
            $delivery->total_qty += $diff_total;
            $delivery->total_price = ($delivery->item_base_price/100) * ($delivery->total_qty + $diff_total);
            $delivery->return_amount += $diff_initial;
            $delivery->save();
        }

        $total_order_amount = $input['initial_delivery_amount'] + $input['secondary_delivery_amount'];
        if ($total_order_amount == 0) {
          $transaction->delete(); 
          $transaction->order->delete(); 
          DB::commit(); 
          Session::flash('flash_message', 'Update successful.');
          return redirect()->route('subscriptions.edit', $subscription->id);
        }
        else{
          $transaction->order->update(['quantity'=>$total_order_amount]);
        }
        DB::commit(); 

		  Session::flash('flash_message', 'Update successful.');
		  return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $transaction = SubscriptionTransaction::findOrFail($id);
        if ($transaction) {
            //reduce deliveries
            $guess_order = Delivery::where('subscription_schedule_id','=', $transaction->schedule->id)
            ->where('delivery_date','<', $transaction->getOriginal()['start_date'])
            ->orderBy('delivery_date','desc')
            ->first();

            if($guess_order){
                $guess_order->num_clean -= $transaction->totalTransactionAmount();
                $guess_order->save();
            }

            //update all future deliveries
            $future_deliveries = Delivery::where('subscription_schedule_id','=', $transaction->schedule->id)
              ->where('delivery_date','>=', $transaction->getOriginal()['start_date'])
              ->get();
            
            foreach($future_deliveries as $delivery){
                
                $delivery->delivery_amount -= $transaction->initial_delivery_amount;
                $delivery->in_circulation -= $transaction->secondary_delivery_amount;
                $delivery->total_qty -= $transaction->totalTransactionAmount();
                $delivery->total_price = ($delivery->item_base_price/100) * ($delivery->total_qty - $transaction->totalTransactionAmount());
                $delivery->return_amount -= $transaction->totalTransactionAmount();
                $delivery->save();
            }

            //delete associated orders
            $transaction->order->delete(); 
            $transaction->delete();
        }           

        return response()->json(['message'=>'Transaction successfully deleted!', 'result'=>true]);
        // Session::flash('flash_message', 'Transaction successfully deleted!');
        // return redirect()->route('tasks.index');
    }
    
    public function ajax(Request $request)
    {
        $transaction_id = $request->transaction_id;
        
        if($transaction_id){
            DB::beginTransaction();
                $transaction = SubscriptionTransaction::findOrFail($transaction_id);
                switch ($request->action) {
                    case 'delete':
                        $transaction->delete();
                        break;
                }
            DB::commit();
        }

        return array('result'=>true,'message'=>"Successfully ".$request->action."d transaction record.");
    }
}
