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
use App\Http\Controllers\Api\V1\CustomerReturn\StorefrontCustomerReturnController;
use App\Http\Controllers\Api\V1\Product\ProductController;
use App\Http\Controllers\Api\V1\Product\ProductReviewReplyController;
use App\Http\Controllers\Api\V1\Shipping\ShippingAddressController;
use App\Http\Controllers\Api\V1\ShippingMethodController;
use App\Http\Controllers\Api\V1\PaymentMethodController;
use App\Http\Controllers\Api\V1\Shipping\OrderShipmentController;
use App\Http\Controllers\Api\V1\StockTransfer\StockTransferController;
use App\Http\Controllers\Api\V1\Vendor\VendorController;
use App\Http\Controllers\Api\V1\VendorReturn\VendorReturnController;
use App\Http\Controllers\Api\V1\PurchaseOrder\PurchaseOrderController;
use App\Http\Controllers\Api\V1\SerialBatch\SerialBatchController;
use App\Http\Controllers\Api\V1\Salary\SalaryPaymentController;
use App\Http\Controllers\Api\V1\Coupon\CouponController;
use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Controllers\Api\V1\Sell\SellController;
use App\Http\Controllers\Api\Notification\NotificationController;
use App\Http\Controllers\Api\Realtime\BroadcastAuthController;
use App\Http\Controllers\Api\Support\SupportTicketController;
use App\Http\Controllers\Api\Storefront\CustomerAuthController;
use App\Http\Controllers\Api\Storefront\StorefrontController;
use App\Http\Controllers\Api\Storefront\StorefrontCustomerController;
use App\Http\Controllers\Api\Storefront\StorefrontOrderController;
use App\Http\Controllers\Api\Storefront\StorefrontProductReviewController;
use App\Http\Controllers\Api\Storefront\StorefrontSupportController;
use App\Http\Controllers\Api\Platform\PlatformController;
use App\Http\Controllers\Api\Storefront\WishlistController;
use App\Http\Controllers\Api\Storefront\WishlistAnalyticsController;
use App\Http\Controllers\Api\V1\ContentPageController;
use App\Http\Controllers\Api\Gateway\GatewayController;
use App\Http\Controllers\Api\Tailor\TailorController;
use App\Http\Middleware\JwtAuthMiddleware;
use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────────────────────────────────────
// Platform Admin API — super_admin only
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('platform')->middleware([JwtAuthMiddleware::class, 'super_admin'])->group(function () {
    Route::get('/stats',                                      [PlatformController::class, 'stats']);

    // Companies
    Route::get('/companies',                                  [PlatformController::class, 'listCompanies']);
    Route::get('/companies/{id}',                             [PlatformController::class, 'getCompany']);
    Route::patch('/companies/{id}/status',                    [PlatformController::class, 'updateCompanyStatus']);
    Route::get('/companies/{id}/users',                       [PlatformController::class, 'listCompanyUsers']);
    Route::post('/companies/{id}/subscription',               [PlatformController::class, 'assignSubscription']);
    Route::delete('/companies/{id}/subscription',             [PlatformController::class, 'cancelSubscription']);

    // Plans
    Route::get('/plans',                                      [PlatformController::class, 'listPlans']);
    Route::post('/plans',                                     [PlatformController::class, 'createPlan']);
    Route::put('/plans/{id}',                                 [PlatformController::class, 'updatePlan']);
    Route::patch('/plans/{id}/toggle',                        [PlatformController::class, 'togglePlanStatus']);

    // Super admin management
    Route::post('/admins',                                    [PlatformController::class, 'createSuperAdmin']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Public Storefront API — scoped by company_id query param
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('store')->group(function () {

    // Catalog (no auth)
    Route::get('/products',           [StorefrontController::class, 'products']);
    Route::get('/deals',              [StorefrontController::class, 'deals']);
    Route::get('/products/{id}',      [StorefrontController::class, 'product']);
    Route::get('/products/{id}/reviews', [StorefrontProductReviewController::class, 'index']);
    Route::get('/categories',         [StorefrontController::class, 'categories']);
    Route::get('/coupons/validate',   [StorefrontController::class, 'validateCoupon']);
    Route::get('/coupons/active',     [StorefrontController::class, 'activeCoupons']);
    Route::get('/shipping-methods',   [StorefrontController::class, 'shippingMethods']);
    Route::get('/payment-methods',    [StorefrontController::class, 'paymentMethods']);
    Route::get('/pages/{slug}',       [StorefrontController::class, 'page']);
    Route::get('/settings/company',   [StorefrontController::class, 'companySettings']);
    Route::get('/settings/homepage-hero', [StorefrontController::class, 'homepageHero']);
    Route::get('/settings/promo-banner',  [StorefrontController::class, 'promoBanner']);
    Route::get('/stats',              [StorefrontController::class, 'stats']);

    // Public order tracking (no auth)
    Route::get('/orders/track',        [StorefrontOrderController::class, 'trackOrder']);

    // Customer auth (no auth)
    Route::post('/customer/register', [CustomerAuthController::class, 'register']);
    Route::post('/customer/login',    [CustomerAuthController::class, 'login']);
    Route::post('/contact',           [StorefrontSupportController::class, 'contact']);
    Route::post('/realtime/auth',     BroadcastAuthController::class);
    Route::get('/support/guest/{ticketNumber}', [StorefrontSupportController::class, 'showGuest']);
    Route::post('/support/guest/{ticketNumber}/reply', [StorefrontSupportController::class, 'replyGuest']);

    // Customer protected routes
    Route::middleware('customer.auth')->group(function () {
        Route::get('/orders',          [StorefrontOrderController::class, 'index']);
        Route::post('/orders',         [StorefrontOrderController::class, 'store']);
        Route::get('/orders/{id}',     [StorefrontOrderController::class, 'show']);
        Route::get('/profile',         [StorefrontCustomerController::class, 'show']);
        Route::put('/profile',         [StorefrontCustomerController::class, 'update']);
        Route::get('/addresses',          [StorefrontCustomerController::class, 'addresses']);
        Route::post('/addresses',         [StorefrontCustomerController::class, 'addAddress']);
        Route::put('/addresses/{id}',     [StorefrontCustomerController::class, 'updateAddress']);
        Route::delete('/addresses/{id}',  [StorefrontCustomerController::class, 'deleteAddress']);

        // Wishlist
        Route::get('/wishlist',                   [WishlistController::class, 'index']);
        Route::get('/wishlist/ids',               [WishlistController::class, 'ids']);
        Route::get('/wishlist/check/{productId}', [WishlistController::class, 'check']);
        Route::post('/wishlist',                  [WishlistController::class, 'store']);
        Route::delete('/wishlist',                [WishlistController::class, 'clear']);
        Route::delete('/wishlist/{productId}',    [WishlistController::class, 'destroy']);
        Route::post('/products/{id}/reviews',     [StorefrontProductReviewController::class, 'store']);

        // Support tickets (customer)
        Route::get('/support/tickets',             [StorefrontSupportController::class, 'index']);
        Route::post('/support/tickets',            [StorefrontSupportController::class, 'store']);
        Route::get('/support/tickets/{id}',        [StorefrontSupportController::class, 'show']);
        Route::post('/support/tickets/{id}/reply', [StorefrontSupportController::class, 'reply']);

        // Customer returns (store-side)
        Route::get('/returns',      [StorefrontCustomerReturnController::class, 'index']);
        Route::post('/returns',     [StorefrontCustomerReturnController::class, 'store']);
        Route::get('/returns/{id}', [StorefrontCustomerReturnController::class, 'show']);
        Route::delete('/returns/{id}', [StorefrontCustomerReturnController::class, 'cancel']);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Payment Gateway Callbacks — public, no auth (gateways POST here)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('gateway')->group(function () {
    // SSLCommerz
    Route::post('/sslcommerz/success',  [GatewayController::class, 'sslSuccess']);
    Route::post('/sslcommerz/fail',     [GatewayController::class, 'sslFail']);
    Route::post('/sslcommerz/cancel',   [GatewayController::class, 'sslCancel']);
    Route::post('/sslcommerz/ipn',      [GatewayController::class, 'sslIpn']);
    // PortWallet
    Route::post('/portwallet/callback', [GatewayController::class, 'portwalletCallback']);
    // Stripe — success/cancel are GET (browser redirect), webhook is POST
    Route::get('/stripe/success',       [GatewayController::class, 'stripeSuccess']);
    Route::get('/stripe/cancel',        [GatewayController::class, 'stripeCancel']);
    Route::post('/stripe/webhook',      [GatewayController::class, 'stripeWebhook']);
    // PayPal — success/cancel are GET (browser redirect)
    Route::get('/paypal/success',       [GatewayController::class, 'paypalSuccess']);
    Route::get('/paypal/cancel',        [GatewayController::class, 'paypalCancel']);
    // bKash — callback is GET (bKash redirects browser back)
    Route::get('/bkash/callback',       [GatewayController::class, 'bkashCallback']);
    // Nagad — callback is GET
    Route::get('/nagad/callback',       [GatewayController::class, 'nagadCallback']);

    // COD Shipping Deposit callbacks (same gateways, different status outcome)
    Route::prefix('cod-deposit')->group(function () {
        Route::post('/sslcommerz/success',  [GatewayController::class, 'codDepositSslSuccess']);
        Route::post('/sslcommerz/fail',     [GatewayController::class, 'codDepositSslFail']);
        Route::post('/sslcommerz/cancel',   [GatewayController::class, 'codDepositSslCancel']);
        Route::post('/sslcommerz/ipn',      [GatewayController::class, 'codDepositSslIpn']);
        Route::post('/portwallet/callback', [GatewayController::class, 'codDepositPortwalletCallback']);
        Route::get('/bkash/callback',       [GatewayController::class, 'codDepositBkashCallback']);
        Route::get('/nagad/callback',       [GatewayController::class, 'codDepositNagadCallback']);
        Route::get('/stripe/success',       [GatewayController::class, 'codDepositStripeSuccess']);
        Route::get('/stripe/cancel',        [GatewayController::class, 'codDepositStripeCancel']);
        Route::get('/paypal/success',       [GatewayController::class, 'codDepositPaypalSuccess']);
        Route::get('/paypal/cancel',        [GatewayController::class, 'codDepositPaypalCancel']);
    });
});

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

Route::post('/realtime/auth', BroadcastAuthController::class)->middleware(JwtAuthMiddleware::class);

// Public accept invitation endpoint
Route::post('/auth/accept-invitation', [SaasAuthController::class, 'acceptInvitation']);

// ─────────────────────────────────────────────────────────────────────────────
// Staff Roles (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('staff-roles')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                 [StaffRoleController::class, 'index'])->middleware('check_permission:Role & Permission.read');
    Route::post('/',                [StaffRoleController::class, 'store'])->middleware('check_permission:Role & Permission.write');
    Route::get('/permissions',      [StaffRoleController::class, 'permissions']); // open — needed for role form
    Route::get('/{id}',             [StaffRoleController::class, 'show'])->middleware('check_permission:Role & Permission.read');
    Route::put('/{id}',             [StaffRoleController::class, 'update'])->middleware('check_permission:Role & Permission.write');
    Route::delete('/{id}',          [StaffRoleController::class, 'destroy'])->middleware('check_permission:Role & Permission.delete');
});

// ─────────────────────────────────────────────────────────────────────────────
// Company Profile & Settings (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('auth/company')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/profile',       [CompanyController::class, 'profile'])->middleware('check_permission:Company Profile.read');
    Route::put('/profile',       [CompanyController::class, 'updateProfile'])->middleware('check_permission:Company Profile.write');
    Route::get('/status',        [CompanyController::class, 'status']);
    Route::get('/settings',      [CompanyController::class, 'settings'])->middleware('check_permission:Company Settings.read');
    Route::put('/settings',      [CompanyController::class, 'upsertSettings'])->middleware('check_permission:Company Settings.write');
    Route::get('/plan-limits',   [CompanyController::class, 'planLimits']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Billing (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('billing')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/plans',                [BillingController::class, 'plans'])->middleware('check_permission:Billing Plans.read');
    Route::put('/plans/{id}',           [BillingController::class, 'updatePlan'])->middleware('check_permission:Billing Plans.write');
    Route::get('/subscription',         [BillingController::class, 'subscription'])->middleware('check_permission:Subscriptions.read');
    Route::get('/payments',             [BillingController::class, 'payments'])->middleware('check_permission:Subscriptions.read');
    Route::post('/renew',               [BillingController::class, 'renew'])->middleware('check_permission:Subscriptions.write');
    Route::post('/cancel',              [BillingController::class, 'cancel'])->middleware('check_permission:Subscriptions.write');
    Route::post('/upgrade',             [BillingController::class, 'upgrade'])->middleware('check_permission:Subscriptions.write');
    Route::post('/create-subscription', [BillingController::class, 'createSubscription'])->middleware('check_permission:Subscriptions.write');
    Route::get('/contact',              [BillingController::class, 'contact'])->middleware('check_permission:Billing Contact.read');
    Route::put('/contact',              [BillingController::class, 'upsertContact'])->middleware('check_permission:Billing Contact.write');
});

// ─────────────────────────────────────────────────────────────────────────────
// Team/Invitations (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('auth/team')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                          [TeamController::class, 'index'])->middleware('check_permission:Team Members.read');
    Route::post('/invite',                   [TeamController::class, 'invite'])->middleware('check_permission:Team Members.write');
    Route::put('/{userId}/role',             [TeamController::class, 'updateRole'])->middleware('check_permission:Team Members.write');
    Route::delete('/{userId}',               [TeamController::class, 'remove'])->middleware('check_permission:Team Members.delete');
    Route::post('/{invitationId}/resend-invitation', [TeamController::class, 'resendInvitation'])->middleware('check_permission:Team Members.write');
});

// ─────────────────────────────────────────────────────────────────────────────
// Legacy Users CRUD (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('users')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',        [UserController::class, 'index'])->middleware('check_permission:User Management.read');
    Route::post('/',       [UserController::class, 'store'])->middleware('check_permission:User Management.write');
    Route::get('/{id}',    [UserController::class, 'show'])->middleware('check_permission:User Management.read');
    Route::put('/{id}',    [UserController::class, 'update'])->middleware('check_permission:User Management.write');
    Route::delete('/{id}', [UserController::class, 'destroy'])->middleware('check_permission:User Management.delete');
});

// ─────────────────────────────────────────────────────────────────────────────
// Staff (protected)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('staff')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',        [StaffController::class, 'index'])->middleware('check_permission:Staff.read');
    Route::post('/',       [StaffController::class, 'store'])->middleware('check_permission:Staff.write');
    Route::get('/stats',   [StaffController::class, 'stats'])->middleware('check_permission:Staff.read');
    Route::get('/{id}',    [StaffController::class, 'show'])->middleware('check_permission:Staff.read');
    Route::put('/{id}',    [StaffController::class, 'update'])->middleware('check_permission:Staff.write');
    Route::delete('/{id}', [StaffController::class, 'destroy'])->middleware('check_permission:Staff.delete');
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 3: Master Data (Categories, Attributes, Locations, Settings)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('categories')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                      [CategoryController::class, 'index'])->middleware('check_permission:Categories.read');
    Route::get('/simple',                [CategoryController::class, 'simple'])->middleware('check_permission:Categories.read');
    Route::get('/stats',                 [CategoryController::class, 'stats'])->middleware('check_permission:Categories.read');
    Route::post('/',                     [CategoryController::class, 'store'])->middleware('check_permission:Categories.write');
    Route::post('/bulk-delete',          [CategoryController::class, 'bulkDelete'])->middleware('check_permission:Categories.delete');
    Route::get('/{id}',                  [CategoryController::class, 'show'])->middleware('check_permission:Categories.read');
    Route::put('/{id}',                  [CategoryController::class, 'update'])->middleware('check_permission:Categories.write');
    Route::patch('/{id}/toggle-status',  [CategoryController::class, 'toggleStatus'])->middleware('check_permission:Categories.write');
    Route::delete('/{id}',               [CategoryController::class, 'destroy'])->middleware('check_permission:Categories.delete');
});

Route::prefix('attributes')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                      [AttributeController::class, 'index'])->middleware('check_permission:Attributes.read');
    Route::get('/simple',                [AttributeController::class, 'simple'])->middleware('check_permission:Attributes.read');
    Route::get('/stats',                 [AttributeController::class, 'stats'])->middleware('check_permission:Attributes.read');
    Route::post('/',                     [AttributeController::class, 'store'])->middleware('check_permission:Attributes.write');
    Route::post('/bulk-delete',          [AttributeController::class, 'bulkDelete'])->middleware('check_permission:Attributes.delete');
    Route::get('/{id}',                  [AttributeController::class, 'show'])->middleware('check_permission:Attributes.read');
    Route::put('/{id}',                  [AttributeController::class, 'update'])->middleware('check_permission:Attributes.write');
    Route::patch('/{id}/toggle-status',  [AttributeController::class, 'toggleStatus'])->middleware('check_permission:Attributes.write');
    Route::delete('/{id}',               [AttributeController::class, 'destroy'])->middleware('check_permission:Attributes.delete');
});

Route::prefix('locations')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',        [LocationController::class, 'index'])->middleware('check_permission:Store Locations.read');
    Route::post('/',       [LocationController::class, 'store'])->middleware('check_permission:Store Locations.write');
    Route::get('/{id}',    [LocationController::class, 'show'])->middleware('check_permission:Store Locations.read');
    Route::put('/{id}',    [LocationController::class, 'update'])->middleware('check_permission:Store Locations.write');
    Route::delete('/{id}', [LocationController::class, 'destroy'])->middleware('check_permission:Store Locations.delete');
});

Route::prefix('settings')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                      [SettingController::class, 'index'])->middleware('check_permission:Settings.read');
    Route::put('/general',               [SettingController::class, 'updateGeneral'])->middleware('check_permission:Settings.write');
    Route::patch('/general',             [SettingController::class, 'updateGeneral'])->middleware('check_permission:Settings.write');
    Route::patch('/tax',                 [SettingController::class, 'updateTax'])->middleware('check_permission:Settings.write');
    Route::patch('/shipping',            [SettingController::class, 'updateShipping'])->middleware('check_permission:Settings.write');
    Route::patch('/payment',             [SettingController::class, 'updatePayment'])->middleware('check_permission:Settings.write');
    Route::patch('/business',            [SettingController::class, 'updateBusiness'])->middleware('check_permission:Settings.write');
    Route::patch('/regional',            [SettingController::class, 'updateRegional'])->middleware('check_permission:International.write');
    Route::patch('/notifications',       [SettingController::class, 'updateNotifications'])->middleware('check_permission:Settings.write');
    Route::get('/store-hours',           [SettingController::class, 'getStoreHours'])->middleware('check_permission:Settings.read');
    Route::patch('/store-hours',         [SettingController::class, 'updateStoreHours'])->middleware('check_permission:Settings.write');
    Route::post('/change-password',      [SettingController::class, 'changePassword']); // own profile — no permission gate
    Route::post('/upload-logo',          [SettingController::class, 'uploadLogo'])->middleware('check_permission:Settings.write');
    Route::post('/upload-banner',        [SettingController::class, 'uploadBanner'])->middleware('check_permission:Settings.write');
    Route::post('/upload-storefront-image', [SettingController::class, 'uploadStorefrontImage'])->middleware('check_permission:Aura Shop.write');
    Route::put('/{section}',             [SettingController::class, 'updateSection'])->middleware('check_permission:Settings.write');
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 4: Products & Inventory
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('products')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',      [ProductController::class, 'index'])->middleware('check_permission:Products.read');
    Route::post('/',     [ProductController::class, 'store'])->middleware('check_permission:Products.write');
    Route::get('/stats', [ProductController::class, 'stats'])->middleware('check_permission:Products.read');

    // Barcode endpoints (Display)
    Route::post('/barcode/search',          [ProductController::class, 'findByBarcode'])->middleware('check_permission:Products.read');
    Route::post('/barcode/bulk-generate',   [ProductController::class, 'bulkGenerateBarcodes'])->middleware('check_permission:Print Barcode.write');
    Route::get('/{id}/barcode',             [ProductController::class, 'getBarcode'])->middleware('check_permission:Print Barcode.read');
    Route::post('/{id}/barcode/regenerate', [ProductController::class, 'regenerateBarcode'])->middleware('check_permission:Print Barcode.write');

    // Barcode endpoints (POS Scanning)
    Route::post('/barcode/find-by-code',    [ProductController::class, 'findByBarcodeCode'])->middleware('check_permission:Products.read');
    Route::post('/barcode/generate-missing',[ProductController::class, 'generateMissingBarcodes'])->middleware('check_permission:Print Barcode.write');
    Route::get('/barcode/statistics',       [ProductController::class, 'getBarcodeStatistics'])->middleware('check_permission:Print Barcode.read');

    Route::get('/{id}',              [ProductController::class, 'show'])->middleware('check_permission:Products.read');
    Route::put('/{id}',              [ProductController::class, 'update'])->middleware('check_permission:Products.write');
    Route::patch('/{id}/status',     [ProductController::class, 'updateStatus'])->middleware('check_permission:Products.write');
    Route::post('/{productId}/reviews/{reviewId}/reply', [ProductReviewReplyController::class, 'store'])->middleware('check_permission:Product Reviews.write');
    Route::delete('/{id}',           [ProductController::class, 'destroy'])->middleware('check_permission:Products.delete');
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 5: Vendors & Purchasing
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('vendors')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',        [VendorController::class, 'index'])->middleware('check_permission:Vendors.read');
    Route::post('/',       [VendorController::class, 'store'])->middleware('check_permission:Vendors.write');
    Route::get('/stats',   [VendorController::class, 'stats'])->middleware('check_permission:Vendors.read');
    Route::get('/{id}',    [VendorController::class, 'show'])->middleware('check_permission:Vendors.read');
    Route::put('/{id}',    [VendorController::class, 'update'])->middleware('check_permission:Vendors.write');
    Route::delete('/{id}', [VendorController::class, 'destroy'])->middleware('check_permission:Vendors.delete');
});

Route::prefix('purchase-orders')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',           [PurchaseOrderController::class, 'index'])->middleware('check_permission:Vendors.read');
    Route::post('/',          [PurchaseOrderController::class, 'store'])->middleware('check_permission:Vendors.write');
    Route::get('/stats',      [PurchaseOrderController::class, 'stats'])->middleware('check_permission:Vendors.read');
    Route::get('/{id}',       [PurchaseOrderController::class, 'show'])->middleware('check_permission:Vendors.read');
    Route::put('/{id}',       [PurchaseOrderController::class, 'update'])->middleware('check_permission:Vendors.write');
    Route::patch('/{id}/status',  [PurchaseOrderController::class, 'updateStatus'])->middleware('check_permission:Vendors.write');
    Route::post('/{id}/receive',  [PurchaseOrderController::class, 'receive'])->middleware('check_permission:Vendors.write');
    Route::delete('/{id}',    [PurchaseOrderController::class, 'destroy'])->middleware('check_permission:Vendors.delete');
});

Route::prefix('vendor-returns')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                  [VendorReturnController::class, 'index'])->middleware('check_permission:Vendor Returns.read');
    Route::post('/',                 [VendorReturnController::class, 'store'])->middleware('check_permission:Vendor Returns.write');
    Route::get('/stats',             [VendorReturnController::class, 'stats'])->middleware('check_permission:Vendor Returns.read');
    Route::get('/{id}',              [VendorReturnController::class, 'show'])->middleware('check_permission:Vendor Returns.read');
    Route::put('/{id}',              [VendorReturnController::class, 'update'])->middleware('check_permission:Vendor Returns.write');
    Route::patch('/{id}/status',     [VendorReturnController::class, 'updateStatus'])->middleware('check_permission:Vendor Returns.write');
    Route::delete('/{id}',           [VendorReturnController::class, 'destroy'])->middleware('check_permission:Vendor Returns.delete');
    Route::get('/vendor/{vendorId}', [VendorReturnController::class, 'getByVendor'])->middleware('check_permission:Vendor Returns.read');
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 6: Customers & Sales Orders
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('customers')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',        [CustomerController::class, 'index'])->middleware('check_permission:Customers.read');
    Route::post('/',       [CustomerController::class, 'store'])->middleware('check_permission:Customers.write');
    Route::get('/stats',   [CustomerController::class, 'stats'])->middleware('check_permission:Customers.read');
    Route::get('/{id}',    [CustomerController::class, 'show'])->middleware('check_permission:Customers.read');
    Route::put('/{id}',    [CustomerController::class, 'update'])->middleware('check_permission:Customers.write');
    Route::delete('/{id}', [CustomerController::class, 'destroy'])->middleware('check_permission:Customers.delete');
});

Route::prefix('sells')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                    [SellController::class, 'index'])->middleware('check_permission:Orders.read');
    Route::post('/',                   [SellController::class, 'store'])->middleware('check_permission:Orders.write');
    Route::get('/stats',               [SellController::class, 'stats'])->middleware('check_permission:Orders.read');
    Route::get('/weekly-orders',       [SellController::class, 'weeklyOrders'])->middleware('check_permission:Orders.read');
    Route::get('/monthly-revenue',     [SellController::class, 'monthlyRevenue'])->middleware('check_permission:Orders.read');
    Route::get('/invoice/{invoiceNo}', [SellController::class, 'getByInvoice'])->middleware('check_permission:Orders.read');
    Route::get('/{id}',                [SellController::class, 'show'])->middleware('check_permission:Orders.read');
    Route::put('/{id}',                [SellController::class, 'update'])->middleware('check_permission:Orders.write');
    Route::patch('/{id}/status',       [SellController::class, 'updateStatus'])->middleware('check_permission:Orders.write');
    Route::delete('/{id}',             [SellController::class, 'destroy'])->middleware('check_permission:Orders.delete');
});

Route::prefix('customer-returns')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                      [CustomerReturnController::class, 'index'])->middleware('check_permission:Customer Returns.read');
    Route::post('/',                     [CustomerReturnController::class, 'store'])->middleware('check_permission:Customer Returns.write');
    Route::get('/stats',                 [CustomerReturnController::class, 'stats'])->middleware('check_permission:Customer Returns.read');
    Route::get('/{id}',                  [CustomerReturnController::class, 'show'])->middleware('check_permission:Customer Returns.read');
    Route::put('/{id}',                  [CustomerReturnController::class, 'update'])->middleware('check_permission:Customer Returns.write');
    Route::post('/{id}/approve',         [CustomerReturnController::class, 'approve'])->middleware('check_permission:Customer Returns.write');
    Route::post('/{id}/reject',          [CustomerReturnController::class, 'reject'])->middleware('check_permission:Customer Returns.write');
    Route::delete('/{id}',               [CustomerReturnController::class, 'destroy'])->middleware('check_permission:Customer Returns.delete');
    Route::get('/customer/{customerId}', [CustomerReturnController::class, 'getByCustomer'])->middleware('check_permission:Customer Returns.read');
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 7: Shipping & Fulfillment
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('payment-methods')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',               [PaymentMethodController::class, 'index'])->middleware('check_permission:Payment Methods.read');
    Route::post('/',              [PaymentMethodController::class, 'store'])->middleware('check_permission:Payment Methods.write');
    Route::put('/{id}',           [PaymentMethodController::class, 'update'])->middleware('check_permission:Payment Methods.write');
    Route::delete('/{id}',        [PaymentMethodController::class, 'destroy'])->middleware('check_permission:Payment Methods.delete');
    Route::patch('/{id}/toggle',  [PaymentMethodController::class, 'toggle'])->middleware('check_permission:Payment Methods.write');
});

Route::prefix('shipping-methods')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',               [ShippingMethodController::class, 'index'])->middleware('check_permission:Shipping Methods.read');
    Route::post('/',              [ShippingMethodController::class, 'store'])->middleware('check_permission:Shipping Methods.write');
    Route::put('/{id}',           [ShippingMethodController::class, 'update'])->middleware('check_permission:Shipping Methods.write');
    Route::delete('/{id}',        [ShippingMethodController::class, 'destroy'])->middleware('check_permission:Shipping Methods.delete');
    Route::patch('/{id}/toggle',  [ShippingMethodController::class, 'toggle'])->middleware('check_permission:Shipping Methods.write');
});

Route::prefix('pages')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',        [ContentPageController::class, 'index'])->middleware('check_permission:Pages.read');
    Route::post('/',       [ContentPageController::class, 'store'])->middleware('check_permission:Pages.write');
    Route::put('/{id}',    [ContentPageController::class, 'update'])->middleware('check_permission:Pages.write');
    Route::delete('/{id}', [ContentPageController::class, 'destroy'])->middleware('check_permission:Pages.delete');
});

Route::prefix('shipping-addresses')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                   [ShippingAddressController::class, 'index'])->middleware('check_permission:Shipping Addresses.read');
    Route::post('/',                  [ShippingAddressController::class, 'store'])->middleware('check_permission:Shipping Addresses.write');
    Route::get('/{id}',               [ShippingAddressController::class, 'show'])->middleware('check_permission:Shipping Addresses.read');
    Route::put('/{id}',               [ShippingAddressController::class, 'update'])->middleware('check_permission:Shipping Addresses.write');
    Route::patch('/{id}/set-default', [ShippingAddressController::class, 'setDefault'])->middleware('check_permission:Shipping Addresses.write');
    Route::delete('/{id}',            [ShippingAddressController::class, 'destroy'])->middleware('check_permission:Shipping Addresses.delete');
});

Route::prefix('shipments')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',               [OrderShipmentController::class, 'index'])->middleware('check_permission:Shipments.read');
    Route::post('/',              [OrderShipmentController::class, 'store'])->middleware('check_permission:Shipments.write');
    Route::get('/stats',          [OrderShipmentController::class, 'stats'])->middleware('check_permission:Shipments.read');
    Route::get('/{id}',           [OrderShipmentController::class, 'show'])->middleware('check_permission:Shipments.read');
    Route::patch('/{id}/status',  [OrderShipmentController::class, 'updateStatus'])->middleware('check_permission:Shipments.write');
    Route::post('/{id}/tracking', [OrderShipmentController::class, 'addTracking'])->middleware('check_permission:Shipments.write');
});

// Public tracking endpoint (no auth)
Route::get('/track/{trackingNumber}', [OrderShipmentController::class, 'publicTracking']);

// Public tailor order tracking (no auth)
Route::get('/tailor/track/{token}', [\App\Http\Controllers\Api\Tailor\TailorController::class, 'publicTrack']);

// ─────────────────────────────────────────────────────────────────────────────
// Phase 8: Staff & Payroll
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('salary-payments')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',        [SalaryPaymentController::class, 'index'])->middleware('check_permission:Salary Management.read');
    Route::post('/',       [SalaryPaymentController::class, 'store'])->middleware('check_permission:Salary Management.write');
    Route::get('/{id}',    [SalaryPaymentController::class, 'show'])->middleware('check_permission:Salary Management.read');
    Route::put('/{id}',    [SalaryPaymentController::class, 'update'])->middleware('check_permission:Salary Management.write');
    Route::delete('/{id}', [SalaryPaymentController::class, 'destroy'])->middleware('check_permission:Salary Management.delete');
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 9: Stock Transfers & Inventory Management
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('transfers')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/products-by-location/{locationId}', [StockTransferController::class, 'getProductsByLocation'])->middleware('check_permission:Transfers.read');
    Route::get('/',            [StockTransferController::class, 'index'])->middleware('check_permission:Transfers.read');
    Route::post('/',           [StockTransferController::class, 'store'])->middleware('check_permission:Transfers.write');
    Route::put('/{id}/cancel', [StockTransferController::class, 'cancelTransfer'])->middleware('check_permission:Transfers.write');
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 10: Coupons & Discounts
// ─────────────────────────────────────────────────────────────────────────────

// Public coupon lookup (no authentication required)
Route::get('/coupons/code/{code}', [CouponController::class, 'getByCode']);

Route::prefix('coupons')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                [CouponController::class, 'index'])->middleware('check_permission:Coupons.read');
    Route::post('/',               [CouponController::class, 'store'])->middleware('check_permission:Coupons.write');
    Route::post('/with-image',     [CouponController::class, 'storeWithImage'])->middleware('check_permission:Coupons.write');
    Route::post('/validate',       [CouponController::class, 'validateCoupon'])->middleware('check_permission:Coupons.read');
    Route::get('/{id}',            [CouponController::class, 'show'])->middleware('check_permission:Coupons.read');
    Route::put('/{id}',            [CouponController::class, 'update'])->middleware('check_permission:Coupons.write');
    Route::put('/{id}/with-image', [CouponController::class, 'updateWithImage'])->middleware('check_permission:Coupons.write');
    Route::delete('/{id}',         [CouponController::class, 'destroy'])->middleware('check_permission:Coupons.delete');
    Route::get('/{id}/usage-stats',[CouponController::class, 'getUsageStats'])->middleware('check_permission:Coupons.read');
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 11: Inventory View (Read-Only)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('inventory')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/', [InventoryController::class, 'index'])->middleware('check_permission:Inventory.read');
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 12: Wishlist Analytics (admin)
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('wishlists')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/analytics', [WishlistAnalyticsController::class, 'analytics'])->middleware('check_permission:Store Wishlist.read');
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 12: Notifications
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('notifications')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',              [NotificationController::class, 'index'])->middleware('check_permission:Notifications.read');
    Route::get('/unread-count',  [NotificationController::class, 'unreadCount'])->middleware('check_permission:Notifications.read');
    Route::patch('/read-all',    [NotificationController::class, 'markAllAsRead'])->middleware('check_permission:Notifications.write');
    Route::delete('/bulk',       [NotificationController::class, 'bulkDelete'])->middleware('check_permission:Notifications.delete');
    Route::patch('/{id}/read',   [NotificationController::class, 'markAsRead'])->middleware('check_permission:Notifications.write');
    Route::patch('/{id}/unread', [NotificationController::class, 'markAsUnread'])->middleware('check_permission:Notifications.write');
    Route::delete('/{id}',       [NotificationController::class, 'destroy'])->middleware('check_permission:Notifications.delete');
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 13: Customer Support
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('support/tickets')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',                [SupportTicketController::class, 'index'])->middleware('check_permission:Support.read');
    Route::get('/stats',           [SupportTicketController::class, 'stats'])->middleware('check_permission:Support.read');
    Route::get('/{id}',            [SupportTicketController::class, 'show'])->middleware('check_permission:Support.read');
    Route::post('/{id}/reply',     [SupportTicketController::class, 'reply'])->middleware('check_permission:Support.write');
    Route::patch('/{id}/status',   [SupportTicketController::class, 'updateStatus'])->middleware('check_permission:Support.write');
    Route::patch('/{id}/priority', [SupportTicketController::class, 'updatePriority'])->middleware('check_permission:Support.write');
    Route::delete('/{id}',         [SupportTicketController::class, 'destroy'])->middleware('check_permission:Support.delete');
});


// ─────────────────────────────────────────────────────────────────────────────
// Tailor Shop Management
// ─────────────────────────────────────────────────────────────────────────────

Route::prefix('tailor')->middleware(JwtAuthMiddleware::class)->group(function () {
    // Dashboard
    Route::get('/dashboard',                    [TailorController::class, 'dashboard'])->middleware('check_permission:TailorShop.read');

    // Fabrics
    Route::get('/fabrics',                      [TailorController::class, 'fabricIndex'])->middleware('check_permission:TailorFabric.read');
    Route::post('/fabrics',                     [TailorController::class, 'fabricStore'])->middleware('check_permission:TailorFabric.write');
    Route::put('/fabrics/{id}',                 [TailorController::class, 'fabricUpdate'])->middleware('check_permission:TailorFabric.write');
    Route::delete('/fabrics/{id}',              [TailorController::class, 'fabricDestroy'])->middleware('check_permission:TailorFabric.delete');

    // Customers
    Route::get('/customers',                    [TailorController::class, 'customerIndex'])->middleware('check_permission:TailorOrders.read,TailorMeasurements.read');
    Route::get('/customers/search',             [TailorController::class, 'customerFindByPhone'])->middleware('check_permission:TailorOrders.read,TailorMeasurements.read');
    Route::post('/customers',                   [TailorController::class, 'customerStore'])->middleware('check_permission:TailorOrders.write');
    Route::get('/customers/{id}',               [TailorController::class, 'customerShow'])->middleware('check_permission:TailorOrders.read,TailorMeasurements.read');
    Route::put('/customers/{id}',               [TailorController::class, 'customerUpdate'])->middleware('check_permission:TailorOrders.write');
    Route::delete('/customers/{id}',            [TailorController::class, 'customerDelete'])->middleware('check_permission:TailorOrders.write');
    Route::get('/customers/{id}/orders',        [TailorController::class, 'customerOrders'])->middleware('check_permission:TailorOrders.read');

    // Measurements
    Route::get('/measurements',                  [TailorController::class, 'measurementIndex'])->middleware('check_permission:TailorMeasurements.read');
    Route::get('/measurements/customer/{customerId}', [TailorController::class, 'measurementByCustomer'])->middleware('check_permission:TailorMeasurements.read');
    Route::post('/measurements',                [TailorController::class, 'measurementStore'])->middleware('check_permission:TailorMeasurements.write');
    Route::put('/measurements/{id}',            [TailorController::class, 'measurementUpdate'])->middleware('check_permission:TailorMeasurements.write');

    // Dorjis
    Route::get('/dorjis',                       [TailorController::class, 'dorjiIndex'])->middleware('check_permission:TailorDorji.read');
    Route::post('/dorjis',                      [TailorController::class, 'dorjiStore'])->middleware('check_permission:TailorDorji.write');
    Route::put('/dorjis/{id}',                  [TailorController::class, 'dorjiUpdate'])->middleware('check_permission:TailorDorji.write');
    Route::delete('/dorjis/{id}',               [TailorController::class, 'dorjiDestroy'])->middleware('check_permission:TailorDorji.delete');

    // Orders
    Route::get('/orders',                       [TailorController::class, 'orderIndex'])->middleware('check_permission:TailorOrders.read');
    Route::post('/orders',                      [TailorController::class, 'orderStore'])->middleware('check_permission:TailorOrders.write');
    Route::get('/orders/{id}',                  [TailorController::class, 'orderShow'])->middleware('check_permission:TailorOrders.read');
    Route::put('/orders/{id}',                  [TailorController::class, 'orderUpdate'])->middleware('check_permission:TailorOrders.write');
    Route::patch('/orders/{id}/status',         [TailorController::class, 'orderUpdateStatus'])->middleware('check_permission:TailorOrders.write');

    // Assignments
    Route::get('/assignments',                  [TailorController::class, 'assignmentIndex'])->middleware('check_permission:TailorOrders.read');
    Route::post('/assignments',                 [TailorController::class, 'assignmentStore'])->middleware('check_permission:TailorOrders.write');
    Route::put('/assignments/{id}',             [TailorController::class, 'assignmentUpdate'])->middleware('check_permission:TailorOrders.write');

    // Payments
    Route::get('/payments',                     [TailorController::class, 'paymentIndex'])->middleware('check_permission:TailorPayments.read');
    Route::post('/payments',                    [TailorController::class, 'paymentStore'])->middleware('check_permission:TailorPayments.write');

    // Reports
    Route::get('/reports/orders',               [TailorController::class, 'reportOrders'])->middleware('check_permission:TailorReports.read');
    Route::get('/reports/fabrics',              [TailorController::class, 'reportFabrics'])->middleware('check_permission:TailorReports.read');
    Route::get('/reports/dorjis',               [TailorController::class, 'reportDorjis'])->middleware('check_permission:TailorReports.read');
});

// Serial tracking
Route::prefix('serials')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',        [SerialBatchController::class, 'indexSerials'])->middleware('check_permission:Inventory.read');
    Route::post('/',       [SerialBatchController::class, 'storeSerials'])->middleware('check_permission:Inventory.write');
    Route::get('/stats',   [SerialBatchController::class, 'stats'])->middleware('check_permission:Inventory.read');
    Route::get('/{id}',    [SerialBatchController::class, 'showSerial'])->middleware('check_permission:Inventory.read');
    Route::put('/{id}',    [SerialBatchController::class, 'updateSerial'])->middleware('check_permission:Inventory.write');
    Route::delete('/{id}', [SerialBatchController::class, 'destroySerial'])->middleware('check_permission:Inventory.delete');
});

// Batch tracking
Route::prefix('batches')->middleware(JwtAuthMiddleware::class)->group(function () {
    Route::get('/',        [SerialBatchController::class, 'indexBatches'])->middleware('check_permission:Inventory.read');
    Route::post('/',       [SerialBatchController::class, 'storeBatch'])->middleware('check_permission:Inventory.write');
    Route::get('/{id}',    [SerialBatchController::class, 'showBatch'])->middleware('check_permission:Inventory.read');
    Route::put('/{id}',    [SerialBatchController::class, 'updateBatch'])->middleware('check_permission:Inventory.write');
    Route::delete('/{id}', [SerialBatchController::class, 'destroyBatch'])->middleware('check_permission:Inventory.delete');
});

// Inventory movements
Route::get('/inventory-movements', [SerialBatchController::class, 'indexMovements'])
    ->middleware([JwtAuthMiddleware::class, 'check_permission:Inventory.read']);
