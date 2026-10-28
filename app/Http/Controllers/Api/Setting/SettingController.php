<?php

namespace App\Http\Controllers\Api\Setting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdateSettingRequest;
use App\Http\Requests\Setting\UpdateRegionalSettingRequest;
use App\Http\Requests\Setting\UploadImageRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Notification\NotificationPreferenceService;
use App\Services\Setting\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SettingService $settingService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->settingService->getAll($companyId));
    }

    public function updateSection(Request $request, string $section): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->all();

        return $this->success($this->settingService->updateSection($companyId, $section, $data));
    }

    // Specific section endpoints
    public function updateGeneral(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $request->validate([
            'storeName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'legalName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'storePhone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'storeEmail' => ['sometimes', 'nullable', 'email', 'max:255'],
            'storeAddress' => ['sometimes', 'nullable', 'string', 'max:500'],
            'bin' => ['sometimes', 'nullable', 'string', 'max:100'],
            'licence' => ['sometimes', 'nullable', 'string', 'max:100'],
            'invoicePrefix' => ['sometimes', 'nullable', 'string', 'max:20'],
            'nextInvoiceNumber' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'poPrefix' => ['sometimes', 'nullable', 'string', 'max:20'],
            'lowStockDefault' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'receiptPaper' => ['sometimes', 'nullable', 'in:58,80,a4'],
            'receiptFooter' => ['sometimes', 'nullable', 'string', 'max:500'],
            'holidays' => ['sometimes', 'nullable', 'array'],
            'holidays.*' => ['date_format:Y-m-d'],
            'primaryColor' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accentColor' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'backgroundColor' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);
        $result = $this->settingService->updateSection($companyId, 'general', $request->all());
        return $this->success($result, 'General settings updated successfully');
    }

    public function updateTax(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'tax', $request->all());
        return $this->success($result, 'Tax settings updated successfully');
    }

    public function updateShipping(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'shipping', $request->all());
        return $this->success($result, 'Shipping settings updated successfully');
    }

    public function getPayment(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->settingService->getPaymentMethods($companyId));
    }

    public function updatePayment(\App\Http\Requests\Setting\UpdatePaymentSettingsRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'payment', $request->all());
        return $this->success($result, 'Payment settings updated successfully');
    }

    public function clearWishlist(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $deleted = \App\Models\Wishlist::where('company_id', $companyId)->delete();
        return $this->success(['deleted' => $deleted], 'Wishlist data cleared');
    }

    public function updateBusiness(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $url = ['nullable', 'string', 'max:255', 'regex:/^https?:\/\/[^\s\/$.?#][^\s]*$/i'];
        $data = $request->validate([
            'businessName' => ['sometimes', 'required', 'string', 'max:255'],
            'businessType' => ['sometimes', 'nullable', 'string', 'max:100'],
            'registrationNumber' => ['sometimes', 'nullable', 'string', 'max:100'],
            'gstNumber' => ['sometimes', 'nullable', 'string', 'max:100'],
            'website' => ['sometimes'] + $url,
            'socialLinks' => ['sometimes', 'nullable', 'array'],
            'socialLinks.facebook' => $url,
            'socialLinks.instagram' => $url,
            'socialLinks.twitter' => $url,
            'onlineStore' => ['sometimes', 'nullable', 'array'],
            'onlineStore.name' => ['nullable', 'string', 'max:255'],
            'onlineStore.slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:[-.][a-z0-9]+)*$/'],
            'onlineStore.theme' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'onlineStore.published' => ['nullable', 'boolean'],
            'onlineStore.apiUrl' => $url,
            'onlineStore.companyId' => ['nullable', 'string', 'max:50'],
            'onlineStore.policies' => ['nullable', 'array'],
            'onlineStore.policies.returns' => ['nullable', 'string', 'max:5000'],
            'onlineStore.policies.shipping' => ['nullable', 'string', 'max:5000'],
            'onlineStore.policies.privacy' => ['nullable', 'string', 'max:5000'],
            'onlineStore.social' => ['nullable', 'array'],
            'onlineStore.social.facebook' => $url,
            'onlineStore.social.instagram' => $url,
            'onlineStore.social.youtube' => $url,
            'onlineStore.social.whatsapp' => ['nullable', 'string', 'max:255', 'regex:/^(\+?[0-9][0-9\s\-]{6,}|https?:\/\/[^\s\/$.?#][^\s]*)$/i'],
            'auraShopHero' => ['sometimes', 'nullable', 'array'],
            'auraShopHero.autoplayMs' => ['nullable', 'integer', 'min:2000', 'max:600000'],
            'auraShopHero.slides' => ['nullable', 'array', 'max:20'],
            'auraShopHero.slides.*.id' => ['nullable', 'string', 'max:64'],
            'auraShopHero.slides.*.imagePath' => ['nullable', 'string', 'max:500'],
            'auraShopHero.slides.*.tag' => ['nullable', 'string', 'max:120'],
            'auraShopHero.slides.*.title' => ['nullable', 'string', 'max:255', 'required_if:auraShopHero.slides.*.enabled,true,1'],
            'auraShopHero.slides.*.subtitle' => ['nullable', 'string', 'max:500'],
            'auraShopHero.slides.*.cta' => ['nullable', 'string', 'max:80'],
            'auraShopHero.slides.*.link' => ['nullable', 'string', 'max:500', 'regex:/^(\/|https?:\/\/)/i'],
            'auraShopHero.slides.*.gradient' => ['nullable', 'string', 'max:255'],
            'auraShopHero.slides.*.enabled' => ['nullable', 'boolean'],
            'homepageSections' => ['sometimes', 'nullable', 'array'],
            'homepageSections.aisles' => ['nullable', 'array'],
            'homepageSections.aisles.enabled' => ['nullable', 'boolean'],
            'homepageSections.aisles.title' => ['nullable', 'string', 'max:120'],
            'homepageSections.aisles.subtitle' => ['nullable', 'string', 'max:255'],
            'homepageSections.aisles.items' => ['nullable', 'array', 'max:6'],
            'homepageSections.aisles.items.*.id' => ['nullable', 'string', 'max:64'],
            'homepageSections.aisles.items.*.label' => ['nullable', 'string', 'max:60', 'required_if:homepageSections.aisles.items.*.enabled,true,1'],
            'homepageSections.aisles.items.*.tagline' => ['nullable', 'string', 'max:160'],
            'homepageSections.aisles.items.*.emoji' => ['nullable', 'string', 'max:16'],
            'homepageSections.aisles.items.*.imagePath' => ['nullable', 'string', 'max:500'],
            'homepageSections.aisles.items.*.link' => ['nullable', 'string', 'max:500', 'regex:/^(\/|https?:\/\/)/i'],
            'homepageSections.aisles.items.*.enabled' => ['nullable', 'boolean'],
            'homepageSections.products' => ['nullable', 'array'],
            'homepageSections.products.enabled' => ['nullable', 'boolean'],
            'homepageSections.products.title' => ['nullable', 'string', 'max:120'],
            'homepageSections.products.subtitle' => ['nullable', 'string', 'max:255'],
            'homepageSections.products.source' => ['nullable', 'in:latest,featured,best_seller,hot_deal,on_sale'],
            'homepageSections.products.categoryId' => ['nullable', 'integer'],
            'homepageSections.products.limit' => ['nullable', 'integer', 'min:4', 'max:12'],
            'homepageSections.lookbook' => ['nullable', 'array'],
            'homepageSections.lookbook.enabled' => ['nullable', 'boolean'],
            'homepageSections.lookbook.tag' => ['nullable', 'string', 'max:60'],
            'homepageSections.lookbook.title' => ['nullable', 'string', 'max:160'],
            'homepageSections.lookbook.text' => ['nullable', 'string', 'max:500'],
            'homepageSections.lookbook.cta' => ['nullable', 'string', 'max:60'],
            'homepageSections.lookbook.link' => ['nullable', 'string', 'max:500', 'regex:/^(\/|https?:\/\/)/i'],
            'homepageSections.lookbook.images' => ['nullable', 'array', 'max:2'],
            'homepageSections.lookbook.images.*' => ['nullable', 'string', 'max:500'],
            'homepageSections.perks' => ['nullable', 'array'],
            'homepageSections.perks.enabled' => ['nullable', 'boolean'],
            'homepageSections.perks.items' => ['nullable', 'array', 'max:4'],
            'homepageSections.perks.items.*.icon' => ['nullable', 'in:truck,rotate,shield,heart,gift,star,sparkles,package,clock,phone'],
            'homepageSections.perks.items.*.title' => ['nullable', 'string', 'max:80'],
            'homepageSections.perks.items.*.text' => ['nullable', 'string', 'max:200'],
            'homepageSections.perks.items.*.link' => ['nullable', 'string', 'max:500', 'regex:/^(\/|https?:\/\/)/i'],
            'homepageSections.footer' => ['nullable', 'array'],
            'homepageSections.footer.tagline' => ['nullable', 'string', 'max:300'],
        ]);
        if (data_get($data, 'onlineStore.published') && !trim((string) data_get($data, 'onlineStore.slug', ''))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['onlineStore.slug' => ['Add a domain or slug before publishing the store.']]);
        }
        // Preserve unrelated business keys (e.g. promoBanner) written by other screens.
        $all = $request->all();
        $result = $this->settingService->updateSection($companyId, 'business', $all);
        return $this->success($result, 'Business settings updated successfully');
    }

    public function updateRegional(UpdateRegionalSettingRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'regional', $request->validated());
        return $this->success($result, 'Regional settings updated successfully');
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'notifications', $request->all());
        return $this->success($result, 'Notification settings updated successfully');
    }

    public function getNotificationPreferences(Request $request, NotificationPreferenceService $service): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($service->get($companyId));
    }

    public function updateNotificationPreferences(Request $request, NotificationPreferenceService $service): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($service->update($companyId, $request->all()), 'Notification preferences saved');
    }

    public function getStoreHours(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->settingService->getStoreHours($companyId), 'Store hours retrieved successfully');
    }

    public function updateStoreHours(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $result = $this->settingService->updateSection($companyId, 'store-hours', $request->all());
        return $this->success($result, 'Store hours updated successfully');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->error('Unauthorized', 401);
        }

        $request->validate([
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:8',
            'confirmPassword' => 'required|string|same:newPassword',
        ]);

        // Verify current password
        if (!\Hash::check($request->input('currentPassword'), $user->password)) {
            return $this->error('Current password is incorrect', 422);
        }

        // Update password
        $user->update([
            'password' => \Hash::make($request->input('newPassword')),
        ]);

        return $this->success(['message' => 'Password changed successfully']);
    }

    public function uploadLogo(UploadImageRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $uploadedBy = (int) $request->attributes->get('auth_user_id');

        // Validate file size (5MB max)
        if ($request->file('file')->getSize() > 5 * 1024 * 1024) {
            return $this->error('Logo file size must not exceed 5MB', 422);
        }

        $payload = $this->settingService->uploadLogo($companyId, $request->file('file'), $uploadedBy);

        return $this->success(
            $payload,
            'Logo uploaded successfully',
            201
        );
    }

    public function uploadBanner(UploadImageRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $uploadedBy = (int) $request->attributes->get('auth_user_id');

        // Validate file size (10MB max)
        if ($request->file('file')->getSize() > 10 * 1024 * 1024) {
            return $this->error('Banner file size must not exceed 10MB', 422);
        }

        $payload = $this->settingService->uploadBanner($companyId, $request->file('file'), $uploadedBy);

        return $this->success(
            $payload,
            'Banner uploaded successfully',
            201
        );
    }

    /** Restore logo / banner URLs (used by settings import). Files themselves are not copied. */
    public function updateBranding(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $rule = ['sometimes', 'nullable', 'string', 'max:500', 'regex:/^(\/storage\/|https?:\/\/)\S+$/i'];
        $data = $request->validate(['logoUrl' => $rule, 'bannerUrl' => $rule]);

        return $this->success($this->settingService->updateBranding($companyId, $data), 'Branding updated');
    }

    public function removeLogo(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->settingService->removeLogo($companyId), 'Logo removed');
    }

    public function removeBanner(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->settingService->removeBanner($companyId), 'Banner removed');
    }

    public function uploadStorefrontImage(UploadImageRequest $request): JsonResponse
    {
        if ($request->file('file')->getSize() > 10 * 1024 * 1024) {
            return $this->error('Image file size must not exceed 10MB', 422);
        }

        $payload = $this->settingService->uploadStorefrontImage($request->file('file'));

        return $this->success(
            $payload,
            'Storefront image uploaded successfully',
            201
        );
    }
}
