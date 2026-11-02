<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Catalog\ImportController;
use App\Http\Controllers\Catalog\InventoryController;
use App\Http\Controllers\Catalog\MediaController;
use App\Http\Controllers\Catalog\MissingImagesController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductImageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Accounting\AccountingController;
use App\Http\Controllers\Accounting\CodController;
use App\Http\Controllers\Accounting\EquityController;
use App\Http\Controllers\Accounting\ExpenseController;
use App\Http\Controllers\Accounting\LoanController;
use App\Http\Controllers\Accounting\ReportController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\VerificationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\Settings\CourierController;
use App\Http\Controllers\Settings\StaffController;
use App\Http\Controllers\Settings\WorkspaceSettingsController;
use App\Http\Controllers\Sales\CustomerController;
use App\Http\Controllers\Sales\OrderController;
use App\Http\Controllers\Sales\ShipmentController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));
Route::get('/health', HealthController::class)->withoutMiddleware('web')->name('health');
Route::get('/offline', OfflineController::class)->name('offline');

// cPanel-friendly maintenance (clear/optimize caches, migrate). 404 unless MAINTENANCE_KEY is set; see MaintenanceController.
Route::get('/maintenance/{action}', MaintenanceController::class)->withoutMiddleware('web')->middleware('throttle:20,1')->name('maintenance');

// Invitation links are opened by guests or signed-in users alike.
Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitations.accept');

Route::prefix('auth')->middleware('guest')->group(function () {
    Route::get('forgot-password', [PasswordResetController::class, 'forgot'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'send'])->middleware('throttle:5,1')->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'show'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1')->name('password.update');
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
});

Route::middleware('auth')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('auth/verify-email', [VerificationController::class, 'notice'])->name('verification.notice');
    Route::get('auth/verify-email/{id}/{hash}', [VerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('auth/verify-email/resend', [VerificationController::class, 'resend'])->middleware('throttle:3,1')->name('verification.send');

    // Workspace selection works without an active tenant.
    Route::get('app/workspaces', [WorkspaceController::class, 'index'])->middleware('verified.policy')->name('workspaces.index');
    Route::post('app/workspaces', [WorkspaceController::class, 'store'])->middleware('verified.policy')->name('workspaces.store');
    Route::post('app/workspaces/{tenant}/switch', [WorkspaceController::class, 'switch'])->middleware('verified.policy')->name('workspaces.switch');

    Route::middleware(['verified.policy', 'tenant'])->prefix('app')->group(function () {
        Route::get('settings', [WorkspaceSettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [WorkspaceSettingsController::class, 'update'])->name('settings.update');
        Route::prefix('accounting')->name('accounting.')->group(function () {
            Route::get('/', [AccountingController::class, 'overview'])->name('overview');
            Route::get('accounts', [AccountingController::class, 'accounts'])->name('accounts');
            Route::post('financial-accounts', [AccountingController::class, 'addFinancialAccount'])->name('financial.add');
            Route::post('close-period', [AccountingController::class, 'closePeriod'])->name('close');
            Route::get('journal', [AccountingController::class, 'journal'])->name('journal');
            Route::post('journal/{entry}/reverse', [AccountingController::class, 'reverse'])->name('reverse');
            Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses');
            Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
            Route::post('expenses/{expense}/void', [ExpenseController::class, 'void'])->name('expenses.void');
            Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->name('expenses.receipt');
            Route::get('loans', [LoanController::class, 'index'])->name('loans');
            Route::post('loans', [LoanController::class, 'store'])->name('loans.store');
            Route::post('loans/{loan}/repay', [LoanController::class, 'repay'])->name('loans.repay');
            Route::get('equity', [EquityController::class, 'index'])->name('equity');
            Route::post('equity/{kind}', [EquityController::class, 'store'])->name('equity.store');
            Route::get('cod', [CodController::class, 'index'])->name('cod');
            Route::post('cod', [CodController::class, 'settle'])->name('cod.settle');
        });
        Route::get('reports/{type}', [ReportController::class, 'show'])->name('reports.show');

        Route::get('settings/couriers', [CourierController::class, 'index'])->name('couriers.index');
        Route::post('settings/couriers/{provider}', [CourierController::class, 'save'])->middleware('throttle:10,1')->name('couriers.save');
        Route::post('settings/couriers/{provider}/test', [CourierController::class, 'test'])->middleware('throttle:10,1')->name('couriers.test');
        Route::delete('settings/couriers/{provider}', [CourierController::class, 'destroy'])->name('couriers.destroy');
        Route::get('settings/staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('settings/staff/invite', [StaffController::class, 'invite'])->middleware('throttle:20,1')->name('staff.invite');
        Route::delete('settings/staff/invitations/{invitation}', [StaffController::class, 'revoke'])->name('staff.revoke');
        Route::patch('settings/staff/{user}', [StaffController::class, 'updateRole'])->name('staff.role');
        Route::delete('settings/staff/{user}', [StaffController::class, 'remove'])->name('staff.remove');
        Route::get('settings/roles', [StaffController::class, 'roles'])->name('staff.roles');

        Route::get('dashboard', DashboardController::class)->name('dashboard');

        // Catalog. Static paths first so they are not captured by {product}.
        Route::get('products/missing-images', [MissingImagesController::class, 'index'])->name('products.missing-images');
        Route::get('products/missing-images/next', [MissingImagesController::class, 'next'])->name('products.missing-images.next');
        Route::resource('products', ProductController::class)->except('show');

        Route::inertia('more', 'More/Index')->name('more');
        Route::get('orders/product-search', [OrderController::class, 'productSearch'])->name('orders.product-search');
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/create', [OrderController::class, 'create'])->name('orders.create');
        Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/status', [OrderController::class, 'transition'])->name('orders.transition');
        Route::get('orders/{order}/invoice', [OrderController::class, 'invoice'])->name('orders.invoice');

        Route::get('shipments', [ShipmentController::class, 'index'])->name('shipments.index');
        Route::post('orders/{order}/ship', [ShipmentController::class, 'book'])->middleware('throttle:30,1')->name('shipments.book');
        Route::post('shipments/{shipment}/refresh', [ShipmentController::class, 'refresh'])->middleware('throttle:60,1')->name('shipments.refresh');
        Route::post('shipments/{shipment}/reconcile', [ShipmentController::class, 'reconcile'])->middleware('throttle:30,1')->name('shipments.reconcile');

        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');

        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
        Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements');

        Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
        Route::get('imports/products/template', [ImportController::class, 'template'])->name('imports.template');
        Route::get('imports/orders/template', [ImportController::class, 'template'])->defaults('type', 'orders')->name('imports.template.orders');
        Route::post('imports/products', [ImportController::class, 'upload'])->middleware('throttle:20,1')->defaults('type', 'products')->name('imports.upload');
        Route::post('imports/orders', [ImportController::class, 'upload'])->middleware('throttle:20,1')->defaults('type', 'orders')->name('imports.upload.orders');
        Route::get('imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        Route::post('imports/{import}/mapping', [ImportController::class, 'mapping'])->name('imports.mapping');
        Route::post('imports/{import}/commit', [ImportController::class, 'commit'])->name('imports.commit');
        Route::post('imports/{import}/retry', [ImportController::class, 'retry'])->name('imports.retry');
        Route::post('imports/{import}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');
        Route::get('imports/{import}/errors', [ImportController::class, 'errors'])->name('imports.errors');

        Route::get('media/{image}/{size}', [MediaController::class, 'show'])->name('media.show');

        Route::prefix('products/{product}/images')->name('products.images.')->group(function () {
            Route::get('/', [ProductImageController::class, 'index'])->name('index');
            Route::post('/', [ProductImageController::class, 'store'])->middleware('throttle:image-uploads')->name('store');
            Route::post('reorder', [ProductImageController::class, 'reorder'])->name('reorder');
            Route::delete('/', [ProductImageController::class, 'destroy'])->name('destroy');
            Route::post('{image}/replace', [ProductImageController::class, 'replace'])->middleware('throttle:image-uploads')->name('replace');
            Route::patch('{image}', [ProductImageController::class, 'update'])->name('update');
        });
    });
});
