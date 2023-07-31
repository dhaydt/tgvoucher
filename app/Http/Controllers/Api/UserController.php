<?php

namespace App\Http\Controllers\Api;

use App\CPU\Helpers;
use App\Customer;
use App\Expense;
use App\Http\Controllers\Controller;
use App\Payment;
use App\Payroll;
use App\Product_Sale;
use App\Product_Warehouse;
use App\Purchase;
use App\Quotation;
use App\ReturnPurchase;
use App\Returns;
use App\RewardPointSetting;
use App\Sale;
use App\User;
use App\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function updateFcm(Request $request){
        $request->validate([
            'fcm' => 'required'
        ]);

        $id = auth()->id();
        $user = User::find($id);
        $user->cm_firebase_token = $request->fcm;
        $user->save();
        
        return response()->json(['status' => 'success', 'message' => 'Firebase token updated successfully!']);
    }

    public function profile(Request $request)
    {
        $user = User::find(auth()->id());

        $user['warehouse_id'] = $user['warehouse_id'] ?? 1;

        $user['warehouse'] = Warehouse::find($user['warehouse_id']);

        $user['role'] = Role::find($user->role_id);

        return response()->json($user);
    }

    public function change_password(Request $request)
    {
        $request->validate([
            'password' => 'required',
            'confim_password' => 'required|same:password'
        ]);

        $user = User::find(auth()->id());
        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['status' => 'success', 'message' => 'Password berhasil diganti']);
    }

    public function home(Request $request)
    {
        if (auth()->user()->role_id == 5) {
            $customer = Customer::select('id', 'points')->where('user_id', auth()->id())->first();
            $lims_sale_data = Sale::with('warehouse')->where('customer_id', $customer->id)->orderBy('created_at', 'desc')->get();
            $lims_payment_data = DB::table('payments')
                ->join('sales', 'payments.sale_id', '=', 'sales.id')
                ->where('customer_id', $customer->id)
                ->select('payments.*', 'sales.reference_no as sale_reference')
                ->orderBy('payments.created_at', 'desc')
                ->get();
            $lims_quotation_data = Quotation::with('biller', 'customer', 'supplier', 'user')->orderBy('id', 'desc')->where('customer_id', $customer->id)->orderBy('created_at', 'desc')->get();

            $lims_return_data = Returns::with('warehouse', 'customer', 'biller')->where('customer_id', $customer->id)->orderBy('created_at', 'desc')->get();
            $lims_reward_point_setting_data = RewardPointSetting::select('per_point_amount')->latest()->first();
            $data = [
                'customer' => $customer,
                'lims_sale_data' => $lims_sale_data,
                'lims_payment_data' => $lims_payment_data,
                'lims_quotation_data' => $lims_quotation_data,
                'lims_quotation_data' => $lims_quotation_data,
                'lims_return_data' => $lims_return_data,
                'lims_reward_point_setting_data' => $lims_reward_point_setting_data,
            ];
            return response()->json($data);
        }
        $role = Role::with('permissions')->find(auth()->user()->role_id);
        // dd($role);
        $permissions = $role['permissions'];
        foreach ($permissions as $permission)
            $all_permission[] = $permission->name;
        if (empty($all_permission))
            $all_permission[] = 'dummy text';

        $start_date = date("Y") . '-' . date("m") . '-' . '01';

        $now = Carbon::now()->format('Y-m-d');

        $end_date = date("Y") . '-' . date("m") . '-' . date('t', mktime(0, 0, 0, date("m"), 1, date("Y")));
        $yearly_sale_amount = [];

        $general_setting = DB::table('general_settings')->latest()->first();
        // return $general_setting;
        if (auth()->user()->role_id > 2 && $general_setting->staff_access == 'own') {
            $product_sale_data = Sale::join('product_sales', 'sales.id', '=', 'product_sales.sale_id')
                ->select(DB::raw('product_sales.product_id, product_sales.product_batch_id, product_sales.sale_unit_id, sum(product_sales.qty) as sold_qty, sum(product_sales.total) as sold_amount'))
                ->where('sales.user_id', auth()->id())
                ->whereDate('product_sales.created_at', '=', $now)
                ->groupBy('product_sales.product_id', 'product_sales.product_batch_id')
                ->get();
            $product_cost = Helpers::calculateAverageCOGS($product_sale_data);
            // $revenue = Sale::whereDate('created_at', '>=', $start_date)->where('user_id', auth()->id())->whereDate('created_at', '<=', $end_date)->sum('grand_total');
            $revenue = Sale::whereDate('created_at', '=', $now)->where('user_id', auth()->id())->whereDate('created_at', '=', $now)->sum('grand_total');

            // $return = Returns::whereDate('created_at', '>=', $start_date)->where('user_id', auth()->id())->whereDate('created_at', '<=', $end_date)->sum('grand_total');
            $return = Returns::whereDate('created_at', '=', $now)->where('user_id', auth()->id())->whereDate('created_at', '=', $now)->sum('grand_total');
            $purchase_return = ReturnPurchase::whereDate('created_at', '=', $now)->where('user_id', auth()->id())->whereDate('created_at', '=', $now)->sum('grand_total');
            $revenue = $revenue - $return;
            $purchase = Purchase::whereDate('created_at', '=', $now)->where('user_id', auth()->id())->sum('grand_total');
            $profit = $revenue + $purchase_return - $product_cost;
            $expense = Expense::whereDate('created_at', '=', $now)->where('user_id', auth()->id())->whereDate('created_at', '=', $now)->sum('amount');
            $recent_sale = Sale::with('customer')->orderBy('id', 'desc')->where('user_id', auth()->id())->take(5)->get();
            $recent_purchase = Purchase::with('supplier')->orderBy('id', 'desc')->where('user_id', auth()->id())->take(5)->get();
            $recent_quotation = Quotation::with('customer')->orderBy('id', 'desc')->where('user_id', auth()->id())->take(5)->get();
            $recent_payment = Payment::orderBy('id', 'desc')->where('user_id', auth()->id())->take(5)->get();
        } else {
            $product_sale_data = Product_Sale::select(DB::raw('product_id, product_batch_id, sale_unit_id, sum(qty) as sold_qty, sum(total) as sold_amount'))->whereDate('created_at', '=', $now)->groupBy('product_id', 'product_batch_id')->get();
            $product_cost = Helpers::calculateAverageCOGS($product_sale_data);
            // $revenue = Sale::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->sum('grand_total');
            $revenue = Sale::whereDate('created_at', '=', $now)->whereDate('created_at', '=', $now)->sum('grand_total');

            // $return = Returns::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->sum('grand_total');
            $return = Returns::whereDate('created_at', '=', $now)->whereDate('created_at', '=', $now)->sum('grand_total');
            $purchase_return = ReturnPurchase::whereDate('created_at', '=', $now)->whereDate('created_at', '=', $now)->sum('grand_total');
            $revenue = $revenue - $return;
            $purchase = Purchase::whereDate('created_at', '=', $now)->sum('grand_total');
            $profit = $revenue + $purchase_return - $product_cost;
            $expense = Expense::whereDate('created_at', '=', $now)->whereDate('created_at', '=', $now)->sum('amount');
            $recent_sale = Sale::with('customer')->orderBy('id', 'desc')->take(5)->get();
            $recent_purchase = Purchase::with('supplier')->orderBy('id', 'desc')->take(5)->get();
            $recent_quotation = Quotation::with('customer')->orderBy('id', 'desc')->take(5)->get();
            $recent_payment = Payment::orderBy('id', 'desc')->take(5)->get();
        }

        $id_warehouse = auth()->user()->warehouse_id;

        if ($id_warehouse == null) {
            $best_selling_qty = Product_Sale::join('products', 'products.id', '=', 'product_sales.product_id')->join('sales', 'sales.id', '=', 'product_sales.sale_id')
                ->select(DB::raw('sales.warehouse_id as warehouse_id, products.id as product_id, products.name as product_name, products.code as product_code, products.image as product_images, sum(product_sales.qty) as sold_qty, sum(sales.grand_total) as sold_amount'))
                ->whereDate('product_sales.created_at', '>=', $start_date)
                ->whereDate('product_sales.created_at', '<=', $end_date)
                ->groupBy('products.code')
                ->orderBy('sold_qty', 'desc')
                ->take(10)
                ->get();
        } else {
            $best_selling_qty = Product_Sale::join('products', 'products.id', '=', 'product_sales.product_id')->join('sales', 'sales.id', '=', 'product_sales.sale_id')
                ->select(DB::raw('sales.warehouse_id as warehouse_id, products.id as product_id, products.name as product_name, products.code as product_code, products.image as product_images, sum(product_sales.qty) as sold_qty, sum(sales.grand_total) as sold_amount'))
                ->whereDate('product_sales.created_at', '>=', $start_date)
                ->whereDate('product_sales.created_at', '<=', $end_date)
                ->where('sales.warehouse_id', $id_warehouse)
                ->groupBy('products.code')
                ->orderBy('sold_qty', 'desc')
                ->take(10)
                ->get();
        }


        $auth = auth()->user();
        $warehouse_id = $auth->warehouse_id;

        foreach ($best_selling_qty as $bs) {
            $stock = Product_Warehouse::where(['product_id' => $bs['product_id'], 'warehouse_id' => $warehouse_id])->first();

            if ($stock) {
                $stock = $stock['qty'];
            } else {
                $stock = 0;
            }

            $bs['stock'] = $stock;
            $bs['product_images'] = config('app.url') . Helpers::imgUrl('product') . $bs['product_images'];
            // $bs['warehouse_id'] = $warehouse_id;
        }

        // return $best_selling_qty;

        if ($id_warehouse == null) {

            $yearly_best_selling_qty = Product_Sale::join('products', 'products.id', '=', 'product_sales.product_id')->join('sales', 'sales.id', '=', 'product_sales.sale_id')
                ->select(DB::raw('sales.warehouse_id as warehouse_id, products.id as product_id, products.name as product_name, products.code as product_code, products.image as product_images, sum(product_sales.qty) as sold_qty, sum(sales.grand_total) as sold_amount'))
                ->whereDate('product_sales.created_at', '>=', date("Y") . '-01-01')
                ->whereDate('product_sales.created_at', '<=', date("Y") . '-12-31')
                ->groupBy('products.code')
                ->orderBy('sold_qty', 'desc')
                ->take(5)
                ->get();
        } else {

            $yearly_best_selling_qty = Product_Sale::join('products', 'products.id', '=', 'product_sales.product_id')->join('sales', 'sales.id', '=', 'product_sales.sale_id')
                ->select(DB::raw('sales.warehouse_id as warehouse_id, products.id as product_id, products.name as product_name, products.code as product_code, products.image as product_images, sum(product_sales.qty) as sold_qty, sum(sales.grand_total) as sold_amount'))
                ->whereDate('product_sales.created_at', '>=', date("Y") . '-01-01')
                ->whereDate('product_sales.created_at', '<=', date("Y") . '-12-31')
                ->where('sales.warehouse_id', $id_warehouse)
                ->groupBy('products.code')
                ->orderBy('sold_qty', 'desc')
                ->take(5)
                ->get();
        }

        // return $yearly_best_selling_qty;



        foreach ($yearly_best_selling_qty as $by) {
            $stock = Product_Warehouse::where(['product_id' => $by['product_id'], 'warehouse_id' => $warehouse_id])->first();

            if ($stock) {
                $stock = $stock['qty'];
            } else {
                $stock = 0;
            }

            $by['stock'] = $stock;
            $by['product_images'] = config('app.url') . Helpers::imgUrl('product') . $by['product_images'];
        }
        // return $yearly_best_selling_qty;

        $yearly_best_selling_price = Product_Sale::join('products', 'products.id', '=', 'product_sales.product_id')
            ->select(DB::raw('products.name as product_name, products.code as product_code, products.image as product_images, sum(total) as total_price'))
            ->whereDate('product_sales.created_at', '>=', date("Y") . '-01-01')
            ->whereDate('product_sales.created_at', '<=', date("Y") . '-12-31')
            ->groupBy('products.code')
            ->orderBy('total_price', 'desc')
            ->take(5)
            ->get();

        //cash flow of last 6 months
        $start = strtotime(date('Y-m-01', strtotime('-6 month', strtotime(date('Y-m-d')))));
        $end = strtotime(date('Y-m-' . date('t', mktime(0, 0, 0, date("m"), 1, date("Y")))));

        while ($start < $end) {
            $start_date = date("Y-m", $start) . '-' . '01';
            $end_date = date("Y-m", $start) . '-' . date('t', mktime(0, 0, 0, date("m", $start), 1, date("Y", $start)));

            if (auth()->user()->role_id > 2 && $general_setting->staff_access == 'own') {
                $recieved_amount = DB::table('payments')->whereNotNull('sale_id')->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->where('user_id', auth()->id())->sum('amount');
                $sent_amount = DB::table('payments')->whereNotNull('purchase_id')->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->where('user_id', auth()->id())->sum('amount');
                $return_amount = Returns::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->where('user_id', auth()->id())->sum('grand_total');
                $purchase_return_amount = ReturnPurchase::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->where('user_id', auth()->id())->sum('grand_total');
                $expense_amount = Expense::whereDate('created_at', '=', $now)->whereDate('created_at', '=', $now)->where('user_id', auth()->id())->sum('amount');
                $payroll_amount = Payroll::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->where('user_id', auth()->id())->sum('amount');
            } else {
                $recieved_amount = DB::table('payments')->whereNotNull('sale_id')->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->sum('amount');
                $sent_amount = DB::table('payments')->whereNotNull('purchase_id')->whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->sum('amount');
                $return_amount = Returns::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->sum('grand_total');
                $purchase_return_amount = ReturnPurchase::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->sum('grand_total');
                $expense_amount = Expense::whereDate('created_at', '=', $now)->whereDate('created_at', '=', $now)->sum('amount');
                $payroll_amount = Payroll::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->sum('amount');
            }
            $sent_amount = $sent_amount + $return_amount + $expense_amount + $payroll_amount;

            $payment_recieved[] = number_format((float)($recieved_amount + $purchase_return_amount), 0, '.', '');
            $payment_sent[] = number_format((float)$sent_amount, 0, '.', '');
            $month[] = date("F", strtotime($start_date));
            $start = strtotime("+1 month", $start);
        }
        // yearly report
        $start = strtotime(date("Y") . '-01-01');
        $end = strtotime(date("Y") . '-12-31');
        while ($start < $end) {
            $start_date = date("Y") . '-' . date('m', $start) . '-' . '01';
            $end_date = date("Y") . '-' . date('m', $start) . '-' . date('t', mktime(0, 0, 0, date("m", $start), 1, date("Y", $start)));
            if (auth()->user()->role_id > 2 && $general_setting->staff_access == 'own') {
                $sale_amount = Sale::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->where('user_id', auth()->id())->sum('grand_total');
                $purchase_amount = Purchase::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->where('user_id', auth()->id())->sum('grand_total');
            } else {
                $sale_amount = Sale::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->sum('grand_total');
                $purchase_amount = Purchase::whereDate('created_at', '>=', $start_date)->whereDate('created_at', '<=', $end_date)->sum('grand_total');
            }
            $yearly_sale_amount[] = number_format((float)$sale_amount, 0, '.', '');
            $yearly_purchase_amount[] = number_format((float)$purchase_amount, 0, '.', '');
            $start = strtotime("+1 month", $start);
        }
        //making strict mode true for this query
        config()->set('database.connections.mysql.strict', true);
        DB::reconnect();

        $data = [
            'pendapatan' => $revenue,
            'profit' => $profit,
            'pengeluaran' => $expense,
            'pembelian' => $purchase,
            // 'return' => $return,
            // 'purchase_return' => $purchase_return,
            // 'payment_sent' => $payment_sent,
            // 'month' => $month,
            // 'yearly_sale_amount' => $yearly_sale_amount,
            // 'yearly_purchase_amount' => $yearly_purchase_amount,
            // 'yearly_purchase_amount' => $yearly_purchase_amount,
            // 'recent_sale' => $recent_sale,
            // 'recent_purchase' => $recent_purchase,
            // 'recent_quotation' => $recent_quotation,
            // 'recent_payment' => $recent_payment,
            'best_selling' => $best_selling_qty,
            'yearly_best_selling' => $yearly_best_selling_qty,
            // 'yearly_best_selling_price' => $yearly_best_selling_price,
            // 'all_permission' => $all_permission,
        ];
        return response()->json($data, 200);
    }
}
