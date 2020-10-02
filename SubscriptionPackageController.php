<?php

namespace Avask\Http\Controllers\Subscription;

use Illuminate\Http\Request;

use Avask\Http\Requests;
use Avask\Http\Controllers\Controller;

use Avask\Models\Subscriptions\SubscriptionPackage;

class SubscriptionPackageController extends Controller
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
        
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
		$input = $request->all();
		$package = SubscriptionPackage::updateOrCreate(['customer_id' => $request->customer_id], $input);

		$response = [
            "status" => 200,
            "message" => "Added subscription package.",
            "alert_type" => "success",
            "data" => $package,
        ];
        
        return response()->json($response);
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
        //
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
        $package = SubscriptionPackage::find($id);
		$input = $request->all();
		$package->fill($input)->save();
        $response = [
            "status" => 200,
            "message" => "Updated subscription package.",
            "alert_type" => "success",
            "data" => $package,
        ];
        
        return response()->json($response);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
