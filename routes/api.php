<?php

use App\Http\Controllers\Api\Attribute\AttributeController;
use App\Http\Controllers\Api\Billing\BillingController;
use App\Http\Controllers\Api\Category\CategoryController;
use App\Http\Controllers\Api\Company\CompanyController;
use App\Http\Controllers\Api\Location\LocationController;
use App\Http\Controllers\Api\Setting\SettingController;
use App\Http\Controllers\Api\Staff\StaffController;
use App\Http\Controllers\Api\StaffRole\StaffRoleController;
use App\Http\Controllers\Api\Team\TeamController;
use App\Http\Controllers\Api\User\UserController;
use App\Http\Controllers\Api\V1\Auth\LegacyAuthController;
use App\Http\Controllers\Api\V1\Auth\SaasAuthController;
use App\Http\Controllers\Api\V1\Customer\CustomerController;
use App\Http\Controllers\Api\V1\CustomerReturn\CustomerReturnController;
use App\Http\Controllers\Api\V1\Product\ProductController;
use App\Http\Controllers\Api\V1\Shipping\ShippingAddressController;
use App\Http\Controllers\Api\V1\Shipping\OrderShipmentController;
use App\Http\Controllers\Api\V1\StockTransfer\StockTransferController;
use App\Http\Controllers\Api\V1\Vendor\VendorController;
use App\Http\Controllers\Api\V1\VendorReturn\VendorReturnController;
use App\Http\Controllers\Api\V1\Salary\SalaryPaymentController;
use App\Http\Controllers\Api\V1\Coupon\CouponController;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Controllers\Api\V1\Sell\SellController;
use App\Http\Middleware\JwtAuthMiddleware;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────────────────────────────────────
// Legacy Auth
// ─────────────────────────────────────────────────────────────────────────────

Route::post('/login', [LegacyAuthController::class, 'login']);

Route::middleware(JwtAuthMiddleware::class)->group(function () {
    Route::post('/logout', [LegacyAuthController::class, 'logout']);
});

// ─────────────────────────────────────────────────────────────────────────────
// SaaS Auth — public endpoints
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('auth')->group(function () {
    Route::post('/signup',               [SaasAuthController::class, 'signup']);
    Route::post('/verify-email',         [SaasAuthController::class, 'verifyEmail']);
    Route::post('/resend-verification',  [SaasAuthController::class, 'resendVerification']);
    Route::post('/login',                [SaasAuthController::class, 'login']);
    Route::post('/forgot-password',      [SaasAuthController::class, 'forgotPassword']);
    Route::post('/reset-password',       [SaasAuthController::class, 'resetPassword']);
});

// ─────────────────────────────────────────────────────────────────────────────
// SaaS Auth — protected endpoints (require Bearer JWT)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('auth')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::post('/logout',           [SaasAuthController::class, 'logout']);
    Route::post('/update-password',  [SaasAuthController::class, 'updatePassword']);
    Route::get('/me',                [SaasAuthController::class, 'me']);
});

// Public accept invitation endpoint
Route::post('/auth/accept-invitation', [SaasAuthController::class, 'acceptInvitation']);

// ─────────────────────────────────────────────────────────────────────────────
// Staff Roles (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('staff-roles')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                 [StaffRoleController::class, 'index']);
    Route::post('/',                [StaffRoleController::class, 'store']);
    Route::get('/permissions',      [StaffRoleController::class, 'permissions']);
    Route::get('/{id}',             [StaffRoleController::class, 'show']);
    Route::put('/{id}',             [StaffRoleController::class, 'update']);
    Route::delete('/{id}',          [StaffRoleController::class, 'destroy']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Company Profile & Settings (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('auth/company')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/profile',       [CompanyController::class, 'profile']);
    Route::put('/profile',       [CompanyController::class, 'updateProfile']);
    Route::get('/status',        [CompanyController::class, 'status']);
    Route::get('/settings',      [CompanyController::class, 'settings']);
    Route::put('/settings',      [CompanyController::class, 'upsertSettings']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Billing (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('billing')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/plans',                [BillingController::class, 'plans']);
    Route::get('/subscription',         [BillingController::class, 'subscription']);
    Route::get('/payments',             [BillingController::class, 'payments']);
    Route::post('/renew',               [BillingController::class, 'renew']);
    Route::post('/cancel',              [BillingController::class, 'cancel']);
    Route::post('/upgrade',             [BillingController::class, 'upgrade']);
    Route::post('/create-subscription', [BillingController::class, 'createSubscription']);
    Route::get('/contact',              [BillingController::class, 'contact']);
    Route::put('/contact',              [BillingController::class, 'upsertContact']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Team/Invitations (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('auth/team')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                          [TeamController::class, 'index']);
    Route::post('/invite',                   [TeamController::class, 'invite']);
    Route::put('/{userId}/role',             [TeamController::class, 'updateRole']);
    Route::delete('/{userId}',               [TeamController::class, 'remove']);
    Route::post('/{invitationId}/resend-invitation', [TeamController::class, 'resendInvitation']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Legacy Users CRUD (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('users')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',     [UserController::class, 'index']);
    Route::post('/',    [UserController::class, 'store']);
    Route::get('/{id}', [UserController::class, 'show']);
    Route::put('/{id}', [UserController::class, 'update']);
    Route::delete('/{id}', [UserController::class, 'destroy']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Staff (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('staff')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',     [StaffController::class, 'index']);
    Route::post('/',    [StaffController::class, 'store']);
    Route::get('/stats', [StaffController::class, 'stats']);
    Route::get('/{id}', [StaffController::class, 'show']);
    Route::put('/{id}', [StaffController::class, 'update']);
    Route::delete('/{id}', [StaffController::class, 'destroy']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 3: Master Data (Categories, Attributes, Locations, Settings)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('categories')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                      [CategoryController::class, 'index']);
    Route::get('/simple',                [CategoryController::class, 'simple']);
    Route::get('/stats',                 [CategoryController::class, 'stats']);
    Route::post('/',                     [CategoryController::class, 'store']);
    Route::post('/bulk-delete',          [CategoryController::class, 'bulkDelete']);
    Route::get('/{id}',                  [CategoryController::class, 'show']);
    Route::put('/{id}',                  [CategoryController::class, 'update']);
    Route::patch('/{id}/toggle-status',  [CategoryController::class, 'toggleStatus']);
    Route::delete('/{id}',               [CategoryController::class, 'destroy']);
});

Route::prefix('attributes')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                      [AttributeController::class, 'index']);
    Route::get('/simple',                [AttributeController::class, 'simple']);
    Route::get('/stats',                 [AttributeController::class, 'stats']);
    Route::post('/',                     [AttributeController::class, 'store']);
    Route::post('/bulk-delete',          [AttributeController::class, 'bulkDelete']);
    Route::get('/{id}',                  [AttributeController::class, 'show']);
    Route::put('/{id}',                  [AttributeController::class, 'update']);
    Route::patch('/{id}/toggle-status',  [AttributeController::class, 'toggleStatus']);
    Route::delete('/{id}',               [AttributeController::class, 'destroy']);
});

Route::prefix('locations')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',     [LocationController::class, 'index']);
    Route::post('/',    [LocationController::class, 'store']);
    Route::get('/{id}', [LocationController::class, 'show']);
    Route::put('/{id}', [LocationController::class, 'update']);
    Route::delete('/{id}', [LocationController::class, 'destroy']);
});

Route::prefix('settings')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                      [SettingController::class, 'index']);
    Route::put('/general',               [SettingController::class, 'updateGeneral']);
    Route::patch('/general',             [SettingController::class, 'updateGeneral']);
    Route::patch('/tax',                 [SettingController::class, 'updateTax']);
    Route::patch('/shipping',            [SettingController::class, 'updateShipping']);
    Route::patch('/payment',             [SettingController::class, 'updatePayment']);
    Route::patch('/business',            [SettingController::class, 'updateBusiness']);
    Route::patch('/regional',            [SettingController::class, 'updateRegional']);
    Route::patch('/notifications',       [SettingController::class, 'updateNotifications']);
    Route::patch('/store-hours',         [SettingController::class, 'updateStoreHours']);
    Route::post('/change-password',      [SettingController::class, 'changePassword']);
    Route::post('/upload-logo',          [SettingController::class, 'uploadLogo']);
    Route::post('/upload-banner',        [SettingController::class, 'uploadBanner']);
    Route::put('/{section}',             [SettingController::class, 'updateSection']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 4: Products & Inventory
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('products')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                  [ProductController::class, 'index']);
    Route::post('/',                 [ProductController::class, 'store']);
    Route::get('/stats',             [ProductController::class, 'stats']);
    Route::get('/{id}',              [ProductController::class, 'show']);
    Route::put('/{id}',              [ProductController::class, 'update']);
    Route::patch('/{id}/status',     [ProductController::class, 'updateStatus']);
    Route::delete('/{id}',           [ProductController::class, 'destroy']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 5: Vendors & Purchasing
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('vendors')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',     [VendorController::class, 'index']);
    Route::post('/',    [VendorController::class, 'store']);
    Route::get('/stats', [VendorController::class, 'stats']);
    Route::get('/{id}', [VendorController::class, 'show']);
    Route::put('/{id}', [VendorController::class, 'update']);
    Route::delete('/{id}', [VendorController::class, 'destroy']);
});

Route::prefix('vendor-returns')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                      [VendorReturnController::class, 'index']);
    Route::post('/',                     [VendorReturnController::class, 'store']);
    Route::get('/stats',                 [VendorReturnController::class, 'stats']);
    Route::get('/{id}',                  [VendorReturnController::class, 'show']);
    Route::put('/{id}',                  [VendorReturnController::class, 'update']);
    Route::patch('/{id}/status',         [VendorReturnController::class, 'updateStatus']);
    Route::delete('/{id}',               [VendorReturnController::class, 'destroy']);
    Route::get('/vendor/{vendorId}',     [VendorReturnController::class, 'getByVendor']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 6: Customers & Sales Orders
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('customers')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',     [CustomerController::class, 'index']);
    Route::post('/',    [CustomerController::class, 'store']);
    Route::get('/stats', [CustomerController::class, 'stats']);
    Route::get('/{id}', [CustomerController::class, 'show']);
    Route::put('/{id}', [CustomerController::class, 'update']);
    Route::delete('/{id}', [CustomerController::class, 'destroy']);
});

Route::prefix('sells')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                          [SellController::class, 'index']);
    Route::post('/',                         [SellController::class, 'store']);
    Route::get('/stats',                     [SellController::class, 'stats']);
    Route::get('/invoice/{invoiceNo}',       [SellController::class, 'getByInvoice']);
    Route::get('/{id}',                      [SellController::class, 'show']);
    Route::put('/{id}',                      [SellController::class, 'update']);
    Route::patch('/{id}/status',             [SellController::class, 'updateStatus']);
    Route::delete('/{id}',                   [SellController::class, 'destroy']);
});

Route::prefix('customer-returns')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                          [CustomerReturnController::class, 'index']);
    Route::post('/',                         [CustomerReturnController::class, 'store']);
    Route::get('/stats',                     [CustomerReturnController::class, 'stats']);
    Route::get('/{id}',                      [CustomerReturnController::class, 'show']);
    Route::put('/{id}',                      [CustomerReturnController::class, 'update']);
    Route::post('/{id}/approve',             [CustomerReturnController::class, 'approve']);
    Route::post('/{id}/reject',              [CustomerReturnController::class, 'reject']);
    Route::delete('/{id}',                   [CustomerReturnController::class, 'destroy']);
    Route::get('/customer/{customerId}',     [CustomerReturnController::class, 'getByCustomer']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 7: Shipping & Fulfillment
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('shipping-addresses')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                  [ShippingAddressController::class, 'index']);
    Route::post('/',                 [ShippingAddressController::class, 'store']);
    Route::get('/{id}',              [ShippingAddressController::class, 'show']);
    Route::put('/{id}',              [ShippingAddressController::class, 'update']);
    Route::patch('/{id}/set-default', [ShippingAddressController::class, 'setDefault']);
    Route::delete('/{id}',           [ShippingAddressController::class, 'destroy']);
});

Route::prefix('shipments')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                  [OrderShipmentController::class, 'index']);
    Route::post('/',                 [OrderShipmentController::class, 'store']);
    Route::get('/stats',             [OrderShipmentController::class, 'stats']);
    Route::get('/{id}',              [OrderShipmentController::class, 'show']);
    Route::patch('/{id}/status',     [OrderShipmentController::class, 'updateStatus']);
    Route::post('/{id}/tracking',    [OrderShipmentController::class, 'addTracking']);
});

// Public tracking endpoint (no auth)
Route::get('/track/{trackingNumber}', [OrderShipmentController::class, 'publicTracking']);

// ─────────────────────────────────────────────────────────────────────────────
// Phase 8: Staff & Payroll
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('salary-payments')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',     [SalaryPaymentController::class, 'index']);
    Route::post('/',    [SalaryPaymentController::class, 'store']);
    Route::get('/{id}', [SalaryPaymentController::class, 'show']);
    Route::put('/{id}', [SalaryPaymentController::class, 'update']);
    Route::delete('/{id}', [SalaryPaymentController::class, 'destroy']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 9: Stock Transfers & Inventory Management
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('transfers')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/products-by-location/{locationId}', [StockTransferController::class, 'getProductsByLocation']);
    Route::get('/', [StockTransferController::class, 'index']);
    Route::post('/', [StockTransferController::class, 'store']);
    Route::put('/{id}/cancel', [StockTransferController::class, 'cancelTransfer']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 10: Coupons & Discounts
// ─────────────────────────────────────────────────────────────────────────────

// Public coupon lookup (no authentication required)
Route::get('/coupons/code/{code}', [CouponController::class, 'getByCode']);

Route::prefix('coupons')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/', [CouponController::class, 'index']);
    Route::post('/', [CouponController::class, 'store']);
    Route::post('/with-image', [CouponController::class, 'storeWithImage']);
    Route::post('/validate', [CouponController::class, 'validateCoupon']);
    Route::get('/{id}', [CouponController::class, 'show']);
    Route::put('/{id}', [CouponController::class, 'update']);
    Route::put('/{id}/with-image', [CouponController::class, 'updateWithImage']);
    Route::delete('/{id}', [CouponController::class, 'destroy']);
    Route::get('/{id}/usage-stats', [CouponController::class, 'getUsageStats']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 11: Inventory View (Read-Only)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('inventory')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/', [InventoryController::class, 'index']);
});
