<?php

namespace App\Http\Controllers\Api;

use App\CPU\Helpers;
use App\CustomerGroup;
use App\Expense;
use App\ExpenseCategory;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ReturnController;
use App\Payroll;
use App\Product;
use App\Product_Sale;
use App\Product_Warehouse;
use App\ProductPurchase;
use App\ProductReturn;
use App\ProductVariant;
use App\Purchase;
use App\PurchaseProductReturn;
use App\ReturnPurchase;
use App\Returns;
use App\Sale;
use App\Supplier;
use App\User;
use App\Variant;
use App\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function profitLossReport(Request $request){

        $start_date = $request->start_date ?? date('Y') . '-' . date('m') . '-01';
        $end_date = $request->end_date ?? date('Y-m-d');
        $warehouse_id = $request->warehouse_id;
        
        $query1 = array(
            'SUM(grand_total) AS grand_total',
            'SUM(paid_amount) AS paid_amount',
            'SUM(total_tax + order_tax) AS tax',
            'SUM(total_discount + order_discount) AS discount'
        );
        $query2 = array(
            'SUM(grand_total) AS grand_total',
            'SUM(total_tax + order_tax) AS tax'
        );
        config()->set('database.connections.mysql.strict', false);
        DB::reconnect();

        if($warehouse_id !== null){
            $product_sale_data = Product_Sale::join('sales', 'product_sales.sale_id', '=', 'sales.id')
                                ->select(DB::raw('product_id, product_batch_id, sale_unit_id, sum(qty) as sold_qty, sum(total) as sold_amount, sales.warehouse_id'))
                                ->whereDate('product_sales.created_at', '>=' , $start_date)
                                ->whereDate('product_sales.created_at', '<=' , $end_date)
                                ->where('sales.warehouse_id', '=' , $warehouse_id)
                                ->groupBy('product_sales.product_id', 'product_batch_id')
                                ->get();
        }else{
            $product_sale_data = Product_Sale::select(DB::raw('product_id, product_batch_id, sale_unit_id, sum(qty) as sold_qty, sum(total) as sold_amount'))
                            ->whereDate('created_at', '>=' , $start_date)
                            ->whereDate('created_at', '<=' , $end_date)
                            ->groupBy('product_id', 'product_batch_id')
                            ->get();
        }
        // return [$product_sale_data, $warehouse_id];
        config()->set('database.connections.mysql.strict', true);
            DB::reconnect();
        $data = Helpers::calculateAverageCOGSprofitLoss($product_sale_data);
        $product_cost = $data[0];
        $product_tax = $data[1];
        /*$product_revenue = 0;
        $product_cost = 0;
        $product_tax = 0;
        $profit = 0;
        foreach ($product_sale_data as $key => $product_sale) {
            if($product_sale->product_batch_id)
                $product_purchase_data = ProductPurchase::where([
                    ['product_id', $product_sale->product_id],
                    ['product_batch_id', $product_sale->product_batch_id]
                ])->get();
            else
                $product_purchase_data = ProductPurchase::where('product_id', $product_sale->product_id)->get();

            $purchased_qty = 0;
            $purchased_amount = 0;
            $purchased_tax = 0;
            $sold_qty = $product_sale->sold_qty;
            $product_revenue += $product_sale->sold_amount;
            foreach ($product_purchase_data as $key => $product_purchase) {
                $purchased_qty += $product_purchase->qty;
                $purchased_amount += $product_purchase->total;
                $purchased_tax += $product_purchase->tax;
                if($purchased_qty >= $sold_qty) {
                    $qty_diff = $purchased_qty - $sold_qty;
                    $unit_cost = $product_purchase->total / $product_purchase->qty;
                    $unit_tax = $product_purchase->tax / $product_purchase->qty;
                    $purchased_amount -= ($qty_diff * $unit_cost);
                    $purchased_tax -= ($qty_diff * $unit_tax);
                    break;
                }
            }
            $product_cost += $purchased_amount;
            $product_tax += $purchased_tax;
        }*/
        if($warehouse_id !== null){
            $purchase = Purchase::where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query1))->get();
            $total_purchase = Purchase::where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $sale = Sale::where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query1))->get();
            $total_sale = Sale::where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $return = Returns::where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query2))->get();
            $total_return = Returns::where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $purchase_return = ReturnPurchase::where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query2))->get();
            $total_purchase_return = ReturnPurchase::where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $expense = Expense::where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->sum('amount');
            $total_expense = Expense::where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $payroll = Payroll::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->sum('amount');
            $total_payroll = Payroll::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $total_item = DB::table('product_warehouse')
                        ->join('products', 'product_warehouse.product_id', '=', 'products.id')
                        ->where([
                            ['products.is_active', true],
                            ['product_warehouse.qty', '>' , 0]
                        ])->count();
        }else{
            $purchase = Purchase::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query1))->get();
            $total_purchase = Purchase::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $sale = Sale::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query1))->get();
            $total_sale = Sale::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $return = Returns::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query2))->get();
            $total_return = Returns::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $purchase_return = ReturnPurchase::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query2))->get();
            $total_purchase_return = ReturnPurchase::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $expense = Expense::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->sum('amount');
            $total_expense = Expense::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $payroll = Payroll::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->sum('amount');
            $total_payroll = Payroll::whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->count();
            $total_item = DB::table('product_warehouse')
                        ->join('products', 'product_warehouse.product_id', '=', 'products.id')
                        ->where([
                            ['products.is_active', true],
                            ['product_warehouse.qty', '>' , 0]
                        ])->count();
        }
        $payment_recieved_number = DB::table('payments')->whereNotNull('sale_id')->whereDate('created_at', '>=' , $start_date)
            ->whereDate('created_at', '<=' , $end_date)->count();
        $payment_recieved = DB::table('payments')->whereNotNull('sale_id')->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->sum('payments.amount');
        $credit_card_payment_sale = DB::table('payments')
                            ->where('paying_method', 'Credit Card')
                            ->whereNotNull('payments.sale_id')
                            ->whereDate('payments.created_at', '>=' , $start_date)
                            ->whereDate('payments.created_at', '<=' , $end_date)->sum('payments.amount');
        $cheque_payment_sale = DB::table('payments')
                            ->where('paying_method', 'Cheque')
                            ->whereNotNull('payments.sale_id')
                            ->whereDate('payments.created_at', '>=' , $start_date)
                            ->whereDate('payments.created_at', '<=' , $end_date)->sum('payments.amount');
        $gift_card_payment_sale = DB::table('payments')
                            ->where('paying_method', 'Gift Card')
                            ->whereNotNull('sale_id')
                            ->whereDate('created_at', '>=' , $start_date)
                            ->whereDate('created_at', '<=' , $end_date)
                            ->sum('amount');
        $paypal_payment_sale = DB::table('payments')
                            ->where('paying_method', 'Paypal')
                            ->whereNotNull('sale_id')
                            ->whereDate('created_at', '>=' , $start_date)
                            ->whereDate('created_at', '<=' , $end_date)
                            ->sum('amount');
        $deposit_payment_sale = DB::table('payments')
                            ->where('paying_method', 'Deposit')
                            ->whereNotNull('sale_id')
                            ->whereDate('created_at', '>=' , $start_date)
                            ->whereDate('created_at', '<=' , $end_date)
                            ->sum('amount');
        $cash_payment_sale =  $payment_recieved - $credit_card_payment_sale - $cheque_payment_sale - $gift_card_payment_sale - $paypal_payment_sale - $deposit_payment_sale;
        $payment_sent_number = DB::table('payments')->whereNotNull('purchase_id')->whereDate('created_at', '>=' , $start_date)
            ->whereDate('created_at', '<=' , $end_date)->count();
        $payment_sent = DB::table('payments')->whereNotNull('purchase_id')->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->sum('payments.amount');
        $credit_card_payment_purchase = DB::table('payments')
                            ->where('paying_method', 'Gift Card')
                            ->whereNotNull('payments.purchase_id')
                            ->whereDate('payments.created_at', '>=' , $start_date)
                            ->whereDate('payments.created_at', '<=' , $end_date)->sum('payments.amount');
        $cheque_payment_purchase = DB::table('payments')
                            ->where('paying_method', 'Cheque')
                            ->whereNotNull('payments.purchase_id')
                            ->whereDate('payments.created_at', '>=' , $start_date)
                            ->whereDate('payments.created_at', '<=' , $end_date)->sum('payments.amount');
        $cash_payment_purchase =  $payment_sent - $credit_card_payment_purchase - $cheque_payment_purchase;
        $lims_warehouse_all = Warehouse::where('is_active',true)->get();
        $warehouse_name = [];
        foreach ($lims_warehouse_all as $warehouse) {
            $warehouse_name[] = $warehouse->name;
            $warehouse_sale[] = Sale::where('warehouse_id', $warehouse->id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query2))->get();
            $warehouse_purchase[] = Purchase::where('warehouse_id', $warehouse->id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query2))->get();
            $warehouse_return[] = Returns::where('warehouse_id', $warehouse->id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query2))->get();
            $warehouse_purchase_return[] = ReturnPurchase::where('warehouse_id', $warehouse->id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->selectRaw(implode(',', $query2))->get();
            $warehouse_expense[] = Expense::where('warehouse_id', $warehouse->id)->whereDate('created_at', '>=' , $start_date)->whereDate('created_at', '<=' , $end_date)->sum('amount');
        }

        $return = [
            'warehouse_id' => $warehouse_id,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'pembelian' => ["grand_total" => round($product_cost, 2), "tax" => $product_tax],
            'penjualan' => $sale,
            'return_pembelian' => $purchase_return,
            'return_penjualan' => $return,
            'pengeluaran' => $expense,
            'gaji' => $payroll,
        ];

        return response()->json($return);

        return view('backend.report.profit_loss', compact('purchase', 'product_cost', 'product_tax', 'total_purchase', 'sale', 'total_sale', 'return', 'purchase_return', 'total_return', 'total_purchase_return', 'expense', 'payroll', 'total_expense', 'total_payroll', 'payment_recieved', 'payment_recieved_number', 'cash_payment_sale', 'cheque_payment_sale', 'credit_card_payment_sale', 'gift_card_payment_sale', 'paypal_payment_sale', 'deposit_payment_sale', 'payment_sent', 'payment_sent_number', 'cash_payment_purchase', 'cheque_payment_purchase', 'credit_card_payment_purchase', 'warehouse_name', 'warehouse_sale', 'warehouse_purchase', 'warehouse_return', 'warehouse_purchase_return', 'warehouse_expense', 'start_date', 'end_date'));
    }
    public function saleReturn(Request $request){
        $warehouse_id = $request->warehouse_id;
        $start_date = $request->start_date ?? now()->format('Y-m-d');
        $end_date = $request->end_date ?? now()->format('Y-m-d');

        if(auth()->user()->role_id > 2 && config('staff_access') == 'own'){
            $totalData = Returns::where('user_id', auth()->id())
                        ->whereDate('created_at', '>=' ,$start_date)
                        ->whereDate('created_at', '<=' ,$end_date)
                        ->count();
        }elseif($warehouse_id != 0){
            $totalData = Returns::where('warehouse_id', $warehouse_id)
                        ->whereDate('created_at', '>=' ,$start_date)
                        ->whereDate('created_at', '<=' ,$end_date)
                        ->count();
        }else{
            $totalData = Returns::whereDate('created_at', '>=' ,$start_date)
                        ->whereDate('created_at', '<=' ,$end_date)
                        ->count();
        }
        $totalFiltered = $totalData;
        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $start = $request->input('start');

        if(empty($request->input('search.value'))) {
            $q = Returns::with('biller', 'customer', 'warehouse', 'user')
                ->whereDate('created_at', '>=' ,$start_date)
                ->whereDate('created_at', '<=' ,$end_date)
                ->orderBy('created_at', 'desc');
            if(auth()->user()->role_id > 2 && config('staff_access') == 'own')
                $q = $q->where('user_id', auth()->id());
            elseif($warehouse_id != 0)
                $q = $q->where('warehouse_id', $warehouse_id);
            $returnss = $q->get();
        }
        else
        {
            $search = $request->input('search.value');
            $q = Returns::join('customers', 'returns.customer_id', '=', 'customers.id')
                ->join('billers', 'returns.biller_id', '=', 'billers.id')
                ->whereDate('returns.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))))
                ->offset($start)
                ->limit($limit)
                ->orderBy('created_at', 'desc');
            if(auth()->user()->role_id > 2 && config('staff_access') == 'own') {
                $returnss =  $q->select('returns.*')
                            ->with('biller', 'customer', 'warehouse', 'user')
                            ->where('returns.user_id', auth()->id())
                            ->orwhere([
                                ['returns.reference_no', 'LIKE', "%{$search}%"],
                                ['returns.user_id', auth()->id()]
                            ])
                            ->orwhere([
                                ['customers.name', 'LIKE', "%{$search}%"],
                                ['returns.user_id', auth()->id()]
                            ])
                            ->orwhere([
                                ['customers.phone_number', 'LIKE', "%{$search}%"],
                                ['returns.user_id', auth()->id()]
                            ])
                            ->orwhere([
                                ['billers.name', 'LIKE', "%{$search}%"],
                                ['returns.user_id', auth()->id()]
                            ])->get();

                $totalFiltered = $q->where('returns.user_id', auth()->id())
                                ->orwhere([
                                    ['returns.reference_no', 'LIKE', "%{$search}%"],
                                    ['returns.user_id', auth()->id()]
                                ])
                                ->orwhere([
                                    ['customers.name', 'LIKE', "%{$search}%"],
                                    ['returns.user_id', auth()->id()]
                                ])
                                ->orwhere([
                                    ['customers.phone_number', 'LIKE', "%{$search}%"],
                                    ['returns.user_id', auth()->id()]
                                ])
                                ->orwhere([
                                    ['billers.name', 'LIKE', "%{$search}%"],
                                    ['returns.user_id', auth()->id()]
                                ])
                                ->count();
            }
            else {
                $returnss =  $q->select('returns.*')
                            ->with('biller', 'customer', 'warehouse', 'user')
                            ->orwhere('returns.reference_no', 'LIKE', "%{$search}%")
                            ->orwhere('customers.name', 'LIKE', "%{$search}%")
                            ->orwhere('customers.phone_number', 'LIKE', "%{$search}%")
                            ->orwhere('billers.name', 'LIKE', "%{$search}%")
                            ->get();

                $totalFiltered = $q->orwhere('returns.reference_no', 'LIKE', "%{$search}%")
                                ->orwhere('customers.name', 'LIKE', "%{$search}%")
                                ->orwhere('customers.phone_number', 'LIKE', "%{$search}%")
                                ->orwhere('billers.name', 'LIKE', "%{$search}%")
                                ->count();
            }
        }
        $data = array();
        if(!empty($returnss))
        {
            foreach ($returnss as $key=>$returns)
            {
                // return $returns;
                $nestedData['id'] = $returns->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($returns->created_at->toDateString()));
                $nestedData['reference_no'] = $returns->reference_no;
                if($returns->sale_id) {
                    $sale_data = Sale::select('reference_no')->find($returns->sale_id);
                    $nestedData['sale_reference'] = $sale_data->reference_no;
                }
                else
                    $nestedData['sale_reference'] = 'N/A';
                $nestedData['warehouse'] = $returns->warehouse->name;
                $nestedData['biller'] = $returns->biller->name;
                $nestedData['customer'] = $returns->customer->name;
                $nestedData['grand_total'] = number_format($returns->grand_total);
                $nestedData['return_date'] = Carbon::parse($returns->created_at)->format('d-m-Y H:i');

                // data for sale details by one click

                // $nestedData['return'] = array( '[ "'.date(config('date_format'), strtotime($returns->created_at->toDateString())).'"', ' "'.$returns->reference_no.'"', ' "'.$returns->warehouse->name.'"', ' "'.$returns->biller->name.'"', ' "'.$returns->biller->company_name.'"', ' "'.$returns->biller->email.'"', ' "'.$returns->biller->phone_number.'"', ' "'.$returns->biller->address.'"', ' "'.$returns->biller->city.'"', ' "'.$returns->customer->name.'"', ' "'.$returns->customer->phone_number.'"', ' "'.$returns->customer->address.'"', ' "'.$returns->customer->city.'"', ' "'.$returns->id.'"', ' "'.$returns->total_tax.'"', ' "'.$returns->total_discount.'"', ' "'.$returns->total_price.'"', ' "'.$returns->order_tax.'"', ' "'.$returns->order_tax_rate.'"', ' "'.$returns->grand_total.'"', ' "'.preg_replace('/[\n\r]/', "<br>", $returns->return_note).'"', ' "'.preg_replace('/[\n\r]/', "<br>", $returns->staff_note).'"', ' "'.$returns->user->name.'"', ' "'.$returns->user->email.'"', ' "'.$nestedData['sale_reference'].'"]'
                // );
                $product = ProductReturn::where('return_id', $returns->id)->first();
                $nestedData['product'] = [];
                if($product){
                    $nestedData['product'] = Helpers::productReturnData($returns->id);
                }
                // $nestedData['return'] = [
                    
                // ];
                $data[] = $nestedData;
            }
        }

        $newData = [];

        foreach($data as $d){
            // return $d;
            foreach($d['product'] as $p){
                $item = [
                    'product_name' => $p['product_name'],
                    'qty' => $p['qty'],
                    'total_price' => $p['price'],
                    'reference_no' => $d['reference_no'],
                    'warehouse' => $d['warehouse'],
                    'return_date' => $d['return_date'],
                    'biller' => $d['biller'],
                    'customer' => $d['customer'],
                    // 'grand_total' => $d['grand_total'],
                ];

                array_push($newData, $item);
            }
        }
        $json_data = array(
            "draw"            => intval($request->input('draw')),  
            "recordsTotal"    => intval($totalData),  
            "recordsFiltered" => intval($totalFiltered), 
            "data_return"     => $newData
        );
            
        return response()->json($json_data);
    }

    public function purchaseReturn(Request $request){
        $warehouse_id = $request->warehouse_id;
        $start_date = $request->start_date ?? now()->format('Y-m-d');
        $end_date = $request->end_date ?? now()->format('Y-m-d');
        $search = $request->supplier;
        
        $warehouse_id = $request->input('warehouse_id');

        if(auth()->user()->role_id > 2 && config('staff_access') == 'own')
            $totalData = ReturnPurchase::where('user_id', auth()->id())
                        ->whereDate('created_at', '>=' ,$start_date)
                        ->whereDate('created_at', '<=' ,$end_date)
                        ->count();
        elseif($warehouse_id != 0)
            $totalData = ReturnPurchase::where('warehouse_id', $warehouse_id)
                        ->whereDate('created_at', '>=' ,$start_date)
                        ->whereDate('created_at', '<=' ,$end_date)
                        ->count();
        else
            $totalData = ReturnPurchase::whereDate('created_at', '>=' ,$start_date)
                        ->whereDate('created_at', '<=' ,$end_date)
                        ->count();

        $totalFiltered = $totalData;
        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        $dir = $request->input('order.0.dir');
        if(empty($request->supplier)) {
            $q = ReturnPurchase::with('supplier', 'warehouse', 'user')
                ->whereDate('created_at', '>=' ,$start_date)
                ->whereDate('created_at', '<=' ,$end_date)
                ->orderBy('created_at', 'desc');
            if(auth()->user()->role_id > 2 && config('staff_access') == 'own')
                $q = $q->where('user_id', auth()->id());
            elseif($warehouse_id != 0)
                $q = $q->where('warehouse_id', $warehouse_id);
            $returnss = $q->get();
        }
        else
        {
            $search = $request->supplier;
            $q = ReturnPurchase::leftJoin('suppliers', 'return_purchases.supplier_id', '=', 'suppliers.id')
                ->whereDate('return_purchases.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))))
                ->orderBy('created_at', 'desc');
            if(auth()->user()->role_id > 2 && config('staff_access') == 'own') {
                $returnss =  $q->select('return_purchases.*')
                            ->with('supplier', 'warehouse', 'user')
                            ->where('return_purchases.user_id', auth()->id())
                            ->orwhere([
                                ['return_purchases.reference_no', 'LIKE', "%{$search}%"],
                                ['return_purchases.user_id', auth()->id()]
                            ])
                            ->orwhere([
                                ['suppliers.name', 'LIKE', "%{$search}%"],
                                ['return_purchases.user_id', auth()->id()]
                            ])
                            ->get();

                $totalFiltered = $q->where('return_purchases.user_id', auth()->id())
                                ->orwhere([
                                    ['return_purchases.reference_no', 'LIKE', "%{$search}%"],
                                    ['return_purchases.user_id', auth()->id()]
                                ])
                                ->orwhere([
                                    ['suppliers.name', 'LIKE', "%{$search}%"],
                                    ['return_purchases.user_id', auth()->id()]
                                ])
                                ->count();
            }
            else {
                $returnss =  $q->select('return_purchases.*')
                            ->with('supplier', 'warehouse', 'user')
                            ->orwhere('return_purchases.reference_no', 'LIKE', "%{$search}%")
                            ->orwhere('suppliers.name', 'LIKE', "%{$search}%")
                            ->get();

                $totalFiltered = $q->orwhere('return_purchases.reference_no', 'LIKE', "%{$search}%")
                                ->orwhere('suppliers.name', 'LIKE', "%{$search}%")
                                ->count();
            }
        }
        $data = array();
        if(!empty($returnss))
        {
            foreach ($returnss as $key=>$returns)
            {
                $nestedData['id'] = $returns->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($returns->created_at->toDateString()));
                $nestedData['reference_no'] = $returns->reference_no;
                $nestedData['warehouse'] = $returns->warehouse->name;
                if($returns->purchase_id) {
                    $purchase_data = Purchase::select('reference_no')->find($returns->purchase_id);
                    $nestedData['purchase_reference'] = $purchase_data->reference_no;
                }
                else
                    $nestedData['purchase_reference'] = 'N/A';
                if($returns->supplier){
                    $supplier = $returns->supplier;
                    $nestedData['supplier'] = $returns->supplier->name;
                }
                else {
                    $supplier = new Supplier();
                    $nestedData['supplier'] = 'N/A';
                }
                $nestedData['grand_total'] = number_format($returns->grand_total, 0);
                $nestedData['return_date'] = Carbon::parse($returns->created_at)->format('d-m-Y H:i');
                // data for purchase details by one click
                $product = PurchaseProductReturn::where('return_id', $returns->id)->get();
                $nestedData['return'] = array( '[ "'.date(config('date_format'), strtotime($returns->created_at->toDateString())).'"', ' "'.$returns->reference_no.'"', ' "'.$returns->warehouse->name.'"', ' "'.$returns->warehouse->phone.'"', ' "'.$returns->warehouse->address.'"', ' "'.$supplier->name.'"', ' "'.$supplier->company_name.'"', ' "'.$supplier->email.'"', ' "'.$supplier->phone_number.'"', ' "'.$supplier->address.'"', ' "'.$supplier->city.'"', ' "'.$returns->id.'"', ' "'.$returns->total_tax.'"', ' "'.$returns->total_discount.'"', ' "'.$returns->total_cost.'"', ' "'.$returns->order_tax.'"', ' "'.$returns->order_tax_rate.'"', ' "'.$returns->grand_total.'"', ' "'.preg_replace('/[\n\r]/', "<br>", $returns->return_note).'"', ' "'.preg_replace('/[\n\r]/', "<br>", $returns->staff_note).'"', ' "'.$returns->user->name.'"', ' "'.$returns->user->email.'"', ' "'.$nestedData['purchase_reference'].'"]'
                );

                $nestedData['product'] = [];
                if($product){
                    $nestedData['product'] = Helpers::purchaseProductReturnData($returns->id);
                }
                $data[] = $nestedData;
            }
        }
        $newData = [];

        foreach($data as $d){
            // return $d;
            foreach($d['product'] as $p){
                $item = [
                    'product_name' => $p['product_name'],
                    'qty' => $p['qty'],
                    'total_price' => $p['price'],
                    'reference_no' => $d['reference_no'],
                    'warehouse' => $d['warehouse'],
                    'return_date' => $d['return_date'],
                    'supplier' => $d['supplier'],
                    // 'grand_total' => $d['grand_total'],
                ];

                array_push($newData, $item);
            }
        }

        $json_data = array(
            "draw"            => intval($request->input('draw')),  
            "recordsTotal"    => intval($totalData),  
            "recordsFiltered" => intval($totalFiltered), 
            "data"            => $newData
        );
            
        return response()->json($json_data);
    }

    public function customerDueReport(Request $request){
        $data = $request->all();
        $start_date = $data['start_date'] ?? date('Y') - 1 . '-'.date('m').'-'.date('d');
        $end_date = $data['end_date'] ?? date('Y') . '-'.date('m').'-'.date('d');

        $q = Sale::with('customer')->where('payment_status', '!=', 4)
            ->whereDate('created_at', '>=' , $start_date)
            ->whereDate('created_at', '<=' , $end_date);
        if($request->customer_id)
            $q = $q->where('customer_id', $request->customer_id);
        $lims_sale_data = $q->get();
        $data = [
            "recordsTotal" => count($lims_sale_data),
            "start_date" => $start_date,
            "end_date" => $end_date,
            "data" => []
        ];

        foreach($lims_sale_data as $d){
            // return $d;
            $item = [
                'due_date' => Carbon::parse($d['created_at'])->format('d-m-Y'),
                'reference_no' => $d['reference_no'],
                'customer' => $d['customer']['name'],
                'customer_group' => CustomerGroup::find($d['customer']['customer_group_id'])['name'] ?? 'Invalid Group Customer',
                'total' => $d['total_price'],
                'paid' => $d['paid_amount'],
                'warehouse_id' => $d['warehouse_id'],
                'due' => $d['total_price'] - $d['paid_amount'],
                'payment_status' => $d['payment_status'] == 4 ? 'Lunas' : 'Hutang'
            ];

            array_push($data['data'], $item);
        }
        return $data;
    }

    public function saleReport(Request $request)
    {
        $user = auth()->user();
        $data = $request->all();
        $start_date = $data['start_date'];
        $end_date = $data['end_date'];
        $warehouse_id = $data['warehouse_id'];

        if ($start_date == null) {
            $start_date = date('Y-m') . '-01';
        }
        if ($end_date == null) {
            $end_date = now()->format('Y-m-d');
        }

        $product_id = [];
        $variant_id = [];
        $product_name = [];
        $product_qty = [];
        $lims_product_all = Product::select('id', 'name', 'qty', 'is_variant')->where('is_active', true)->get();

        foreach ($lims_product_all as $product) {
            $lims_product_sale_data = null;
            $variant_id_all = [];
            if ($warehouse_id == 0) {
                if ($product->is_variant)
                    $variant_id_all = Product_Sale::distinct('variant_id')->where('product_id', $product->id)->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->pluck('variant_id');
                else
                    $lims_product_sale_data = Product_Sale::where('product_id', $product->id)->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->first();
            } else {
                if ($product->is_variant)
                    $variant_id_all = DB::table('sales')
                        ->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')
                        ->distinct('variant_id')
                        ->where([
                            ['product_sales.product_id', $product->id],
                            ['sales.warehouse_id', $warehouse_id]
                        ])->whereDate('sales.created_at', '>=', $start_date)
                        ->whereDate('sales.created_at', '<=', $end_date)
                        ->pluck('variant_id');
                else
                    $lims_product_sale_data = DB::table('sales')
                        ->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')->where([
                            ['product_sales.product_id', $product->id],
                            ['sales.warehouse_id', $warehouse_id]
                        ])->whereDate('sales.created_at', '>=', $start_date)
                        ->whereDate('sales.created_at', '<=', $end_date)
                        ->first();
            }
            if ($lims_product_sale_data) {
                $product_name[] = $product->name;
                $product_id[] = $product->id;
                $variant_id[] = null;
                if ($warehouse_id == 0)
                    $product_qty[] = $product->qty;
                else {
                    $product_qty[] = Product_Warehouse::where([
                        ['product_id', $product->id],
                        ['warehouse_id', $warehouse_id]
                    ])->sum('qty');
                }
            } elseif (count($variant_id_all)) {
                foreach ($variant_id_all as $key => $variantId) {
                    $variant_data = Variant::find($variantId);
                    $product_name[] = $product->name . ' [' . $variant_data->name . ']';
                    $product_id[] = $product->id;
                    $variant_id[] = $variant_data->id;
                    if ($warehouse_id == 0)
                        $product_qty[] = ProductVariant::FindExactProduct($product->id, $variant_data->id)->first()->qty;
                    else
                        $product_qty[] = Product_Warehouse::where([
                            ['product_id', $product->id],
                            ['variant_id', $variant_data->id],
                            ['warehouse_id', $warehouse_id]
                        ])->first()->qty;
                }
            }
        }
        $lims_warehouse_list = Warehouse::where('is_active', true)->get();
        $resp = [
            // 'product_id' => $product_id,
            // 'variant_id' => $variant_id,
            // 'product_name' => $product_name,
            // 'product_qty' => $product_qty,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'warehouse_id' => $warehouse_id,
            'total_amount' => '',
            'total_qty' => '',
            'total_in_stock' => '',
            'data_sale' => [],
            'lims_warehouse_list' => $lims_warehouse_list,
        ];

        if (!empty($product_name)) {
            $total_a = [];
            $total_q = [];
            $total_in = [];
            foreach ($product_id as $key => $pro_id) {
                if ($warehouse_id == 0) {
                    if ($variant_id[$key]) {
                        $sold_price = DB::table('product_sales')->where([
                            ['product_id', $pro_id],
                            ['variant_id', $variant_id[$key]]
                        ])->whereDate('created_at', '>=', $start_date)
                            ->whereDate('created_at', '<=', $end_date)
                            ->sum('total');

                        $product_sale_data = DB::table('product_sales')->where([
                            ['product_id', $pro_id],
                            ['variant_id', $variant_id[$key]]
                        ])->whereDate('created_at', '>=', $start_date)
                            ->whereDate('created_at', '<=', $end_date)
                            ->get();
                    } else {
                        $sold_price = DB::table('product_sales')->where('product_id', $pro_id)
                            ->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->sum('total');

                        $product_sale_data = DB::table('product_sales')->where('product_id', $pro_id)->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->get();
                    }
                } else {
                    if ($variant_id[$key]) {
                        $sold_price = DB::table('sales')
                            ->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')->where([
                                ['product_sales.product_id', $pro_id],
                                ['variant_id', $variant_id[$key]],
                                ['sales.warehouse_id', $warehouse_id]
                            ])->whereDate('sales.created_at', '>=', $start_date)->whereDate('sales.created_at', '<=', $end_date)->sum('total');
                        $product_sale_data = DB::table('sales')
                            ->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')->where([
                                ['product_sales.product_id', $pro_id],
                                ['variant_id', $variant_id[$key]],
                                ['sales.warehouse_id', $warehouse_id]
                            ])->whereDate('sales.created_at', '>=', $start_date)->whereDate('sales.created_at', '<=', $end_date)->get();
                    } else {
                        $sold_price = DB::table('sales')
                            ->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')->where([
                                ['product_sales.product_id', $pro_id],
                                ['sales.warehouse_id', $warehouse_id]
                            ])->whereDate('sales.created_at', '>=', $start_date)->whereDate('sales.created_at', '<=', $end_date)->sum('total');
                        $product_sale_data = DB::table('sales')
                            ->join('product_sales', 'sales.id', '=', 'product_sales.sale_id')->where([
                                ['product_sales.product_id', $pro_id],
                                ['sales.warehouse_id', $warehouse_id]
                            ])->whereDate('sales.created_at', '>=', $start_date)->whereDate('sales.created_at', '<=', $end_date)->get();
                    }
                }

                $sold_qty = 0;

                // return count($product_sale_data);

                foreach ($product_sale_data as $product_sale) {
                    $unit = DB::table('units')->find($product_sale->sale_unit_id);
                    if ($unit) {
                        if ($unit->operator == '*')
                            $sold_qty += $product_sale->qty * $unit->operation_value;
                        elseif ($unit->operator == '/')
                            $sold_qty += $product_sale->qty / $unit->operation_value;
                    } else
                        $sold_qty += $product_sale->qty;
                }

                $item = [
                    'product_id' => $product_id[$key],
                    'product_name' => $product_name[$key],
                    'sold_amount' => $sold_price,
                    'sold_qty' => $sold_qty,
                    'in_stock' => $product_qty[$key]
                ];
                // return $item;
                array_push($resp['data_sale'], $item);
                array_push($total_a, $sold_price);
                array_push($total_q, $sold_qty);
                array_push($total_in, $product_qty[$key]);
            }
            $resp['total_amount'] = array_sum($total_a);
            $resp['total_qty'] = array_sum($total_q);
            $resp['total_in_stock'] = array_sum($total_in);
        }

        return response()->json($resp);
        return view('backend.report.sale_report', compact('product_id', 'variant_id', 'product_name', 'product_qty', 'start_date', 'end_date', 'lims_warehouse_list', 'warehouse_id'));
    }

    public function expense_category(){
        $cat = ExpenseCategory::where('is_active', 1)->get();

        return response()->json($cat);
    }

    public function expenseReport(Request $request)
    {
        $user = auth()->user();
        $data = $request->all();
        $start_date = $data['start_date'];
        $end_date = $data['end_date'];
        $warehouse_id = $data['warehouse_id'];

        // if ($warehouse_id == null || $warehouse_id == 0) {
        //     $warehouse_id = 1;
        // }

        if ($start_date == null) {
            $start_date = date('Y-m') . '-01';
        }
        if ($end_date == null) {
            $end_date = now()->format('Y-m-d');
        }

        if($warehouse_id == 0 || $warehouse_id == null){
            $expenses = Expense::with('expenseCategory')->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->orderBy('created_at', 'desc')->get();
        }else{
            $expenses = Expense::with('expenseCategory')->where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->orderBy('created_at', 'desc')->get();
        }

        $resp = [
            'start_date' => $start_date,
            'end_date' => $end_date,
            'warehouse_id' => $warehouse_id,
            'data' => [],
        ];

        foreach ($expenses as $e) {
            $item = [
                'id' => $e['id'],
                'category_name' => $e['expenseCategory']['name'] ?? 'Invalid Category name',
                'reference_no' => $e['reference_no'],
                'amount' => $e['amount'],
                'warehouse_id' => $e['warehouse_id'],
                'note' => $e['note'],
            ];

            array_push($resp['data'], $item);
        }

        return response()->json($resp);
    }

    public function stockAlert(Request $request)
    {
        $warehouse_id = $request->warehouse_id;

        $alert = Product::where('is_active', 1)->get();

        $resp = ['warehouse_id' => $warehouse_id, 'data' => []];

        foreach ($alert as $a) {
            $item = [
                'id' => $a['id'],
                'name' => $a['name'],
                'qty' => $a['qty'],
                'warehouse_id' => $a['warehouse_id'],
            ];

            if ($a['alert_quantity'] > $a['qty']) {
                array_push($resp['data'], $item);
            }
        }

        // if ($warehouse_id == null) {
        //     $alert = Product::where('is_active', 1)->get();

        //     $resp = ['warehouse_id' => $warehouse_id, 'data' => []];
    
        //     foreach ($alert as $a) {
        //         $item = [
        //             'id' => $a['id'],
        //             'name' => $a['name'],
        //             'qty' => $a['qty'],
        //             'warehouse_id' => $a['warehouse_id'],
        //         ];

        //         if ($a['alert_quantity'] > $a['qty']) {
        //             array_push($resp['data'], $item);
        //         }
        //     }
        // } else {
        //     $alert = Product_Warehouse::with('product')->where('warehouse_id', $warehouse_id)->whereHas('product', function ($q) {
        //         $q->where('is_active', 1);
        //     })->get();
            
        //     $resp = ['warehouse_id' => $warehouse_id, 'data' => []];
    
        //     foreach ($alert as $a) {
        //         $item = [
        //             'id' => $a['product']['id'],
        //             'name' => $a['product']['name'],
        //             'qty' => $a['product']['qty'],
        //             'warehouse_id' => $a['warehouse_id'],
        //         ];
        //         if ($a['product']['alert_quantity'] > $a['qty']) {
        //             array_push($resp['data'], $item);
        //         }
        //     }
        // }



        return response()->json($resp);
    }

    public function OldPurchaseReport(Request $request)
    {
        $user = auth()->user();
        $data = $request->all();
        $start_date = $data['start_date'];
        $end_date = $data['end_date'];
        $warehouse_id = $data['warehouse_id'];

        if ($start_date == null) {
            $start_date = date('Y-m') . '-01';
        }
        if ($end_date == null) {
            $end_date = now()->format('Y-m-d');
        }

        $product_id = [];
        $variant_id = [];
        $product_name = [];
        $product_qty = [];
        $lims_product_all = Product::select('id', 'name', 'qty', 'is_variant')->where('is_active', true)->get();
        foreach ($lims_product_all as $product) {
            $lims_product_purchase_data = null;
            $variant_id_all = [];
            if ($warehouse_id == 0 || $warehouse_id ==  null) {
                if ($product->is_variant)
                    $variant_id_all = ProductPurchase::distinct('variant_id')->where('product_id', $product->id)->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->pluck('variant_id');
                else
                    $lims_product_purchase_data = ProductPurchase::where('product_id', $product->id)->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->first();
            } else {
                if ($product->is_variant)
                    $variant_id_all = DB::table('purchases')
                        ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')
                        ->distinct('variant_id')
                        ->where([
                            ['product_purchases.product_id', $product->id],
                            ['purchases.warehouse_id', $warehouse_id]
                        ])->whereDate('purchases.created_at', '>=', $start_date)
                        ->whereDate('purchases.created_at', '<=', $end_date)
                        ->pluck('variant_id');
                else
                    $lims_product_purchase_data = DB::table('purchases')
                        ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                            ['product_purchases.product_id', $product->id],
                            ['purchases.warehouse_id', $warehouse_id]
                        ])->whereDate('purchases.created_at', '>=', $start_date)
                        ->whereDate('purchases.created_at', '<=', $end_date)
                        ->first();
            }

            if ($lims_product_purchase_data) {
                $product_name[] = $product->name;
                $product_id[] = $product->id;
                $variant_id[] = null;
                if ($warehouse_id == 0)
                    $product_qty[] = $product->qty;
                else
                    $product_qty[] = Product_Warehouse::where([
                        ['product_id', $product->id],
                        ['warehouse_id', $warehouse_id]
                    ])->sum('qty');
            } elseif (count($variant_id_all)) {
                foreach ($variant_id_all as $key => $variantId) {
                    $variant_data = Variant::find($variantId);
                    $product_name[] = $product->name . ' [' . $variant_data->name . ']';
                    $product_id[] = $product->id;
                    $variant_id[] = $variant_data->id;
                    if ($warehouse_id == 0)
                        $product_qty[] = ProductVariant::FindExactProduct($product->id, $variant_data->id)->first()->qty;
                    else
                        $product_qty[] = Product_Warehouse::where([
                            ['product_id', $product->id],
                            ['variant_id', $variant_data->id],
                            ['warehouse_id', $warehouse_id]
                        ])->first()->qty;
                }
            }
        }

        $lims_warehouse_list = Warehouse::where('is_active', true)->get();


        $resp = [
            // 'product_id' => $product_id,
            // 'variant_id' => $variant_id,
            // 'product_name' => $product_name,
            // 'product_qty' => $product_qty,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'warehouse_id' => $warehouse_id,
            'purchased_amount' => '',
            'purchased_qty' => '',
            'total_in_stock' => '',
            'data_purchased' => [],
            'lims_warehouse_list' => $lims_warehouse_list,
        ];

        if (!empty($product_name)) {
            $total_a = [];
            $total_q = [];
            $total_in = [];
            foreach ($product_id as $key => $pro_id) {
                if ($warehouse_id == 0) {
                    if ($variant_id[$key]) {
                        $purchased_cost = DB::table('product_purchases')->where([
                            ['product_id', $pro_id],
                            ['variant_id', $variant_id[$key]]
                        ])->whereDate('created_at', '>=', $start_date)
                            ->whereDate('created_at', '<=', $end_date)
                            ->sum('total');

                        $product_purchase_data = DB::table('product_purchases')->where([
                            ['product_id', $pro_id],
                            ['variant_id', $variant_id[$key]]
                        ])->whereDate('created_at', '>=', $start_date)
                            ->whereDate('created_at', '<=', $end_date)
                            ->get();
                    } else {
                        $purchased_cost = DB::table('product_purchases')->where('product_id', $pro_id)->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->sum('total');

                        $product_purchase_data = DB::table('product_purchases')->where('product_id', $pro_id)->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->get();
                    }
                } else {
                    if ($variant_id[$key]) {
                        $purchased_cost = DB::table('purchases')
                            ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                                ['product_purchases.product_id', $pro_id],
                                ['product_purchases.variant_id', $variant_id[$key]],
                                ['purchases.warehouse_id', $warehouse_id]
                            ])->whereDate('purchases.created_at', '>=', $start_date)->whereDate('purchases.created_at', '<=', $end_date)->sum('total');
                        $product_purchase_data = DB::table('purchases')
                            ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                                ['product_purchases.product_id', $pro_id],
                                ['product_purchases.variant_id', $variant_id[$key]],
                                ['purchases.warehouse_id', $warehouse_id]
                            ])->whereDate('purchases.created_at', '>=', $start_date)->whereDate('purchases.created_at', '<=', $end_date)->get();
                    } else {
                        $purchased_cost = DB::table('purchases')
                            ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                                ['product_purchases.product_id', $pro_id],
                                ['purchases.warehouse_id', $warehouse_id]
                            ])->whereDate('purchases.created_at', '>=', $start_date)->whereDate('purchases.created_at', '<=', $end_date)->sum('total');
                        $product_purchase_data = DB::table('purchases')
                            ->join('product_purchases', 'purchases.id', '=', 'product_purchases.purchase_id')->where([
                                ['product_purchases.product_id', $pro_id],
                                ['purchases.warehouse_id', $warehouse_id]
                            ])->whereDate('purchases.created_at', '>=', $start_date)->whereDate('purchases.created_at', '<=', $end_date)->get();
                    }
                }

                $purchased_qty = 0;

                // return count($product_sale_data);

                foreach ($product_purchase_data as $product_purchase) {
                    $unit = DB::table('units')->find($product_purchase->purchase_unit_id);
                    if ($unit->operator == '*') {
                        $purchased_qty += $product_purchase->qty * $unit->operation_value;
                    } elseif ($unit->operator == '/') {
                        $purchased_qty += $product_purchase->qty / $unit->operation_value;
                    }
                }

                $item = [
                    'product_id' => $product_id[$key],
                    'product_name' => $product_name[$key],
                    'purchased_amount' => $purchased_cost,
                    'purchased_qty' => $purchased_qty,
                    'in_stock' => $product_qty[$key],
                ];
                // return $item;
                array_push($resp['data_purchased'], $item);
                array_push($total_a, $purchased_cost);
                array_push($total_q, $purchased_qty);
                array_push($total_in, $product_qty[$key]);
            }
            $resp['purchased_amount'] = array_sum($total_a);
            $resp['purchased_qty'] = array_sum($total_q);
            $resp['total_in_stock'] = array_sum($total_in);
        }

        return $resp;
        return view('backend.report.purchase_report', compact('product_id', 'variant_id', 'product_name', 'product_qty', 'start_date', 'end_date', 'lims_warehouse_list', 'warehouse_id'));
    }

    public function purchaseReport(Request $request){        
        $warehouse_id = $request->warehouse_id;
        // $purchase_status = $request->input('purchase_status');
        // $payment_status = $request->input('payment_status');
        $start_date = $request->start_date;
        $end_date = $request->end_date;
        if ($start_date == null) {
            $start_date = date('Y-m') . '-01';
        }
        if ($end_date == null) {
            $end_date = now()->format('Y-m-d');
        }
        $search = $request->supplier;

        $q = Purchase::whereDate('created_at', '>=' ,$start_date)->whereDate('created_at', '<=' ,$end_date);
        // return $q->get();
        if(auth()->user()->role_id > 2 && config('staff_access') == 'own')
            $q = $q->where('user_id', auth()->id());
        if($warehouse_id)
            $q = $q->where('warehouse_id', $warehouse_id);
        // if($purchase_status)
        //     $q = $q->where('status', $purchase_status);
        // if($payment_status)
        //     $q = $q->where('payment_status', $payment_status);

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        if(empty($search)) {
            $q = Purchase::with('supplier', 'warehouse')
                ->whereDate('created_at', '>=' ,$start_date)
                ->whereDate('created_at', '<=' ,$end_date)
                ->orderBy('created_at', 'desc');
            if(auth()->user()->role_id > 2 && config('staff_access') == 'own')
                $q = $q->where('user_id', auth()->id());
            if($warehouse_id)
                $q = $q->where('warehouse_id', $warehouse_id);
            // if($purchase_status)
            //     $q = $q->where('status', $purchase_status);
            // if($payment_status)
            //     $q = $q->where('payment_status', $payment_status);
            $purchases = $q->get();
        }
        else
        {
            $search = $request->supplier;
            $q = Purchase::leftJoin('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
                ->whereDate('purchases.created_at', '=' , date('Y-m-d', strtotime(str_replace('/', '-', $search))))
                ->orderBy('created_at', 'desc');
            if(auth()->user()->role_id > 2 && config('staff_access') == 'own') {
                $purchases =  $q->with('supplier', 'warehouse')
                                ->where('purchases.user_id', auth()->id())
                                ->orwhere([
                                    ['purchases.reference_no', 'LIKE', "%{$search}%"],
                                    ['purchases.user_id', auth()->id()]
                                ])
                                ->orwhere([
                                    ['suppliers.name', 'LIKE', "%{$search}%"],
                                    ['purchases.user_id', auth()->id()]
                                ])
                                ->select('purchases.*')
                                ->get();
                $totalFiltered =  $q->where('purchases.user_id', auth()->id())
                                    ->orwhere([
                                        ['purchases.reference_no', 'LIKE', "%{$search}%"],
                                        ['purchases.user_id', auth()->id()]
                                    ])
                                    ->orwhere([
                                        ['suppliers.name', 'LIKE', "%{$search}%"],
                                        ['purchases.user_id', auth()->id()]
                                    ])
                                    ->count();
            }
            else {
                $purchases =  $q->with('supplier', 'warehouse')
                                ->orwhere('purchases.reference_no', 'LIKE', "%{$search}%")
                                ->orwhere('suppliers.name', 'LIKE', "%{$search}%")
                                ->select('purchases.*')
                                ->get();
                $totalFiltered = $q->orwhere('purchases.reference_no', 'LIKE', "%{$search}%")
                                    ->orwhere('suppliers.name', 'LIKE', "%{$search}%")
                                    ->count();
            }
        }
        $data = array();
        if(!empty($purchases))
        {
            foreach ($purchases as $key=>$purchase)
            {
                $nestedData['id'] = $purchase->id;
                $nestedData['key'] = $key;
                $nestedData['date'] = date(config('date_format'), strtotime($purchase->created_at->toDateString()));
                $nestedData['reference_no'] = $purchase->reference_no;

                if($purchase->supplier_id) {
                    $supplier = $purchase->supplier;
                }
                else {
                    $supplier = new Supplier();
                }
                $nestedData['supplier'] = $supplier->name;
                // if($purchase->status == 1){
                //     $nestedData['purchase_status'] = '<div class="badge badge-success">'.trans('file.Recieved').'</div>';
                //     $purchase_status = trans('file.Recieved');
                // }
                // elseif($purchase->status == 2){
                //     $nestedData['purchase_status'] = '<div class="badge badge-success">'.trans('file.Partial').'</div>';
                //     $purchase_status = trans('file.Partial');
                // }
                // elseif($purchase->status == 3){
                //     $nestedData['purchase_status'] = '<div class="badge badge-danger">'.trans('file.Pending').'</div>';
                //     $purchase_status = trans('file.Pending');
                // }
                // else{
                //     $nestedData['purchase_status'] = '<div class="badge badge-danger">'.trans('file.Ordered').'</div>';
                //     $purchase_status = trans('file.Ordered');
                // }

                if($purchase->payment_status == 1)
                    $nestedData['payment_status'] = 'Belum Lunas';
                else
                    $nestedData['payment_status'] = 'Lunas';
                
                $nestedData['grand_total'] = number_format($purchase->grand_total, 0);
                $returned_amount = DB::table('return_purchases')->where('purchase_id', $purchase->id)->sum('grand_total');
                $nestedData['returned_amount'] = number_format($returned_amount, 0);
                $nestedData['paid_amount'] = number_format($purchase->paid_amount, 0);
                $nestedData['due'] = number_format($purchase->grand_total- $returned_amount  - $purchase->paid_amount, 0);
                

                // data for purchase details by one click
                $user = User::find($purchase->user_id);
                $warehouse = Warehouse::find($purchase->warehouse_id);
                $nestedData['warehouse'] = $warehouse['name'] ?? 'Invalid toko';
                $nestedData['date'] = $purchase->created_at;
                // return $purchase;
                // $nestedData['purchase'] = array( '[ "'.date(config('date_format'), strtotime($purchase->created_at->toDateString())).'"', ' "'.$purchase->reference_no.'"', ' "'.$purchase_status.'"',  ' "'.$purchase->id.'"', ' "'.$purchase->warehouse->name.'"', ' "'.$purchase->warehouse->phone.'"', ' "'.$purchase->warehouse->address.'"', ' "'.$supplier->name.'"', ' "'.$supplier->company_name.'"', ' "'.$supplier->email.'"', ' "'.$supplier->phone_number.'"', ' "'.$supplier->address.'"', ' "'.$supplier->city.'"', ' "'.$purchase->total_tax.'"', ' "'.$purchase->total_discount.'"', ' "'.$purchase->total_cost.'"', ' "'.$purchase->order_tax.'"', ' "'.$purchase->order_tax_rate.'"', ' "'.$purchase->order_discount.'"', ' "'.$purchase->shipping_cost.'"', ' "'.$purchase->grand_total.'"', ' "'.$purchase->paid_amount.'"', ' "'.preg_replace('/\s+/S', " ", $purchase->note).'"', ' "'.$user->name.'"', ' "'.$user->email.'"]'
                // );

                $product = Helpers::productPurchaseData($purchase->id);
                // return $product;
                $nestedData['product'] = $product;
                $data[] = $nestedData;
            }
        }
        $newData = [];
        foreach($data as $d){
            foreach($d['product'] as $p){
                $item = [
                    "purchased_date" => Carbon::parse($d['date'])->format('d-m-Y'),
                    "reference_no" => $d['reference_no'],
                    "product_name" => $p['product_name'],
                    "user" => $user['name'],
                    "purchased_qty" => $p['purchased_qty'],
                    "purchased_amount" => $p['purchased_amount'],
                    "supplier" => $d['supplier'],
                    "warehouse" => $d['warehouse'],
                    "payment_status" => $d['payment_status'],
                ];
                array_push($newData, $item);
            }
        }
        // return $newData;
        $json_data = array(
            "start_date"    => $start_date,  
            "end_date"    => $end_date,  
            "supplier"    => $search,  
            // "warehouse_id"    => $supplier,  
            "recordsTotal"    => count($newData),  
            "data_purchased"  => $newData
        );
        return response()->json($json_data);
    }

    public function stockReport(Request $request)
    {
        $user = auth()->user();
        $warehouse_id = $request->warehouse_id;

        if($warehouse_id == null){
            $product = Product::where('is_active', 1)->get();

            $resp = [
                'total_item' => count($product),
                'warehouse_id' => $warehouse_id,
                'stock' => []
            ];
    
            foreach ($product as $p) {
                $data = [];
                $data['product_id'] = $p['id'];
                $data['warehouse_id'] = null;
                $data['name'] = $p['name'];
                $data['qty'] = $p['qty'];
                $data['price'] = $p['price'] ?? Product::find($p['product_id'])['price'];
    
                array_push($resp['stock'], $data);
            }
    
            return $resp;

        }else{
            $product = Product_Warehouse::with('product')->whereHas('product', function ($q) {
                $q->where('is_active', 1);
            })->where('warehouse_id', $warehouse_id)->get();
        }


        $resp = [
            'total_item' => count($product),
            'warehouse_id' => $warehouse_id,
            'stock' => []
        ];

        foreach ($product as $p) {
            $data = [];
            $data['product_id'] = $p['product']['id'];
            $data['warehouse_id'] = $p['warehouse_id'];
            $data['name'] = $p['product']['name'];
            $data['qty'] = $p['qty'];
            $data['price'] = $p['price'] ?? Product::find($p['product_id'])['price'];

            array_push($resp['stock'], $data);
        }

        return $resp;
    }
}
