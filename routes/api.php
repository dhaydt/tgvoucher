<?php

use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\GeneralController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('login', [LoginController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [UserController::class, 'profile']);
    Route::post('/update_fcm', [UserController::class, 'updateFcm']);
    Route::get('/home', [UserController::class, 'home']);
    Route::post('/change_password', [UserController::class, 'change_password']);
    
    Route::post('/transfer_stock', [StockController::class, 'transfer_stock']);
    Route::post('/report_transfer_stock', [StockController::class, 'reportTransfer']);

    Route::get('/transaction', [TransactionController::class, 'index']);
    Route::post('/transaction_post', [TransactionController::class, 'transaction_post']);
    Route::post('/scan_product', [TransactionController::class, 'scan_product']);
    Route::get('/product_list', [TransactionController::class, 'product_list']);
    Route::get('/product_list_buyer', [TransactionController::class, 'product_list_buyer']);
    Route::post('/transaction_list', [TransactionController::class, 'transaction_list']);
    Route::get('/transaction_detail/{id}', [TransactionController::class, 'transaction_details']);
    Route::post('/add_purchase', [TransactionController::class, 'addPurchase']);
    Route::post('/add_expense', [TransactionController::class, 'addExpense']);
    Route::post('/add_cart', [TransactionController::class, 'add_cart']);

    Route::post('/sale_report', [ReportController::class, 'saleReport']);
    Route::post('/sale_return_report', [ReportController::class, 'saleReturn']);
    Route::post('/customer_due_report', [ReportController::class, 'customerDueReport']);
    Route::post('/purchase_return_report', [ReportController::class, 'purchaseReturn']);
    Route::post('/purchase_report', [ReportController::class, 'purchaseReport']);
    Route::post('/stock_report', [ReportController::class, 'stockReport']);
    Route::post('/expense_report', [ReportController::class, 'expenseReport']);
    Route::post('/stock_alert', [ReportController::class, 'stockAlert']);
    Route::post('/profit_loss_report', [ReportController::class, 'profitLossReport']);
    
    Route::get('/expense_category', [ReportController::class, 'expense_category']);
    
    Route::get('category_product', [GeneralController::class, 'category_product']);
    Route::get('customer', [GeneralController::class, 'customer']);
    Route::get('supplier', [GeneralController::class, 'supplier']);
    Route::get('warehouse', [GeneralController::class, 'warehouse']);
    Route::post('add_supplier', [GeneralController::class, 'add_supplier']);
    Route::post('add_customer', [GeneralController::class, 'add_customer']);
    Route::get('customer_group', [GeneralController::class, 'customer_group']);
});
