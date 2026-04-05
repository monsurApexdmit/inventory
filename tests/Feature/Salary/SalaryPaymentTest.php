<?php

namespace Tests\Feature\Salary;

use App\Models\Company;
use App\Models\SaasUser;
use App\Models\Staff;
use App\Models\SalaryPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SalaryPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private SaasUser $owner;
    private Staff $staff;
    private string $token;

    public function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->owner = SaasUser::factory()->create(['company_id' => $this->company->id]);
        $this->staff = Staff::factory()->create(['company_id' => $this->company->id]);
        $this->token = JWTAuth::fromUser($this->owner);
    }

    /**
     * Test 1: List salary payments with pagination
     */
    public function test_list_salary_payments(): void
    {
        foreach (['2024-01', '2024-02', '2024-03', '2024-04', '2024-05'] as $month) {
            SalaryPayment::factory()->create([
                'staff_id' => $this->staff->id,
                'month' => $month,
            ]);
        }

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments');

        $response->assertStatus(200);
        $this->assertEquals(5, $response->json('data.pagination.total'));
        $this->assertCount(5, $response->json('data.data'));
        $this->assertArrayHasKey('id', $response->json('data.data.0'));
        $this->assertArrayHasKey('staffId', $response->json('data.data.0'));
        $this->assertArrayHasKey('status', $response->json('data.data.0'));
    }

    /**
     * Test 2: Filter by status
     */
    public function test_list_with_status_filter(): void
    {
        SalaryPayment::factory()->paid()->create([
            'staff_id' => $this->staff->id,
            'month' => '2024-01',
        ]);
        SalaryPayment::factory()->pending()->create([
            'staff_id' => $this->staff->id,
            'month' => '2024-02',
        ]);
        SalaryPayment::factory()->pending()->create([
            'staff_id' => $this->staff->id,
            'month' => '2024-03',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments?status=pending');

        $response->assertStatus(200);
        $this->assertEquals(2, $response->json('data.pagination.total'));
        $this->assertEquals('pending', $response->json('data.data.0.status'));
    }

    /**
     * Test 3: Filter by month
     */
    public function test_list_with_month_filter(): void
    {
        SalaryPayment::factory()->create([
            'staff_id' => $this->staff->id,
            'month' => '2024-01',
        ]);
        SalaryPayment::factory()->create([
            'staff_id' => $this->staff->id,
            'month' => '2024-02',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments?month=2024-01');

        $response->assertStatus(200);
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }

    /**
     * Test 4: Get single salary payment
     */
    public function test_get_salary_payment(): void
    {
        $payment = SalaryPayment::factory()->create(['staff_id' => $this->staff->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson("/api/salary-payments/{$payment->id}");

        $response->assertStatus(200);
        $this->assertEquals($payment->id, $response->json('data.id'));
        $this->assertEquals($this->staff->id, $response->json('data.staffId'));
        $this->assertNotNull($response->json('data.staff'));
    }

    /**
     * Test 5: Create salary payment (pending state)
     */
    public function test_create_salary_payment_pending(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '2024-01',
                'amount' => 5000,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.paidAmount', 0);
        $this->assertDatabaseHas('salary_payments', [
            'staff_id' => $this->staff->id,
            'month' => '2024-01',
            'amount' => 5000,
            'status' => 'pending',
        ]);
    }

    /**
     * Test 6: Create salary payment (partial state)
     */
    public function test_create_salary_payment_partial(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '2024-02',
                'amount' => 5000,
                'paidAmount' => 2500,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'partial');
        $response->assertJsonPath('data.paidAmount', 2500);
        $this->assertDatabaseHas('salary_payments', [
            'staff_id' => $this->staff->id,
            'status' => 'partial',
            'paid_amount' => 2500,
        ]);
    }

    /**
     * Test 7: Create salary payment (fully paid state)
     */
    public function test_create_salary_payment_paid(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '2024-03',
                'amount' => 5000,
                'paidAmount' => 5000,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseHas('salary_payments', [
            'status' => 'paid',
            'paid_amount' => 5000,
        ]);
    }

    /**
     * Test 8: Duplicate month returns 409
     */
    public function test_create_duplicate_month_conflict(): void
    {
        SalaryPayment::factory()->create([
            'staff_id' => $this->staff->id,
            'month' => '2024-04',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '2024-04',
                'amount' => 5000,
            ]);

        $response->assertStatus(409);
    }

    /**
     * Test 9: Create with invalid staff returns 400
     */
    public function test_create_invalid_staff_returns_400(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => 999,
                'month' => '2024-05',
                'amount' => 5000,
            ]);

        $response->assertStatus(400);
    }

    /**
     * Test 10: Update salary payment (partial to paid)
     */
    public function test_update_salary_payment_partial_to_paid(): void
    {
        $payment = SalaryPayment::factory()->partial(0.5)->create([
            'staff_id' => $this->staff->id,
            'amount' => 5000,
            'paid_amount' => 2500,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/salary-payments/{$payment->id}", [
                'paidAmount' => 5000,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'paid');
        $this->assertDatabaseHas('salary_payments', [
            'id' => $payment->id,
            'paid_amount' => 5000,
            'status' => 'paid',
        ]);
    }

    /**
     * Test 11: Update salary payment (update remarks)
     */
    public function test_update_salary_payment_remarks(): void
    {
        $payment = SalaryPayment::factory()->create(['staff_id' => $this->staff->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/salary-payments/{$payment->id}", [
                'remarks' => 'Paid via bank transfer',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('salary_payments', [
            'id' => $payment->id,
            'remarks' => 'Paid via bank transfer',
        ]);
    }

    /**
     * Test 12: Delete salary payment (soft delete)
     */
    public function test_delete_salary_payment(): void
    {
        $payment = SalaryPayment::factory()->create(['staff_id' => $this->staff->id]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson("/api/salary-payments/{$payment->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.message', 'Salary payment deleted successfully');
        $this->assertSoftDeleted('salary_payments', ['id' => $payment->id]);
    }

    /**
     * Test 13: Get non-existent payment returns 404
     */
    public function test_get_non_existent_payment_returns_404(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments/999');

        $response->assertStatus(404);
    }

    /**
     * Test 14: Update non-existent payment returns 404
     */
    public function test_update_non_existent_payment_returns_404(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson('/api/salary-payments/999', [
                'paidAmount' => 5000,
            ]);

        $response->assertStatus(404);
    }

    /**
     * Test 15: Delete non-existent payment returns 404
     */
    public function test_delete_non_existent_payment_returns_404(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->deleteJson('/api/salary-payments/999');

        $response->assertStatus(404);
    }

    /**
     * Test 16: Requires authentication
     */
    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/salary-payments');

        $response->assertStatus(401);
    }

    /**
     * Test 17: Create requires staffId
     */
    public function test_create_requires_staff_id(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'month' => '2024-01',
                'amount' => 5000,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test 18: Create requires month
     */
    public function test_create_requires_month(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'amount' => 5000,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test 19: Create requires amount
     */
    public function test_create_requires_amount(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '2024-01',
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test 20: Create with invalid month format returns 422
     */
    public function test_create_invalid_month_format(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => 'invalid-month',
                'amount' => 5000,
            ]);

        $response->assertStatus(422);
    }

    /**
     * Test 21: Pagination default limit
     */
    public function test_pagination_default_limit(): void
    {
        $months = ['2020-01', '2020-02', '2020-03', '2020-04', '2020-05', '2020-06', '2020-07', '2020-08', '2020-09', '2020-10', '2020-11', '2020-12', '2021-01', '2021-02', '2021-03', '2021-04', '2021-05', '2021-06', '2021-07', '2021-08'];
        foreach ($months as $month) {
            SalaryPayment::factory()->create([
                'staff_id' => $this->staff->id,
                'month' => $month,
            ]);
        }

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments');

        $response->assertStatus(200);
        $this->assertCount(15, $response->json('data.data'));
        $this->assertEquals(1, $response->json('data.pagination.page'));
        $this->assertEquals(15, $response->json('data.pagination.limit'));
    }

    /**
     * Test 22: Pagination custom limit and page
     */
    public function test_pagination_custom_limit(): void
    {
        $months = ['2018-01', '2018-02', '2018-03', '2018-04', '2018-05', '2018-06', '2018-07', '2018-08', '2018-09', '2018-10', '2018-11', '2018-12', '2019-01', '2019-02', '2019-03', '2019-04', '2019-05', '2019-06', '2019-07', '2019-08'];
        foreach ($months as $month) {
            SalaryPayment::factory()->create([
                'staff_id' => $this->staff->id,
                'month' => $month,
            ]);
        }

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->getJson('/api/salary-payments?limit=5&page=2');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data.data'));
        $this->assertEquals(2, $response->json('data.pagination.page'));
        $this->assertEquals(4, $response->json('data.pagination.lastPage'));
    }

    /**
     * Test 23: Status auto-calculates on create (pending with no paid amount)
     */
    public function test_status_auto_calculates_pending(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/salary-payments', [
                'staffId' => $this->staff->id,
                'month' => '2024-06',
                'amount' => 5000,
                'paidAmount' => 0,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'pending');
    }

    /**
     * Test 24: Status auto-calculates on update
     */
    public function test_status_auto_calculates_on_update(): void
    {
        $payment = SalaryPayment::factory()->create([
            'staff_id' => $this->staff->id,
            'amount' => 5000,
            'paid_amount' => 0,
            'status' => 'pending',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/salary-payments/{$payment->id}", [
                'paidAmount' => 3000,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'partial');
    }

    /**
     * Test 25: Amount stays editable
     */
    public function test_update_amount(): void
    {
        $payment = SalaryPayment::factory()->create([
            'staff_id' => $this->staff->id,
            'amount' => 5000,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/salary-payments/{$payment->id}", [
                'amount' => 6000,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('salary_payments', [
            'id' => $payment->id,
            'amount' => 6000,
        ]);
    }
}
