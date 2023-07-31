<?php

namespace App\Http\Controllers\Api;

use App\Account;
use App\Biller;
use App\CashRegister;
use App\Coupon;
use App\CPU\Helpers;
use App\Customer;
use App\Expense;
use App\GiftCard;
use App\Http\Controllers\Controller;
use App\Payment;
use App\PaymentWithCheque;
use App\PaymentWithCreditCard;
use App\PaymentWithGiftCard;
use App\PosSetting;
use App\Product;
use App\Product_Sale;
use App\Product_Warehouse;
use App\ProductBatch;
use App\ProductPurchase;
use App\ProductVariant;
use App\Purchase;
use App\RewardPointSetting;
use App\Sale;
use App\Supplier;
use App\Tax;
use App\Unit;
use App\User;
use App\Variant;
use App\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Stripe\Stripe;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $role = Role::with('permissions')->find(auth()->user()->role_id);

        if ($role->hasPermissionTo('sales-add')) {
            $lims_customer_list = Customer::where('is_active', true)->get();
            if (auth()->user()->role_id > 2) {
                $lims_warehouse_list = Warehouse::where([
                    ['is_active', true],
                    ['id', auth()->user()->warehouse_id]
                ])->get();
                $lims_biller_list = Biller::where([
                    ['is_active', true],
                    ['id', auth()->user()->biller_id]
                ])->get();
            } else {
                $lims_warehouse_list = Warehouse::where('is_active', true)->get();
                $lims_biller_list = Biller::where('is_active', true)->get();
            }

            $user = User::find(auth()->id());
            $user['warehouse_id'] = $user['warehouse_id'] ?? 1;

            $products = Product_Warehouse::with('product')->where('warehouse_id', $user['warehouse_id'])->get();
            $newProduct = [];

            foreach ($products as $p) {
                $item = $p['product'];
                if ($item['is_active'] == 1) {
                    $data['id'] = $item['id'];
                    $data['name'] = $item['name'];
                    $data['code'] = $item['code'];
                    $data['type'] = $item['type'];
                    $data['barcode_symbology'] = $item['barcode_symbology'];
                    $data['brand_id'] = $item['brand_id'];
                    $data['price'] = $item['price'];
                    $data['qty'] = $p['qty'];

                    array_push($newProduct, $data);
                }
            }


            $data = [
                "customer_list" => $lims_customer_list,
                "biller_list" => $lims_biller_list,
                "product_list" => $newProduct,
                // "warehouse_list" => $lims_warehouse_list,
                // "pos_setting_data" => $lims_pos_setting_data,
                // "tax_list" => $lims_tax_list,
                // "reward_point_setting_data" => $lims_reward_point_setting_data,
            ];

            return response()->json($data);
        } else {
            return redirect()->back()->with('not_permitted', 'Sorry! You are not allowed to access this module');
        }
    }

    public function addExpense(Request $request)
    {
        $this->validate($request, [
            'expense_category_id' => 'required',
            'warehouse_id' => 'required',
            'note' => 'required',
            'amount' => 'required',
        ]);

        $data = $request->all();

        $data['account_id'] = Account::where('is_default', 1)->first()['id'];

        $data['created_at'] = date("Y-m-d H:i:s");

        // expense_category_id: 1
        // warehouse_id: 1
        // amount: 200000
        // note: Pena 3

        $data['reference_no'] = 'er-' . date("Ymd") . '-' . date("his");

        $data['user_id'] = auth()->id();

        $cash_register_data = CashRegister::where([
            ['user_id', $data['user_id']],
            ['warehouse_id', $data['warehouse_id']],
            ['status', true]
        ])->first();

        if ($cash_register_data) {
            $data['cash_register_id'] = $cash_register_data->id;
        }

        Expense::create($data);

        return response()->json(['status' => 'success', 'message' => 'Pengeluaran berhasil ditambahkan!']);
    }

    public function addPurchase(Request $request)
    {
        $this->validate($request, [
            'warehouse_id' => 'required',
            'supplier_id' => 'required',
            'product_id' => 'required',
            'product_code' => 'required',
            'qty' => 'required',
            'net_unit_cost' => 'required',
            'subtotal' => 'required',
            'total_cost' => 'required',
            'item' => 'required',
        ]);

        $data = $request->all();
        // warehouse_id: 1
        // supplier_id: 1
        $data['status'] = 1;
        $data['document'] = null;
        $data['product_code_name'] = null;
        // qty[]: 1
        $data['recieved'] = [];
        $data['batch_no'] = [];
        $data['expired_date'] = [];
        // product_code[]: AX5GB
        // product_id[]: 2
        // purchase_unit[]: Pcs
        // net_unit_cost[]: 15000.00
        $data['discount'] = [];
        $data['tax_rate'] = [];
        $data['tax'] = [];
        // subtotal[]: 15000.00
        $data['imei_number'] = [];
        // qty[]: 1
        $data['batch_no'] = [];
        // product_code[]: AX3GB
        // product_id[]: 1
        $data['purchase_unit'] = [];
        // net_unit_cost[]: 13000.00
        // $data['discount']=[];
        // tax_rate[]: 0.00
        // tax[]: 0.00
        // subtotal[]: 13000.00
        // imei_number[]: 
        // total_qty: 2
        $data['total_discount'] = 0.00;
        $data['total_tax'] = 0.00;
        // total_cost: 28000.00
        // item: 2
        $data['order_tax'] = 0.0;
        $data['grand_total'] = $request->total_cost;
        $data['paid_amount'] = 0;
        $data['payment_status'] = 1;
        $data['order_tax_rate'] = 0;
        $data['order_discount'] = null;
        $data['shipping_cost'] = null;
        $data['note'] = null;

        //return dd($data);
        $data['user_id'] = auth()->id();
        $data['reference_no'] = 'pr-' . date("Ymd") . '-' . date("his");

        foreach ($request->product_id as $key => $p) {
            array_push($data['purchase_unit'], 'Pcs');
            array_push($data['tax_rate'], 0);
            array_push($data['discount'], 0);
            array_push($data['tax'], 0);
            array_push($data['imei_number'], null);
            array_push($data['batch_no'], null);
            array_push($data['recieved'], $request->qty[$key]);
        }

        // $document = $request->document;
        // if ($document) {
        //     $v = Validator::make(
        //         [
        //             'extension' => strtolower($request->document->getClientOriginalExtension()),
        //         ],
        //         [
        //             'extension' => 'in:jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt',
        //         ]
        //     );
        //     if ($v->fails())
        //         return redirect()->back()->withErrors($v->errors());

        //     $documentName = $document->getClientOriginalName();
        //     $document->move('public/documents/purchase', $documentName);
        //     $data['document'] = $documentName;
        // }
        if (isset($data['created_at']))
            $data['created_at'] = date("Y-m-d H:i:s", strtotime($data['created_at']));
        else
            $data['created_at'] = date("Y-m-d H:i:s");
        //return dd($data);
        $lims_purchase_data = Purchase::create($data);
        $product_id = $data['product_id'];
        $product_code = $data['product_code'];
        $qty = $data['qty'];
        $recieved = $data['recieved'];
        $batch_no = $data['batch_no'];
        $expired_date = $data['expired_date'];
        $purchase_unit = $data['purchase_unit'];
        $net_unit_cost = $data['net_unit_cost'];
        $discount = $data['discount'];
        $tax_rate = $data['tax_rate'];
        $tax = $data['tax'];
        $total = $data['subtotal'];
        $imei_numbers = $data['imei_number'];
        $product_purchase = [];

        foreach ($product_id as $i => $id) {
            $lims_purchase_unit_data  = Unit::where('unit_name', $purchase_unit[$i])->first();

            if ($lims_purchase_unit_data->operator == '*') {
                $quantity = $recieved[$i] * $lims_purchase_unit_data->operation_value;
            } else {
                $quantity = $recieved[$i] / $lims_purchase_unit_data->operation_value;
            }
            $lims_product_data = Product::find($id);

            //dealing with product barch
            if ($batch_no[$i]) {
                $product_batch_data = ProductBatch::where([
                    ['product_id', $lims_product_data->id],
                    ['batch_no', $batch_no[$i]]
                ])->first();
                if ($product_batch_data) {
                    $product_batch_data->expired_date = $expired_date[$i];
                    $product_batch_data->qty += $quantity;
                    $product_batch_data->save();
                } else {
                    $product_batch_data = ProductBatch::create([
                        'product_id' => $lims_product_data->id,
                        'batch_no' => $batch_no[$i],
                        'expired_date' => $expired_date[$i],
                        'qty' => $quantity
                    ]);
                }
                $product_purchase['product_batch_id'] = $product_batch_data->id;
            } else
                $product_purchase['product_batch_id'] = null;

            if ($lims_product_data->is_variant) {
                $lims_product_variant_data = ProductVariant::select('id', 'variant_id', 'qty')->FindExactProductWithCode($lims_product_data->id, $product_code[$i])->first();
                $lims_product_warehouse_data = Product_Warehouse::where([
                    ['product_id', $id],
                    ['variant_id', $lims_product_variant_data->variant_id],
                    ['warehouse_id', $data['warehouse_id']]
                ])->first();
                $product_purchase['variant_id'] = $lims_product_variant_data->variant_id;
                //add quantity to product variant table
                $lims_product_variant_data->qty += $quantity;
                $lims_product_variant_data->save();
            } else {
                $product_purchase['variant_id'] = null;
                if ($product_purchase['product_batch_id']) {
                    $lims_product_warehouse_data = Product_Warehouse::where([
                        ['product_id', $id],
                        ['product_batch_id', $product_purchase['product_batch_id']],
                        ['warehouse_id', $data['warehouse_id']],
                    ])->first();
                } else {
                    $lims_product_warehouse_data = Product_Warehouse::where([
                        ['product_id', $id],
                        ['warehouse_id', $data['warehouse_id']],
                    ])->first();
                }
            }
            //add quantity to product table
            $lims_product_data->qty = $lims_product_data->qty + $quantity;
            $lims_product_data->save();
            //add quantity to warehouse
            if ($lims_product_warehouse_data) {
                $lims_product_warehouse_data->qty = $lims_product_warehouse_data->qty + $quantity;
                $lims_product_warehouse_data->product_batch_id = $product_purchase['product_batch_id'];
            } else {
                $lims_product_warehouse_data = new Product_Warehouse();
                $lims_product_warehouse_data->product_id = $id;
                $lims_product_warehouse_data->product_batch_id = $product_purchase['product_batch_id'];
                $lims_product_warehouse_data->warehouse_id = $data['warehouse_id'];
                $lims_product_warehouse_data->qty = $quantity;
                if ($lims_product_data->is_variant)
                    $lims_product_warehouse_data->variant_id = $lims_product_variant_data->variant_id;
            }
            //added imei numbers to product_warehouse table
            if ($imei_numbers[$i]) {
                if ($lims_product_warehouse_data->imei_number)
                    $lims_product_warehouse_data->imei_number .= ',' . $imei_numbers[$i];
                else
                    $lims_product_warehouse_data->imei_number = $imei_numbers[$i];
            }
            $lims_product_warehouse_data->save();

            $product_purchase['purchase_id'] = $lims_purchase_data->id;
            $product_purchase['product_id'] = $id;
            $product_purchase['imei_number'] = $imei_numbers[$i];
            $product_purchase['qty'] = $qty[$i];
            $product_purchase['recieved'] = $recieved[$i];
            $product_purchase['purchase_unit_id'] = $lims_purchase_unit_data->id;
            $product_purchase['net_unit_cost'] = $net_unit_cost[$i];
            $product_purchase['discount'] = $discount[$i];
            $product_purchase['tax_rate'] = $tax_rate[$i];
            $product_purchase['tax'] = $tax[$i];
            $product_purchase['total'] = $total[$i];
            ProductPurchase::create($product_purchase);
        }

        $id_warehouse = auth()->user()->warehouse_id;

        $msg = [
            'title' => "Pembelian berhasil",
            'description' => 'Pembelian produk ke supplier ' . $lims_purchase_data['supplier']['name'] . ', nomor ref : ' . $data['reference_no'] . ' telah dilakukan.'
        ];
        
        Helpers::notifToAdmin($id_warehouse, $msg);

        return response()->json(['status' => 'success', 'message' => 'Pembelian berhasil ditambahkan!']);
    }

    public function transaction_list(Request $request)
    {
        $from = $request->from_date ?? now()->format('Y-m-d');
        $to = $request->to_date ?? now()->format('Y-m-d');

        $warehouse_id = $request->warehouse_id;

        $role = auth()->user()->role_id;

        // return $role;

        $query = Sale::with('user', 'customer', 'warehouse', 'biller');

        if($role == 1){
            if ($warehouse_id == null) {
                $query = $query->orderBy('created_at', 'desc')->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->get();
            } else {
                $query = $query->where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->orderBy('created_at', 'desc')->get();
            }
        }elseif($role == 2){

            $warehouse_id = auth()->user()->warehouse_id;

            $query = $query->where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->orderBy('created_at', 'desc')->get();

        }else{

            $warehouse_id = auth()->user()->warehouse_id;
            $query = $query->where('warehouse_id', $warehouse_id)->whereDate('created_at', '>=', $from)->whereDate('created_at', '<=', $to)->where('user_id', auth()->id())->orderBy('created_at', 'desc')->get();
        }

        $data = [
            'total_data' => count($query),
            'warehouse_id' => $warehouse_id,
            'from_date' => $from,
            'to_date' => $to,
            'data' => []
        ];

        foreach ($query as $q) {
            $payment = $q['payment_status'];
            if ($payment == 1) {
                $payment = 'pending';
            } elseif ($payment == 2) {
                $payment = 'due';
            } elseif ($payment == 3) {
                $payment = 'partial';
            } else {
                $payment = 'cash';
            }
            $item = [
                'transaction _id' => $q['id'],
                'reference_no' => $q['reference_no'],
                'cash_register_id' => $q['cash_register_id'],
                'item' => $q['item'],
                'total_qty' => $q['total_qty'],
                'total_price' => $q['total_price'],
                'grand_total' => $q['grand_total'],
                'payment_status' => $payment,
                'transaction_date' => Carbon::parse($q['created_at'])->format('d-m-Y H:i'),
                'seller' => [
                    'id' => $q['user']['id'],
                    'name' => $q['user']['name']
                ],
                'customer' => [
                    'id' => $q['customer']['id'],
                    'name' => $q['customer']['name']
                ],
                'warehouse' => [
                    'id' => $q['warehouse']['id'],
                    'name' => $q['warehouse']['name']
                ],
                'biller' => [
                    'id' => $q['biller']['id'],
                    'name' => $q['biller']['name']
                ],
            ];

            array_push($data['data'], $item);
        }
        return response()->json($data);
    }

    public function transaction_details($id)
    {
        $sale = Sale::with('product_sale', 'biller', 'customer', 'user', 'warehouse')->find($id);

        $payment = $sale['payment_status'];

        if ($payment == 1) {
            $payment = 'pending';
        } elseif ($payment == 2) {
            $payment = 'due';
        } elseif ($payment == 3) {
            $payment = 'partial';
        } else {
            $payment = 'cash';
        }

        foreach($sale['product_sale'] as $ps){
            $ps['product_name'] = Product::find($ps['product_id'])['name'];
        }

        $data = [
            'transaction _id' => $sale['id'],
            'reference_no' => $sale['reference_no'],
            'cash_register_id' => $sale['cash_register_id'],
            'item' => $sale['item'],
            'total_qty' => $sale['total_qty'],
            'total_price' => $sale['total_price'],
            'grand_total' => $sale['grand_total'],
            'payment_status' => $payment,
            'sale_note' => $sale['sale_note'],
            'staff_note' => $sale['staff_note'],
            'transaction_date' => Carbon::parse($sale['created_at'])->format('d-m-Y H:i'),
            'created_at' => $sale['created_at'],
            'seller' => [
                'id' => $sale['user']['id'],
                'name' => $sale['user']['name']
            ],
            'customer' => [
                'id' => $sale['customer']['id'],
                'name' => $sale['customer']['name']
            ],
            'warehouse' => [
                'id' => $sale['warehouse']['id'],
                'name' => $sale['warehouse']['name'],
                'address' => $sale['warehouse']['address'],
                'phone' => $sale['warehouse']['phone']
            ],
            'biller' => [
                'id' => $sale['biller']['id'],
                'name' => $sale['biller']['name']
            ],
            'product' => $sale['product_sale']
        ];

        return $data;
    }


    public function product_list(Request $request)
    {

        $columns = array(
            2 => 'name',
            3 => 'code',
            4 => 'brand_id',
            5 => 'category_id',
            6 => 'qty',
            7 => 'unit_id',
            8 => 'price',
            9 => 'cost',
            10 => 'stock_worth'
        );

        $totalData = Product::where('is_active', true)->count();
        $totalFiltered = $totalData;

        // if($request->input('length') != -1)
        //     $limit = $request->input('length');
        // else
        $limit = null;
        // $start = $request->input('start');
        // $order = 'products.'.$columns[$request->input('order.0.column')];
        // $dir = $request->input('order.0.dir');
        // if(empty($request->input('search.value'))){
        //     $products = Product::with('category', 'brand', 'unit')->offset($start)
        //                 ->where('is_active', true)
        //                 ->limit($limit)
        //                 ->orderBy($order,$dir)
        //                 ->get();
        // }
        // else
        // {
        // $search = $request->input('search.value'); 
        $products =  Product::select('products.*')
            ->with('category', 'brand', 'unit')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->leftjoin('brands', 'products.brand_id', '=', 'brands.id')
            ->where([
                // ['products.name', 'LIKE', "%{$search}%"],
                ['products.is_active', true]
            ])
            ->orWhere([
                // ['products.code', 'LIKE', "%{$search}%"],
                ['products.is_active', true]
            ])
            ->orWhere([
                // ['categories.name', 'LIKE', "%{$search}%"],
                ['categories.is_active', true],
                ['products.is_active', true]
            ])
            ->orWhere([
                // ['brands.title', 'LIKE', "%{$search}%"],
                ['brands.is_active', true],
                ['products.is_active', true]
            ])
            // ->offset($start)
            ->limit($limit)
            ->orderBy('created_at', 'desc')->get();

        $totalFiltered = Product::join('categories', 'products.category_id', '=', 'categories.id')
            ->leftjoin('brands', 'products.brand_id', '=', 'brands.id')
            ->where([
                // ['products.name','LIKE',"%{$search}%"],
                ['products.is_active', true]
            ])
            ->orWhere([
                // ['products.code', 'LIKE', "%{$search}%"],
                ['products.is_active', true]
            ])
            ->orWhere([
                // ['categories.name', 'LIKE', "%{$search}%"],
                ['categories.is_active', true],
                ['products.is_active', true]
            ])
            ->orWhere([
                // ['brands.title', 'LIKE', "%{$search}%"],
                ['brands.is_active', true],
                ['products.is_active', true]
            ])
            ->count();
        // }
        $data = array();
        if (!empty($products)) {
            foreach ($products as $key => $product) {
                $nestedData['id'] = $product->id;
                $nestedData['key'] = $key;
                $product_image = explode(",", $product->image);
                $product_image = htmlspecialchars($product_image[0]);
                $nestedData['image'] = url('public/images/product', $product_image);
                $nestedData['name'] = $product->name;
                $nestedData['code'] = $product->code;
                if ($product->brand_id)
                    $nestedData['brand'] = $product->brand->title;
                else
                    $nestedData['brand'] = "N/A";
                $nestedData['category'] = $product->category->name;
                if (auth()->user()->role_id > 2 && $product->type == 'standard') {
                    $nestedData['qty'] = Product_Warehouse::where([
                        ['product_id', $product->id],
                        ['warehouse_id', auth()->user()->warehouse_id]
                    ])->sum('qty');
                } else
                    $nestedData['qty'] = Product_Warehouse::where([
                        ['product_id', $product->id],
                        ['warehouse_id', 1]
                    ])->sum('qty');
                    // $nestedData['qty'] = $product->qty;

                if ($product->unit_id)
                    $nestedData['unit'] = $product->unit->unit_name;
                else
                    $nestedData['unit'] = 'N/A';

                $nestedData['price'] = $product->price;

                $warehouse_id = auth()->user()->warehouse_id ?? 1;
                if($warehouse_id){
                    if($product->is_diffPrice == 1){
                        $newProduct = Product_Warehouse::where(['product_id' => $product['id'], 'warehouse_id' => $warehouse_id])->first();
                        if($newProduct){
                            $nestedData['price'] = $newProduct->price;
                        }
                    }
                }

                $nestedData['cost'] = $product->cost;

                if (config('currency_position') == 'prefix')
                    $nestedData['stock_worth'] = config('currency') . ' ' . ($nestedData['qty'] * $product->price) . ' / ' . config('currency') . ' ' . ($nestedData['qty'] * $product->cost);
                else
                    $nestedData['stock_worth'] = ($nestedData['qty'] * $product->price) . ' ' . config('currency') . ' / ' . ($nestedData['qty'] * $product->cost) . ' ' . config('currency');

                // $nestedData['options'] = '<div class="btn-group">
                //             <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'.trans("file.action").'
                //               <span class="caret"></span>
                //               <span class="sr-only">Toggle Dropdown</span>
                //             </button>
                //             <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" user="menu">
                //             <li>
                //                 <button="type" class="btn btn-link view"><i class="fa fa-eye"></i> '.trans('file.View').'</button>
                //             </li>';

                // if(in_array("products-edit", $request['all_permission']))
                //     $nestedData['options'] .= '<li>
                //             <a href="'.route('products.edit', $product->id).'" class="btn btn-link"><i class="fa fa-edit"></i> '.trans('file.edit').'</a>
                //         </li>';
                // if(in_array("product_history", $request['all_permission']))
                //     $nestedData['options'] .= \Form::open(["route" => "products.history", "method" => "GET"] ).'
                //             <li>
                //                 <input type="hidden" name="product_id" value="'.$product->id.'" />
                //                 <button type="submit" class="btn btn-link"><i class="dripicons-checklist"></i> '.trans("file.Product History").'</button> 
                //             </li>'.\Form::close();
                // if(in_array("print_barcode", $request['all_permission'])) {
                //     $product_info = $product->code.' ('.$product->name.')';
                //     $nestedData['options'] .= \Form::open(["route" => "product.printBarcode", "method" => "GET"] ).'
                //         <li>
                //             <input type="hidden" name="data" value="'.$product_info.'" />
                //             <button type="submit" class="btn btn-link"><i class="dripicons-print"></i> '.trans("file.print_barcode").'</button> 
                //         </li>'.\Form::close(); 
                // }
                // if(in_array("products-delete", $request['all_permission']))
                //     $nestedData['options'] .= \Form::open(["route" => ["products.destroy", $product->id], "method" => "DELETE"] ).'
                //             <li>
                //               <button type="submit" class="btn btn-link" onclick="return confirmDelete()"><i class="fa fa-trash"></i> '.trans("file.delete").'</button> 
                //             </li>'.\Form::close().'
                //         </ul>
                //     </div>';
                // data for product details by one click
                if ($product->tax_id)
                    $tax = Tax::find($product->tax_id)->name;
                else
                    $tax = "N/A";

                if ($product->tax_method == 1)
                    $tax_method = trans('file.Exclusive');
                else
                    $tax_method = trans('file.Inclusive');

                $nestedData['product'] = array(
                    '[ "' . $product->type . '"', ' "' . $product->name . '"', ' "' . $product->code . '"', ' "' . $nestedData['brand'] . '"', ' "' . $nestedData['category'] . '"', ' "' . $nestedData['unit'] . '"', ' "' . $product->cost . '"', ' "' . $product->price . '"', ' "' . $tax . '"', ' "' . $tax_method . '"', ' "' . $product->alert_quantity . '"', ' "' . preg_replace('/\s+/S', " ", $product->product_details) . '"', ' "' . $product->id . '"', ' "' . $product->product_list . '"', ' "' . $product->variant_list . '"', ' "' . $product->qty_list . '"', ' "' . $product->price_list . '"', ' "' . $nestedData['qty'] . '"', ' "' . $product->image . '"', ' "' . $product->is_variant . '"]'
                );
                $nestedData['product'] = [
                    'type' => $product->type,
                    'name' => $product->name,
                    'code' => $product->code,
                    'brand' => $product->brand,
                    'category' => $product->category->name,
                    'unit' => $product->unit->unit_name,
                    'cost' => $product->cost,
                    'price' => $nestedData['price'],
                    // 'is_variant' => $product->is_variant
                ];
                //$nestedData['imagedata'] = DNS1D::getBarcodePNG($product->code, $product->barcode_symbology);
                $data[] = $nestedData;
            }
        }
        $json_data = array(
            // "draw"            => intval($request->input('draw')),  
            "recordsTotal"    => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "warehouse_id"      => auth()->user()->warehouse_id ?? 1,
            "data"            => $data
        );

        return response()->json($json_data);
        echo json_encode($json_data);
    }

    public function transaction_post(Request $request)
    {
        $this->validate($request, [
            'customer_id' => 'required',
            'product_id' => 'required',
            'product_code' => 'required',
            'qty' => 'required',
            'net_unit_price' => 'required',
            'subtotal' => 'required',
            'total_price' => 'required',
            'item' => 'required',
            'paid_amount' => 'required',
        ]);

        $data = $request->all();

        $data['product_batch_id'] = [];
        $data['warehouse_id'] = auth()->user()->warehouse_id ?? 1;
        if ($data['warehouse_id'] == 1) {
            $data['biller_id'] = PosSetting::OrderBy('created_at', 'desc')->first()['biller_id'];
        } else {
            $data['biller_id'] = auth()->user()->biller_id;
        }
        $data['product_code_name'] = null;
        $data['sale_unit'] = [];
        $data['discount'] = [];
        $data['tax_rate'] = [];
        $data['tax'] = [];
        $data['total_discount'] = 0.00;
        $data['total_tax'] = 0.00;
        $data['order_tax'] = 0.00;
        $data['grand_total'] = $request->total_price;
        $data['paid_amount'] = $request['paid_amount'];
        $data['used_points'] = null;
        $data['coupon_discount'] = null;
        $data['sale_status'] = 1;
        $data['coupon_active'] = null;
        $data['coupon_id'] = null;
        $data['pos'] = 1;
        $data['draft'] = 0;
        $data['paying_amount'] = $request->total_price;
        $data['paid_by_id'] = 1;
        $data['gift_card_id'] = null;
        $data['cheque_no'] = null;
        $data['payment_note'] = null;
        $data['order_discount_type'] = "Flat";
        $data['order_discount_value'] = null;
        $data['order_discount'] = 0;
        $data['order_tax_rate'] = 0;
        $data['shipping_cost'] = 0;
        $data['imei_number'] = [];

        foreach ($request->product_id as $pl) {
            array_push($data['product_batch_id'], null);
            array_push($data['imei_number'], null);
            array_push($data['sale_unit'], 'Pcs');
            array_push($data['discount'], 0.00);
            array_push($data['tax_rate'], 0.00);
            array_push($data['tax'], 0.00);
        }


        if (isset($request->reference_no)) {
            $this->validate($request, [
                'reference_no' => [
                    'max:191', 'required', 'unique:sales'
                ],
            ]);
        }

        $data['user_id'] = auth()->user()->id;
        $data['warehouse_id'] = auth()->user()->warehouse_id ?? 1;
        if ($data['warehouse_id'] == 1) {
            $data['biller_id'] = PosSetting::OrderBy('created_at', 'desc')->first()['biller_id'];
        } else {
            $data['biller_id'] = auth()->user()->biller_id;
        }
        $cash_register_data = CashRegister::where([
            ['user_id', $data['user_id']],
            ['warehouse_id', $data['warehouse_id']],
            ['status', true]
        ])->first();

        if ($cash_register_data)
            $data['cash_register_id'] = $cash_register_data->id;

        if (isset($data['created_at']))
            $data['created_at'] = date("Y-m-d H:i:s", strtotime($data['created_at']));
        else
            $data['created_at'] = date("Y-m-d H:i:s");

        if ($data['pos']) {
            if (!isset($data['reference_no']))
                $data['reference_no'] = 'posr-' . date("Ymd") . '-' . date("his");

            $balance = $data['grand_total'] - $data['paid_amount'];
            if ($balance > 0 || $balance < 0)
                $data['payment_status'] = 2;
            else
                $data['payment_status'] = 4;

            if ($data['draft']) {
                $lims_sale_data = Sale::find($data['sale_id']);
                $lims_product_sale_data = Product_Sale::where('sale_id', $data['sale_id'])->get();
                foreach ($lims_product_sale_data as $product_sale_data) {
                    $product_sale_data->delete();
                }
                $lims_sale_data->delete();
            }
        } else {
            if (!isset($data['reference_no']))
                $data['reference_no'] = 'sr-' . date("Ymd") . '-' . date("his");
        }

        $document = $request->document;
        if ($document) {
            $v = Validator::make(
                [
                    'extension' => strtolower($request->document->getClientOriginalExtension()),
                ],
                [
                    'extension' => 'in:jpg,jpeg,png,gif,pdf,csv,docx,xlsx,txt',
                ]
            );
            if ($v->fails())
                return redirect()->back()->withErrors($v->errors());

            $documentName = $document->getClientOriginalName();
            $document->move('public/sale/documents', $documentName);
            $data['document'] = $documentName;
        }
        if ($data['coupon_active']) {
            $lims_coupon_data = Coupon::find($data['coupon_id']);
            $lims_coupon_data->used += 1;
            $lims_coupon_data->save();
        }

        $lims_sale_data = Sale::create($data);
        $lims_customer_data = Customer::find($data['customer_id']);
        $lims_reward_point_setting_data = RewardPointSetting::latest()->first();
        //checking if customer gets some points or not
        if ($lims_reward_point_setting_data->is_active &&  $data['grand_total'] >= $lims_reward_point_setting_data->minimum_amount) {
            $point = (int)($data['grand_total'] / $lims_reward_point_setting_data->per_point_amount);
            $lims_customer_data->points += $point;
            $lims_customer_data->save();
        }

        //collecting male data
        $mail_data['email'] = $lims_customer_data->email;
        $mail_data['reference_no'] = $lims_sale_data->reference_no;
        $mail_data['sale_status'] = $lims_sale_data->sale_status;
        $mail_data['payment_status'] = $lims_sale_data->payment_status;
        $mail_data['total_qty'] = $lims_sale_data->total_qty;
        $mail_data['total_price'] = $lims_sale_data->total_price;
        $mail_data['order_tax'] = $lims_sale_data->order_tax;
        $mail_data['order_tax_rate'] = $lims_sale_data->order_tax_rate;
        $mail_data['order_discount'] = $lims_sale_data->order_discount;
        $mail_data['shipping_cost'] = $lims_sale_data->shipping_cost;
        $mail_data['grand_total'] = $lims_sale_data->grand_total;
        $mail_data['paid_amount'] = $lims_sale_data->paid_amount;

        $product_id = $data['product_id'];
        $product_batch_id = $data['product_batch_id'];
        $imei_number = $data['imei_number'];
        $product_code = $data['product_code'];
        $qty = $data['qty'];
        $sale_unit = $data['sale_unit'];
        $net_unit_price = $data['net_unit_price'];
        $discount = $data['discount'];
        $tax_rate = $data['tax_rate'];
        $tax = $data['tax'];
        $total = $data['subtotal'];
        $product_sale = [];

        foreach ($product_id as $i => $id) {
            $lims_product_data = Product::where('id', $id)->first();
            $product_sale['variant_id'] = null;
            $product_sale['product_batch_id'] = null;
            if ($lims_product_data->type == 'combo' && $data['sale_status'] == 1) {
                $product_list = explode(",", $lims_product_data->product_list);
                $variant_list = explode(",", $lims_product_data->variant_list);
                if ($lims_product_data->variant_list)
                    $variant_list = explode(",", $lims_product_data->variant_list);
                else
                    $variant_list = [];
                $qty_list = explode(",", $lims_product_data->qty_list);
                $price_list = explode(",", $lims_product_data->price_list);

                foreach ($product_list as $key => $child_id) {
                    $child_data = Product::find($child_id);
                    if (count($variant_list) && $variant_list[$key]) {
                        $child_product_variant_data = ProductVariant::where([
                            ['product_id', $child_id],
                            ['variant_id', $variant_list[$key]]
                        ])->first();

                        $child_warehouse_data = Product_Warehouse::where([
                            ['product_id', $child_id],
                            ['variant_id', $variant_list[$key]],
                            ['warehouse_id', $data['warehouse_id']],
                        ])->first();

                        $child_product_variant_data->qty -= $qty[$i] * $qty_list[$key];
                        $child_product_variant_data->save();
                    } else {
                        $child_warehouse_data = Product_Warehouse::where([
                            ['product_id', $child_id],
                            ['warehouse_id', $data['warehouse_id']],
                        ])->first();
                    }

                    $child_data->qty -= $qty[$i] * $qty_list[$key];
                    $child_warehouse_data->qty -= $qty[$i] * $qty_list[$key];

                    $child_data->save();
                    $child_warehouse_data->save();
                }
            }

            if ($sale_unit[$i] != 'n/a') {
                $lims_sale_unit_data  = Unit::where('unit_name', $sale_unit[$i])->first();
                $sale_unit_id = $lims_sale_unit_data->id;
                if ($lims_product_data->is_variant) {
                    $lims_product_variant_data = ProductVariant::select('id', 'variant_id', 'qty')->FindExactProductWithCode($id, $product_code[$i])->first();
                    $product_sale['variant_id'] = $lims_product_variant_data->variant_id;
                }
                if ($lims_product_data->is_batch && $product_batch_id[$i]) {
                    $product_sale['product_batch_id'] = $product_batch_id[$i];
                }

                if ($data['sale_status'] == 1) {
                    if ($lims_sale_unit_data->operator == '*')
                        $quantity = $qty[$i] * $lims_sale_unit_data->operation_value;
                    elseif ($lims_sale_unit_data->operator == '/')
                        $quantity = $qty[$i] / $lims_sale_unit_data->operation_value;
                    //deduct quantity
                    $lims_product_data->qty = $lims_product_data->qty - $quantity;
                    $lims_product_data->save();
                    //deduct product variant quantity if exist
                    if ($lims_product_data->is_variant) {
                        $lims_product_variant_data->qty -= $quantity;
                        $lims_product_variant_data->save();
                        $lims_product_warehouse_data = Product_Warehouse::FindProductWithVariant($id, $lims_product_variant_data->variant_id, $data['warehouse_id'])->first();
                    } elseif ($product_batch_id[$i]) {
                        $lims_product_warehouse_data = Product_Warehouse::where([
                            ['product_batch_id', $product_batch_id[$i]],
                            ['warehouse_id', $data['warehouse_id']]
                        ])->first();
                        $lims_product_batch_data = ProductBatch::find($product_batch_id[$i]);
                        //deduct product batch quantity
                        $lims_product_batch_data->qty -= $quantity;
                        $lims_product_batch_data->save();
                    } else {
                        $lims_product_warehouse_data = Product_Warehouse::FindProductWithoutVariant($id, $data['warehouse_id'])->first();
                    }
                    //deduct quantity from warehouse
                    $lims_product_warehouse_data->qty -= $quantity;
                    $lims_product_warehouse_data->save();
                }
            } else
                $sale_unit_id = 0;

            if ($product_sale['variant_id']) {
                $variant_data = Variant::select('name')->find($product_sale['variant_id']);
                $mail_data['products'][$i] = $lims_product_data->name . ' [' . $variant_data->name . ']';
            } else
                $mail_data['products'][$i] = $lims_product_data->name;
            //deduct imei number if available
            if ($imei_number[$i]) {
                $imei_numbers = explode(",", $imei_number[$i]);
                $all_imei_numbers = explode(",", $lims_product_warehouse_data->imei_number);
                foreach ($imei_numbers as $number) {
                    if (($j = array_search($number, $all_imei_numbers)) !== false) {
                        unset($all_imei_numbers[$j]);
                    }
                }
                $lims_product_warehouse_data->imei_number = implode(",", $all_imei_numbers);
                $lims_product_warehouse_data->save();
            }
            if ($lims_product_data->type == 'digital')
                $mail_data['file'][$i] = url('/public/product/files') . '/' . $lims_product_data->file;
            else
                $mail_data['file'][$i] = '';
            if ($sale_unit_id)
                $mail_data['unit'][$i] = $lims_sale_unit_data->unit_code;
            else
                $mail_data['unit'][$i] = '';

            $product_sale['sale_id'] = $lims_sale_data->id;
            $product_sale['product_id'] = $id;
            $product_sale['imei_number'] = $imei_number[$i];
            $product_sale['qty'] = $mail_data['qty'][$i] = $qty[$i];
            $product_sale['sale_unit_id'] = $sale_unit_id;
            $product_sale['net_unit_price'] = $net_unit_price[$i];
            $product_sale['discount'] = $discount[$i];
            $product_sale['tax_rate'] = $tax_rate[$i];
            $product_sale['tax'] = $tax[$i];
            $product_sale['total'] = $mail_data['total'][$i] = $total[$i];
            Product_Sale::create($product_sale);
        }
        if ($data['sale_status'] == 3)
            $message = 'Sale successfully added to draft';
        else
            $message = ' Sale created successfully';
        if ($mail_data['email'] && $data['sale_status'] == 1) {
            try {
                Mail::send('mail.sale_details', $mail_data, function ($message) use ($mail_data) {
                    $message->to($mail_data['email'])->subject('Sale Details');
                });
            } catch (\Exception $e) {
                $message = ' Sale created successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }
        }

        if ($data['payment_status'] == 3 || $data['payment_status'] == 4 || ($data['payment_status'] == 2 && $data['pos'] && $data['paid_amount'] > 0)) {

            $lims_payment_data = new Payment();
            $lims_payment_data->user_id = auth()->user()->id;

            if ($data['paid_by_id'] == 1)
                $paying_method = 'Cash';
            elseif ($data['paid_by_id'] == 2) {
                $paying_method = 'Gift Card';
            } elseif ($data['paid_by_id'] == 3)
                $paying_method = 'Credit Card';
            elseif ($data['paid_by_id'] == 4)
                $paying_method = 'Cheque';
            elseif ($data['paid_by_id'] == 5)
                $paying_method = 'Paypal';
            elseif ($data['paid_by_id'] == 6)
                $paying_method = 'Deposit';
            elseif ($data['paid_by_id'] == 7) {
                $paying_method = 'Points';
                $lims_payment_data->used_points = $data['used_points'];
            }

            if ($cash_register_data)
                $lims_payment_data->cash_register_id = $cash_register_data->id;
            $lims_account_data = Account::where('is_default', true)->first();
            $lims_payment_data->account_id = $lims_account_data->id;
            $lims_payment_data->sale_id = $lims_sale_data->id;
            $data['payment_reference'] = 'spr-' . date("Ymd") . '-' . date("his");
            $lims_payment_data->payment_reference = $data['payment_reference'];
            $lims_payment_data->amount = $data['paid_amount'];
            $lims_payment_data->change = $data['paying_amount'] - $data['paid_amount'];
            $lims_payment_data->paying_method = $paying_method;
            $lims_payment_data->payment_note = $data['payment_note'];
            $lims_payment_data->save();

            $lims_payment_data = Payment::latest()->first();
            $data['payment_id'] = $lims_payment_data->id;
            if ($paying_method == 'Credit Card') {
                $lims_pos_setting_data = PosSetting::latest()->first();
                Stripe::setApiKey($lims_pos_setting_data->stripe_secret_key);
                $token = $data['stripeToken'];
                $grand_total = $data['grand_total'];

                $lims_payment_with_credit_card_data = PaymentWithCreditCard::where('customer_id', $data['customer_id'])->first();

                if (!$lims_payment_with_credit_card_data) {
                    // Create a Customer:
                    $customer = \Stripe\Customer::create([
                        'source' => $token
                    ]);

                    // Charge the Customer instead of the card:
                    $charge = \Stripe\Charge::create([
                        'amount' => $grand_total * 100,
                        'currency' => 'usd',
                        'customer' => $customer->id
                    ]);
                    $data['customer_stripe_id'] = $customer->id;
                } else {
                    $customer_id =
                        $lims_payment_with_credit_card_data->customer_stripe_id;

                    $charge = \Stripe\Charge::create([
                        'amount' => $grand_total * 100,
                        'currency' => 'usd',
                        'customer' => $customer_id, // Previously stored, then retrieved
                    ]);
                    $data['customer_stripe_id'] = $customer_id;
                }
                $data['charge_id'] = $charge->id;
                PaymentWithCreditCard::create($data);
            } elseif ($paying_method == 'Gift Card') {
                $lims_gift_card_data = GiftCard::find($data['gift_card_id']);
                $lims_gift_card_data->expense += $data['paid_amount'];
                $lims_gift_card_data->save();
                PaymentWithGiftCard::create($data);
            } elseif ($paying_method == 'Cheque') {
                PaymentWithCheque::create($data);
            } elseif ($paying_method == 'Paypal') {
                $provider = new ExpressCheckout;
                $paypal_data = [];
                $paypal_data['items'] = [];
                foreach ($data['product_id'] as $key => $product_id) {
                    $lims_product_data = Product::find($product_id);
                    $paypal_data['items'][] = [
                        'name' => $lims_product_data->name,
                        'price' => ($data['subtotal'][$key] / $data['qty'][$key]),
                        'qty' => $data['qty'][$key]
                    ];
                }
                $paypal_data['items'][] = [
                    'name' => 'Order Tax',
                    'price' => $data['order_tax'],
                    'qty' => 1
                ];
                $paypal_data['items'][] = [
                    'name' => 'Order Discount',
                    'price' => $data['order_discount'] * (-1),
                    'qty' => 1
                ];
                $paypal_data['items'][] = [
                    'name' => 'Shipping Cost',
                    'price' => $data['shipping_cost'],
                    'qty' => 1
                ];
                if ($data['grand_total'] != $data['paid_amount']) {
                    $paypal_data['items'][] = [
                        'name' => 'Due',
                        'price' => ($data['grand_total'] - $data['paid_amount']) * (-1),
                        'qty' => 1
                    ];
                }
                //return $paypal_data;
                $paypal_data['invoice_id'] = $lims_sale_data->reference_no;
                $paypal_data['invoice_description'] = "Reference # {$paypal_data['invoice_id']} Invoice";
                $paypal_data['return_url'] = url('/sale/paypalSuccess');
                $paypal_data['cancel_url'] = url('/sale/create');

                $total = 0;
                foreach ($paypal_data['items'] as $item) {
                    $total += $item['price'] * $item['qty'];
                }

                $paypal_data['total'] = $total;
                $response = $provider->setExpressCheckout($paypal_data);
                // This will redirect user to PayPal
                return redirect($response['paypal_link']);
            } elseif ($paying_method == 'Deposit') {
                $lims_customer_data->expense += $data['paid_amount'];
                $lims_customer_data->save();
            } elseif ($paying_method == 'Points') {
                $lims_customer_data->points -= $data['used_points'];
                $lims_customer_data->save();
            }
        }
        $id_warehouse = auth()->user()->warehouse_id;

        $alerts = Product::select('name','code', 'image', 'qty', 'alert_quantity')->where('is_active', true)->whereColumn('alert_quantity', '>', 'qty')->pluck('name');

        $msg = [
            'title' => "Peringatan stock",
            'description' => 'Stock Produk '. implode(', ', $alerts->toArray()) .' telah mencapai batas maksimal
            ',
        ];
        if(count($alerts) > 0){
            Helpers::notifToAdmin($id_warehouse, $msg);
        }

        if ($lims_sale_data->sale_status == '1')
            return response()->json(['message' => $message]);
        // return redirect('sales/gen_invoice/' . $lims_sale_data->id)->with('message', $message);
        elseif ($data['pos'])
            return response()->json(['message' => $message]);
        // return redirect('pos')->with('message', $message);
        else
            return response()->json(['message' => $message]);
        // return redirect('sales')->with('message', $message);
        // reference_no: 
        // warehouse_id: 1
        // biller_id: 1
        // customer_id: 1
        // product_code_name: 
        // product_batch_id[]: 
        // qty[]: 2
        // product_code[]: AX5GB
        // product_id[]: 2
        // sale_unit[]: Pcs
        // net_unit_price[]: 30000.00
        // discount[]: 0.00
        // tax_rate[]: 0.00
        // tax[]: 0.00
        // subtotal[]: 60000.00
        // qty[]: 3
        // product_code[]: AX3GB
        // product_id[]: 1
        // sale_unit[]: Pcs
        // net_unit_price[]: 17000.00
        // discount[]: 0.00
        // tax_rate[]: 0.00
        // tax[]: 0.00
        // subtotal[]: 51000.00
        // total_qty: 5
        // total_discount: 0.00
        // total_tax: 0.00
        // total_price: 111000.00
        // item: 2
        // order_tax: 0.00
        // grand_total: 111000.00
        // used_points: 
        // coupon_discount: 
        // sale_status: 1
        // coupon_active: 
        // coupon_id: 
        // coupon_discount: 
        // pos: 1
        // draft: 0
        // paying_amount: 111000.00
        // paid_amount: 111000.00
        // paid_by_id: 1
        // gift_card_id: 
        // cheque_no: 
        // payment_note: 
        // sale_note: 
        // staff_note: 
        // order_discount_type: Flat
        // order_discount_value: 
        // order_discount: 0
        // order_tax_rate: 0
        // shipping_cost: 

    }

    public function scan_product(Request $request)
    {
        $this->validate($request, [
            'code' => 'required',
        ]);

        $product = Product::where('code', 'like', '%' . $request['code'] . '%')->orWhere('name', 'like', '%' . $request['code'] . '%')->orWhere('barcode_symbology', 'like', '%' . $request['code'] . '%')->first();
        $data = [];
        if ($product) {
            $data = [
                "id" => $product['id'],
                "name" => $product['name'],
                "code" => $product['code'],
                "barcode_symbology" => $product['barcode_symbology'],
                "price" => $product['price'],
            ];
        }

        return response()->json($data);
    }

    public function add_cart(Request $request){
        $this->validate($request, [
            'product_code' => 'required',
            'product_name' => 'required',
            'customer_id' => 'required',
        ]);

        $warehouse_id = auth()->user()->warehouse_id ?? 1;

        $todayDate = date('Y-m-d');
        $product_code = [$request->product_code, $request->product_name];
        $product_info = [$request->product_code, $request->customer_id, '1'];
        $customer_id = $product_info[1];
        // return response()->json([$product_code, $product_info, $customer_id]);
        if(strpos($request['data'], '|')) {
            $product_info = explode("|", $request['data']);
            $embeded_code = $product_code[0];
            $product_code[0] = substr($embeded_code, 0, 7);
            $qty = substr($embeded_code, 7, 5) / 1000;
        }
        else {
            $product_code[0] = rtrim($product_code[0], " ");
            $qty = $product_info[2];
        }
        $product_variant_id = null;
        $all_discount = DB::table('discount_plan_customers')
                        ->join('discount_plans', 'discount_plans.id', '=', 'discount_plan_customers.discount_plan_id')
                        ->join('discount_plan_discounts', 'discount_plans.id', '=', 'discount_plan_discounts.discount_plan_id')
                        ->join('discounts', 'discounts.id', '=', 'discount_plan_discounts.discount_id')
                        ->where([
                            ['discount_plans.is_active', true],
                            ['discounts.is_active', true],
                            ['discount_plan_customers.customer_id', $customer_id]
                        ])
                        ->select('discounts.*')
                        ->get();
        $lims_product_data = Product::where([
            ['code', $product_code[0]],
            ['is_active', true]
        ])->first();
        if(!$lims_product_data) {
            $lims_product_data = Product::join('product_variants', 'products.id', 'product_variants.product_id')
                ->select('products.*', 'product_variants.id as product_variant_id', 'product_variants.item_code', 'product_variants.additional_price')
                ->where([
                    ['product_variants.item_code', $product_code[0]],
                    ['products.is_active', true]
                ])->first();
            $product_variant_id = $lims_product_data->product_variant_id;
        }

        $product['product_name'] = $lims_product_data->name;
        if($lims_product_data->is_variant){
            $product['product_code'] = $lims_product_data->item_code;
            $lims_product_data->price += $lims_product_data->additional_price;
        }
        else
            $product['product_code'] = $lims_product_data->code;

        $no_discount = 1;
        foreach ($all_discount as $key => $discount) {
            $product_list = explode(",", $discount->product_list);
            $days = explode(",", $discount->days);

            if( ( $discount->applicable_for == 'All' || in_array($lims_product_data->id, $product_list) ) && ( $todayDate >= $discount->valid_from && $todayDate <= $discount->valid_till && in_array(date('D'), $days) && $qty >= $discount->minimum_qty && $qty <= $discount->maximum_qty ) ) {
                if($discount->type == 'flat') {
                    $product['discount'] = $lims_product_data->price - $discount->value;
                }
                elseif($discount->type == 'percentage') {
                    $product['discount'] = $lims_product_data->price - ($lims_product_data->price * ($discount->value/100));
                }
                $no_discount = 0;
                break;
            }
            else {
                continue;
            }       
        }

        // return $lims_product_data;
        $qty_warehouse = 0;

        $product_warehouse = Product_Warehouse::where(['product_id' => $lims_product_data->id, 'warehouse_id' => $warehouse_id])->first();
        if($product_warehouse){
            $qty_warehouse = $product_warehouse->qty;
        }

        if($lims_product_data->is_diffPrice == 1){
            if($lims_product_data->promotion && $todayDate <= $lims_product_data->last_date && $no_discount) {
                $product['price'] = $lims_product_data->promotion_price;
            }
            elseif($no_discount)
                if($product_warehouse){
                    $product['price'] = $product_warehouse->price;
                }else{
                    $product['price'] = $lims_product_data->price;
                }
        }else{
            if($lims_product_data->promotion && $todayDate <= $lims_product_data->last_date && $no_discount) {
                $product['price'] = $lims_product_data->promotion_price;
            }
            elseif($no_discount)
                $product['price'] = $lims_product_data->price;
        }
        
        if($lims_product_data->tax_id) {
            $lims_tax_data = Tax::find($lims_product_data->tax_id);
            $product['tax_rate'] = $lims_tax_data->rate;
            $product['tax_name'] = $lims_tax_data->name;
        }
        else{
            $product['tax_rate'] = 0;
            $product['tax_name'] = 'No Tax';
        }
        $product['tax_method'] = $lims_product_data->tax_method;
        if($lims_product_data->type == 'standard'){
            $units = Unit::where("base_unit", $lims_product_data->unit_id)
                    ->orWhere('id', $lims_product_data->unit_id)
                    ->get();
            $unit_name = array();
            $unit_operator = array();
            $unit_operation_value = array();
            foreach ($units as $unit) {
                if($lims_product_data->sale_unit_id == $unit->id) {
                    array_unshift($unit_name, $unit->unit_name);
                    array_unshift($unit_operator, $unit->operator);
                    array_unshift($unit_operation_value, $unit->operation_value);
                }
                else {
                    $unit_name[]  = $unit->unit_name;
                    $unit_operator[] = $unit->operator;
                    $unit_operation_value[] = $unit->operation_value;
                }
            }
            $product['unit'] = implode(",",$unit_name) . ',';
            $product['operator'] = implode(",",$unit_operator) . ',';
            $product['unit_operation'] = implode(",",$unit_operation_value) . ',';     
        }
        else{
            $product['unit'] = 'n/a'. ',';
            $product['operator'] = 'n/a'. ',';
            $product['unit_operation'] = 'n/a'. ',';
        }
        $product['product_id'] = $lims_product_data->id;
        $product['warehouse_id'] = $lims_product_data->id;
        $product['variant_id'] = $product_variant_id;
        $product['promotion'] = $lims_product_data->promotion;
        $product['is_batch'] = $lims_product_data->is_batch;
        $product['is_imei'] = $lims_product_data->is_imei;
        $product['is_variant'] = $lims_product_data->is_variant;
        $product['qty'] = $qty_warehouse;
        return $product;
    }
}
