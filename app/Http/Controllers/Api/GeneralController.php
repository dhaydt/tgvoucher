<?php

namespace App\Http\Controllers\Api;

use App\Category;
use App\Customer;
use App\CustomerGroup;
use App\Http\Controllers\Controller;
use App\Supplier;
use App\User;
use App\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Symfony\Contracts\Service\Attribute\Required;

class GeneralController extends Controller
{
    public function category_product()
    {
        $data = Category::where('is_active', 1)->get();

        return response()->json($data);
    }
    
    public function customer_group()
    {
        $data = CustomerGroup::where('is_active', 1)->get();

        return response()->json($data);
    }

    public function customer()
    {
        $data = Customer::where('is_active', 1)->get();

        return response()->json($data);
    }

    public function supplier()
    {
        $data = Supplier::where('is_active', 1)->get();

        return response()->json($data);
    }

    public function warehouse()
    {
        $data = Warehouse::where('is_active', 1)->get();

        return response()->json($data);
    }
    
    public function add_customer(Request $request)
    {
        $this->validate($request, [
            'customer_group_id' => 'required',
            'customer_name' => 'required',
            'address' => 'required',
            'city' => 'required',
            'phone_number' => [
                'max:255',
                Rule::unique('customers')->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
        ]);
    
        //validation for user if given user access
        if(isset($request->user)) {
            $this->validate($request, [
                'name' => [
                    'max:255',
                        Rule::unique('users')->where(function ($query) {
                        return $query->where('is_deleted', false);
                    }),
                ],
                'email' => [
                    'email',
                    'max:255',
                        Rule::unique('users')->where(function ($query) {
                        return $query->where('is_deleted', false);
                    }),
                ],
            ]);
        }
        $lims_customer_data = $request->all();
        $lims_customer_data['is_active'] = true;
        $message = 'Customer';
        if(isset($request->user)) {
            $lims_customer_data['phone'] = $lims_customer_data['phone_number'];
            $lims_customer_data['role_id'] = 5;
            $lims_customer_data['is_deleted'] = false;
            $lims_customer_data['password'] = bcrypt($lims_customer_data['password']);
            $user = User::create($lims_customer_data);
            $lims_customer_data['user_id'] = $user->id;
            $message .= ', User';
        }
        $lims_customer_data['name'] = $lims_customer_data['customer_name'];
        if(isset($request->both)) {
            Supplier::create($lims_customer_data);
            $message .= ' and Supplier';
        }
        
        if($lims_customer_data['email']) {
            try{
                Mail::send( 'mail.customer_create', $lims_customer_data, function( $message ) use ($lims_customer_data)
                {
                    $message->to( $lims_customer_data['email'] )->subject( 'New Customer' );
                });
            }
            catch(\Exception $e){
                $message .= ' created successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
            }   
        }
        else{
            $message .= ' created successfully!';
        }
            
        $Customer = Customer::create($lims_customer_data);
        $message .= ' created successfully!';
        // if($lims_customer_data['pos'])
        //     return redirect('pos')->with('message', $message);
        // else
        return response()->json(['message' => $message, 'customer_id' => $Customer['id']]);
    }

    public function add_supplier(Request $request)
    {
        $this->validate($request, [
            'company_name' => [
                'max:255',
                Rule::unique('suppliers')->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
            'email' => [
                'max:255',
                Rule::unique('suppliers')->where(function ($query) {
                    return $query->where('is_active', 1);
                }),
            ],
            'image' => 'image|mimes:jpg,jpeg,png,gif|max:100000',
            'name' => 'required',
            'phone_number' => 'required',
            'address' => 'required',
            'city' => 'required',
        ]);

        $lims_supplier_data = $request->except('image');
        $lims_supplier_data['is_active'] = true;
        $image = $request->image;
        if ($image) {
            $ext = pathinfo($image->getClientOriginalName(), PATHINFO_EXTENSION);
            $imageName = preg_replace('/[^a-zA-Z0-9]/', '', $request['company_name']);
            $imageName = $imageName . '.' . $ext;
            $image->move('public/images/supplier', $imageName);
            $lims_supplier_data['image'] = $imageName;
        }
        $sup = Supplier::create($lims_supplier_data);
        $message = 'Supplier';
        try{
            Mail::send( 'mail.supplier_create', $lims_supplier_data, function( $message ) use ($lims_supplier_data)
            {
                $message->to( $lims_supplier_data['email'] )->subject( 'New Supplier' );
            });
            $message .= ' created successfully!';
        }
        catch(\Exception $e) {
            $message .= ' created successfully. Please setup your <a href="setting/mail_setting">mail setting</a> to send mail.';
        }
        return response()->json(['message' => $message, 'customersupllier_id' => $sup['id']]);
    }
}
