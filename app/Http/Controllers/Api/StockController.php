<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Product;
use App\Product_Warehouse;
use App\ProductTransfer;
use App\ProductVariant;
use App\Transfer;
use App\Unit;
use Carbon\Carbon;
use Illuminate\Http\Request;

class StockController extends Controller
{
    public function reportTransfer(Request $request)
    {
        $columns = array(
            1 => 'created_at',
            2 => 'reference_no',
        );

        $start = $request->starting_date ?? date('Y-m').'-01';
        $end = $request->ending_date ?? date('Y-m-d');

        $from_warehouse_id = $request->from_warehouse_id;
        $to_warehouse_id = $request->to_warehouse_id;
        $q = Transfer::whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end);
        if (auth()->user()->role_id > 2 && config('staff_access') == 'own')
            $q = $q->where('user_id', auth()->id());
        if ($from_warehouse_id)
            $q = $q->where('from_warehouse_id', $from_warehouse_id);
        if ($to_warehouse_id)
            $q = $q->where('to_warehouse_id', $to_warehouse_id);

        $totalData = $q->count();
        $totalFiltered = $totalData;

        if ($request->input('length') != -1)
            $limit = $request->input('length');
        else
            $limit = $totalData;
        // $start = $request->input('start');
        $order = 'transfers.' . $columns[1];
        $dir = $request->input('order.0.dir');
        if (empty($request->input('search.value'))) {
            $q = Transfer::with('fromWarehouse', 'toWarehouse', 'user')
                ->whereDate('created_at', '>=', $start)
                ->whereDate('created_at', '<=', $end)
                // ->offset($start)
                ->limit(null)
                ->orderBy('created_at', 'desc');
            if (auth()->user()->role_id > 2 && config('staff_access') == 'own')
                $q = $q->where('user_id', auth()->id());
            if ($from_warehouse_id)
                $q = $q->where('from_warehouse_id', $from_warehouse_id);
            if ($to_warehouse_id)
                $q = $q->where('to_warehouse_id', $to_warehouse_id);
            $transfers = $q->get();
        } else {
            $search = $request->input('search.value');
            $q = Transfer::whereDate('transfers.created_at', '=', date('Y-m-d', strtotime(str_replace('/', '-', $search))))
                ->offset($start)
                ->limit($limit)
                ->orderBy($order, $dir);
            if (auth()->user()->role_id > 2 && config('staff_access') == 'own') {
                $transfers =  $q->select('transfers.*')
                    ->with('fromWarehouse', 'toWarehouse', 'user')
                    ->where('transfers.user_id', auth()->id())
                    ->orwhere([
                        ['reference_no', 'LIKE', "%{$search}%"],
                        ['user_id', auth()->id()]
                    ])
                    ->get();
                $totalFiltered = $q->where('transfers.user_id', auth()->id())->count();
            } else {
                $transfers =  $q->select('transfers.*')
                    ->with('fromWarehouse', 'toWarehouse', 'user')
                    ->orwhere('reference_no', 'LIKE', "%{$search}%")
                    ->get();

                $totalFiltered = $q->orwhere('transfers.reference_no', 'LIKE', "%{$search}%")->count();
            }
        }
        $data = array();
        if (!empty($transfers)) {
            foreach ($transfers as $key => $transfer) {
                $nestedData['id'] = $transfer->id;
                $nestedData['key'] = $key;
                $nestedData['date_transfer'] = Carbon::parse($transfer->created_at)->format('d-m-Y');
                $nestedData['reference_no'] = $transfer->reference_no;
                $nestedData['from_warehouse'] = $transfer->fromWarehouse->id;
                $nestedData['to_warehouse'] = $transfer->toWarehouse->id;
                $nestedData['total_cost'] = number_format($transfer->total_cost, 0);
                $nestedData['total_tax'] = number_format($transfer->total_tax, 0);
                $nestedData['grand_total'] = number_format($transfer->grand_total, 0);

                if ($transfer->status == 1) {
                    $nestedData['status'] = 'completed';
                    $status = trans('file.Completed');
                } elseif ($transfer->status == 2) {
                    $nestedData['status'] = 'pending';
                    $status = trans('file.Pending');
                } elseif ($transfer->status == 3) {
                    $nestedData['status'] = 'sent';
                    $status = trans('file.Sent');
                }

                // $nestedData['options'] = '<div class="btn-group">
                //             <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . trans("file.action") . '
                //               <span class="caret"></span>
                //               <span class="sr-only">Toggle Dropdown</span>
                //             </button>
                //             <ul class="dropdown-menu edit-options dropdown-menu-right dropdown-default" user="menu">
                //                 <li>
                //                     <button type="button" class="btn btn-link view"><i class="fa fa-eye"></i> ' . trans('file.View') . '</button>
                //                 </li>';
                // if (in_array("transfers-edit", $request['all_permission'])) {
                //     $nestedData['options'] .= '<li>
                //         <a href="' . route('transfers.edit', $transfer->id) . '" class="btn btn-link"><i class="dripicons-document-edit"></i> ' . trans('file.edit') . '</a>
                //         </li>';
                // }
                // if (in_array("transfers-delete", $request['all_permission']))
                //     $nestedData['options'] .= \Form::open(["route" => ["transfers.destroy", $transfer->id], "method" => "DELETE"]) . '
                //             <li>
                //               <button type="submit" class="btn btn-link" onclick="return confirmDelete()"><i class="dripicons-trash"></i> ' . trans("file.delete") . '</button> 
                //             </li>' . \Form::close() . '
                //         </ul>
                //     </div>';
                // data for transfer details by one click

                $nestedData['transfer'] = array(
                    '[ "' . date(config('date_format'), strtotime($transfer->created_at->toDateString())) . '"', ' "' . $transfer->reference_no . '"', ' "' . $status . '"', ' "' . $transfer->id . '"', ' "' . $transfer->fromWarehouse->name . '"', ' "' . $transfer->fromWarehouse->phone . '"', ' "' . preg_replace('/\s+/S', " ", $transfer->fromWarehouse->address) . '"', ' "' . $transfer->toWarehouse->name . '"', ' "' . $transfer->toWarehouse->phone . '"', ' "' . preg_replace('/\s+/S', " ", $transfer->toWarehouse->address) . '"', ' "' . $transfer->total_tax . '"', ' "' . $transfer->total_cost . '"', ' "' . $transfer->shipping_cost . '"', ' "' . $transfer->grand_total . '"', ' "' . preg_replace('/[\n\r]/', "<br>", $transfer->note) . '"', ' "' . $transfer->user->name . '"', ' "' . $transfer->user->email . '"]'
                );

                $product = ProductTransfer::with('product')->where('transfer_id', $transfer->id)->get();
                $item = [];

                foreach($product as $p){
                    $i = [
                        'product_id' => $p['product_id'],
                        'name' => $p['product']['name'],
                        'qty' => $p['qty'],
                        'cost' => $p['product']['cost'],
                    ];
                    array_push($item, $i);
                }

                // return $product;
                $nestedData['transfer'] = [
                    'id' => $transfer->id,
                    'created_at' => $transfer->created_at,
                    'reference_no' => $transfer->reference_no,
                    'status' => $status,
                    'from_warehouse' => $transfer->fromWarehouse->id,
                    'to_warehouse' => $transfer->toWarehouse->id,
                    'product' => $item
                ];
                $data[] = $nestedData;
            }
        }
        $json_data = array(
            "starting_date"            => $start,
            "ending_date"            => $end,
            "from_warehouse_id"            => $from_warehouse_id,
            "to_warehouse_id"            => $to_warehouse_id,
            "draw"            => intval($request->input('draw')),
            "recordsTotal"    => intval($totalData),
            "recordsFiltered" => intval($totalFiltered),
            "data"            => $data
        );
        return response()->json($json_data);
    }
    public function transfer_stock(Request $request)
    {

        $this->validate($request, [
            'from_warehouse_id' => 'required',
            'to_warehouse_id' => 'required',
            'product_id' => 'required',
            'product_code' => 'required',
            'qty' => 'required',
            'net_unit_cost' => 'required',
            'subtotal' => 'required',
            'total_cost' => 'required',
            'item' => 'required',
        ]);

        $data = $request->all();
        // from_warehouse_id: 1
        // to_warehouse_id: 2
        $data['status'] = 1;
        $data['product_code_name'] = null;
        // product_batch_id[]: 
        // qty[]: 3
        // product_code[]: AX5GB
        // product_id[]: 2
        $data['purchase_unit'] = [];
        // net_unit_cost[]: 15000.00
        $data['tax_rate'] = [];
        $data['tax'] = [];
        // subtotal[]: 45000.00
        $data['imei_number'] = [];
        $data['product_batch_id'] = [];
        // qty[]: 2
        // product_code[]: AX3GB
        // product_id[]: 1
        // purchase_unit[]: Pcs
        // net_unit_cost[]: 13000.00
        // $tax_rate[]: 0.00
        // tax[]: 0.00
        // subtotal[]: 26000.00
        // imei_number[]: 
        // total_qty: 5
        $data['total_discount'] = 0;
        $data['total_tax'] = 0.00;
        // $total_cost = $request->total_price;
        // item: 2
        $data['order_tax'] = null;
        $data['grand_total'] = $request->total_cost;
        $data['paid_amount'] = $request->total_cost;
        $data['payment_status'] = 1;
        $data['shipping_cost'] = 0;
        $data['document'] = null;
        $data['note'] = null;

        foreach ($request->product_id as $p) {
            array_push($data['purchase_unit'], 'Pcs');
            array_push($data['tax_rate'], 0);
            array_push($data['tax'], 0);
            array_push($data['imei_number'], null);
            array_push($data['product_batch_id'], null);
        }


        // return $data;
        $data['user_id'] = auth()->id();
        $data['reference_no'] = 'tr-' . date("Ymd") . '-' . date("his");
        if (isset($data['created_at']))
            $data['created_at'] = date("Y-m-d H:i:s", strtotime($data['created_at']));
        else
            $data['created_at'] = date("Y-m-d H:i:s");
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
        //     $document->move('public/documents/transfer', $documentName);
        //     $data['document'] = $documentName;
        // }
        $lims_transfer_data = Transfer::create($data);

        $product_id = $data['product_id'];
        $imei_number = $data['imei_number'];
        $product_batch_id = $data['product_batch_id'];
        $product_code = $data['product_code'];
        $qty = $data['qty'];
        $purchase_unit = $data['purchase_unit'];
        $net_unit_cost = $data['net_unit_cost'];
        $tax_rate = $data['tax_rate'];
        $tax = $data['tax'];
        $total = $data['subtotal'];
        $product_transfer = [];

        foreach ($product_id as $i => $id) {
            $lims_purchase_unit_data  = Unit::where('unit_name', $purchase_unit[$i])->first();
            $product_transfer['variant_id'] = null;
            $product_transfer['product_batch_id'] = null;

            //get product data
            $lims_product_data = Product::select('is_variant')->find($id);
            if ($lims_product_data->is_variant) {
                $lims_product_variant_data = ProductVariant::select('variant_id')->FindExactProductWithCode($id, $product_code[$i])->first();
                $lims_product_warehouse_data = Product_Warehouse::FindProductWithVariant($id, $lims_product_variant_data->variant_id, $data['from_warehouse_id'])->first();
                $product_transfer['variant_id'] = $lims_product_variant_data->variant_id;
            } elseif ($product_batch_id[$i]) {
                $lims_product_warehouse_data = Product_Warehouse::where([
                    ['product_batch_id', $product_batch_id[$i]],
                    ['warehouse_id', $data['from_warehouse_id']]
                ])->first();
                $product_transfer['product_batch_id'] = $product_batch_id[$i];
            } else {
                $lims_product_warehouse_data = Product_Warehouse::where([
                    ['product_id', $id],
                    ['warehouse_id', $data['from_warehouse_id']],
                ])->first();
            }

            if ($data['status'] != 2) {
                if ($lims_purchase_unit_data->operator == '*')
                    $quantity = $qty[$i] * $lims_purchase_unit_data->operation_value;
                else
                    $quantity = $qty[$i] / $lims_purchase_unit_data->operation_value;
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
                }
            } else
                $quantity = 0;
            //deduct quantity from sending warehouse
            $lims_product_warehouse_data->qty -= $quantity;
            $lims_product_warehouse_data->save();

            if ($data['status'] == 1) {
                if ($lims_product_data->is_variant) {
                    $lims_product_warehouse_data = Product_Warehouse::FindProductWithVariant($id, $lims_product_variant_data->variant_id, $data['to_warehouse_id'])->first();
                } elseif ($product_batch_id[$i]) {
                    $lims_product_warehouse_data = Product_Warehouse::where([
                        ['product_batch_id', $product_batch_id[$i]],
                        ['warehouse_id', $data['to_warehouse_id']]
                    ])->first();
                } else {
                    $lims_product_warehouse_data = Product_Warehouse::where([
                        ['product_id', $id],
                        ['warehouse_id', $data['to_warehouse_id']],
                    ])->first();
                }
                //add quantity to destination warehouse
                if ($lims_product_warehouse_data)
                    $lims_product_warehouse_data->qty += $quantity;
                else {
                    $lims_product_warehouse_data = new Product_Warehouse();
                    $lims_product_warehouse_data->product_id = $id;
                    $lims_product_warehouse_data->product_batch_id = $product_transfer['product_batch_id'];
                    $lims_product_warehouse_data->variant_id = $product_transfer['variant_id'];
                    $lims_product_warehouse_data->warehouse_id = $data['to_warehouse_id'];
                    $lims_product_warehouse_data->qty = $quantity;
                }
                //add imei number if available
                if ($imei_number[$i]) {
                    if ($lims_product_warehouse_data->imei_number)
                        $lims_product_warehouse_data->imei_number .= ',' . $imei_number[$i];
                    else
                        $lims_product_warehouse_data->imei_number = $imei_number[$i];
                }

                $lims_product_warehouse_data->save();
            }

            $product_transfer['transfer_id'] = $lims_transfer_data->id;
            $product_transfer['product_id'] = $id;
            $product_transfer['imei_number'] = $imei_number[$i];
            $product_transfer['qty'] = $qty[$i];
            $product_transfer['purchase_unit_id'] = $lims_purchase_unit_data->id;
            $product_transfer['net_unit_cost'] = $net_unit_cost[$i];
            $product_transfer['tax_rate'] = $tax_rate[$i];
            $product_transfer['tax'] = $tax[$i];
            $product_transfer['total'] = $total[$i];
            ProductTransfer::create($product_transfer);
        }

        return response()->json(['status' => 'success', 'message' => 'Transafer stock berhasil!']);
    }
}
