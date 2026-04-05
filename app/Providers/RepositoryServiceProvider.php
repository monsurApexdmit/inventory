<?php

namespace App\Providers;

use App\Repositories\Contracts\IAttributeRepository;
use App\Repositories\Contracts\IBillingContactRepository;
use App\Repositories\Contracts\ICategoryRepository;
use App\Repositories\Contracts\ICompanyRepository;
use App\Repositories\Contracts\ICompanySettingsRepository;
use App\Repositories\Contracts\IEmailVerificationRepository;
use App\Repositories\Contracts\IInvitationRepository;
use App\Repositories\Contracts\ILocationRepository;
use App\Repositories\Contracts\IPasswordResetRepository;
use App\Repositories\Contracts\IPaymentRepository;
use App\Repositories\Contracts\IPermissionRepository;
use App\Repositories\Contracts\IProductRepository;
use App\Repositories\Contracts\IRolePermissionRepository;
use App\Repositories\Contracts\ISaasUserRepository;
use App\Repositories\Contracts\ISettingRepository;
use App\Repositories\Contracts\IStaffRepository;
use App\Repositories\Contracts\IStaffRoleRepository;
use App\Repositories\Contracts\ISubscriptionPlanRepository;
use App\Repositories\Contracts\ISubscriptionRepository;
use App\Repositories\Contracts\IUserRepository;
use App\Repositories\Contracts\ICustomerRepository;
use App\Repositories\Contracts\ICustomerReturnRepository;
use App\Repositories\Contracts\IShippingAddressRepository;
use App\Repositories\Contracts\IOrderShipmentRepository;
use App\Repositories\Contracts\IVendorRepository;
use App\Repositories\Contracts\IVendorReturnRepository;
use App\Repositories\Contracts\ISalaryPaymentRepository;
use App\Repositories\Contracts\IStockTransferRepository;
use App\Repositories\Contracts\ICouponRepository;
use App\Repositories\Contracts\ISellRepository;
use App\Repositories\Eloquent\AttributeRepository;
use App\Repositories\Eloquent\BillingContactRepository;
use App\Repositories\Eloquent\CategoryRepository;
use App\Repositories\Eloquent\CompanyRepository;
use App\Repositories\Eloquent\CompanySettingsRepository;
use App\Repositories\Eloquent\EmailVerificationRepository;
use App\Repositories\Eloquent\InvitationRepository;
use App\Repositories\Eloquent\LocationRepository;
use App\Repositories\Eloquent\PasswordResetRepository;
use App\Repositories\Eloquent\PaymentRepository;
use App\Repositories\Eloquent\PermissionRepository;
use App\Repositories\Eloquent\ProductRepository;
use App\Repositories\Eloquent\RolePermissionRepository;
use App\Repositories\Eloquent\SaasUserRepository;
use App\Repositories\Eloquent\SettingRepository;
use App\Repositories\Eloquent\StaffRepository;
use App\Repositories\Eloquent\StaffRoleRepository;
use App\Repositories\Eloquent\SubscriptionPlanRepository;
use App\Repositories\Eloquent\SubscriptionRepository;
use App\Repositories\Eloquent\UserRepository;
use App\Repositories\Eloquent\CustomerRepository;
use App\Repositories\Eloquent\CustomerReturnRepository;
use App\Repositories\Eloquent\ShippingAddressRepository;
use App\Repositories\Eloquent\OrderShipmentRepository;
use App\Repositories\Eloquent\VendorRepository;
use App\Repositories\Eloquent\VendorReturnRepository;
use App\Repositories\Eloquent\SalaryPaymentRepository;
use App\Repositories\Eloquent\StockTransferRepository;
use App\Repositories\Eloquent\CouponRepository;
use App\Repositories\Eloquent\SellRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Phase 1 bindings
        $this->app->bind(IUserRepository::class, UserRepository::class);
        $this->app->bind(ISaasUserRepository::class, SaasUserRepository::class);
        $this->app->bind(ICompanyRepository::class, CompanyRepository::class);
        $this->app->bind(IEmailVerificationRepository::class, EmailVerificationRepository::class);
        $this->app->bind(IPasswordResetRepository::class, PasswordResetRepository::class);

        // Phase 2 bindings
        $this->app->bind(ICompanySettingsRepository::class, CompanySettingsRepository::class);
        $this->app->bind(ISubscriptionPlanRepository::class, SubscriptionPlanRepository::class);
        $this->app->bind(ISubscriptionRepository::class, SubscriptionRepository::class);
        $this->app->bind(IPaymentRepository::class, PaymentRepository::class);
        $this->app->bind(IBillingContactRepository::class, BillingContactRepository::class);
        $this->app->bind(IStaffRoleRepository::class, StaffRoleRepository::class);
        $this->app->bind(IPermissionRepository::class, PermissionRepository::class);
        $this->app->bind(IRolePermissionRepository::class, RolePermissionRepository::class);
        $this->app->bind(IInvitationRepository::class, InvitationRepository::class);
        $this->app->bind(IStaffRepository::class, StaffRepository::class);

        // Phase 3 bindings
        $this->app->bind(ICategoryRepository::class, CategoryRepository::class);
        $this->app->bind(IAttributeRepository::class, AttributeRepository::class);
        $this->app->bind(ILocationRepository::class, LocationRepository::class);
        $this->app->bind(ISettingRepository::class, SettingRepository::class);

        // Phase 4 bindings
        $this->app->bind(IProductRepository::class, ProductRepository::class);

        // Phase 5 bindings
        $this->app->bind(IVendorRepository::class, VendorRepository::class);
        $this->app->bind(IVendorReturnRepository::class, VendorReturnRepository::class);

        // Phase 6 bindings
        $this->app->bind(ICustomerRepository::class, CustomerRepository::class);
        $this->app->bind(ICustomerReturnRepository::class, CustomerReturnRepository::class);

        // Phase 7 bindings
        $this->app->bind(IShippingAddressRepository::class, ShippingAddressRepository::class);
        $this->app->bind(IOrderShipmentRepository::class, OrderShipmentRepository::class);

        // Phase 8 bindings
        $this->app->bind(ISalaryPaymentRepository::class, SalaryPaymentRepository::class);

        // Phase 9 bindings
        $this->app->bind(IStockTransferRepository::class, StockTransferRepository::class);

        // Phase 10 bindings
        $this->app->bind(ICouponRepository::class, CouponRepository::class);

        // Phase 11 bindings (Orders/Sells)
        $this->app->bind(ISellRepository::class, SellRepository::class);
    }
}
