<?php

namespace Tests\Feature\Inventory;

use App\Models\Admin;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Modules\Inventory\Services\PurchasingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Supplier payments: against invoices (never more than is left), on account (never more than is
 * owed), and advances (only when marked as such and allowed by pharmacy.supplier_advance_payments).
 */
class SupplierPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->supplier = Supplier::create(['code' => 'SUP', 'name' => 'Supplier']);
        $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
    }

    private function invoice(float $amount): SupplierInvoice
    {
        return SupplierInvoice::create(['supplier_id' => $this->supplier->id, 'number' => 'INV-'.uniqid(), 'invoice_date' => now()->toDateString(), 'amount' => $amount]);
    }

    private function pay(array $data): SupplierPayment
    {
        return app(PurchasingService::class)->recordSupplierPayment($this->supplier, $data + ['paid_on' => now()->toDateString()], $this->admin->id);
    }

    private function assertRefused(array $data, string $message): void
    {
        try {
            $this->pay($data);
            $this->fail('The payment was accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_invoice_payments_partial_and_full_never_more_than_is_left(): void
    {
        $invoice = $this->invoice(100);

        $this->pay(['supplier_invoice_id' => $invoice->id, 'amount' => 40]);
        $this->pay(['supplier_invoice_id' => $invoice->id, 'amount' => '60.00']);
        $this->assertRefused(['supplier_invoice_id' => $invoice->id, 'amount' => '0.01'], 'left on that invoice');

        $this->assertSame([0, 2], [$this->supplier->balanceMinor(), SupplierPayment::count()]);
    }

    public function test_an_on_account_payment_cannot_overpay_by_accident(): void
    {
        $this->invoice(100);

        $this->pay(['amount' => 70]);                       // within what is owed
        $this->assertRefused(['amount' => 40], 'advance payment');   // 30 owed: an accidental overpayment
        $this->pay(['amount' => 30]);

        $this->assertSame(0, $this->supplier->balanceMinor(), 'never a credit nobody asked for');
        $this->assertSame(0, SupplierPayment::where('is_advance', true)->count());
    }

    public function test_an_advance_is_recorded_as_such_and_makes_the_balance_a_credit(): void
    {
        $this->invoice(100);

        $payment = $this->pay(['amount' => 150, 'advance' => true]);

        $this->assertTrue($payment->is_advance);
        $this->assertSame(-5000, $this->supplier->balanceMinor(), 'the supplier owes us 50.00');
        // Further on-account payments while in credit are advances too.
        $this->assertRefused(['amount' => 1], 'advance payment');
    }

    public function test_advances_can_be_switched_off(): void
    {
        config(['pharmacy.supplier_advance_payments' => false]);
        $this->invoice(100);

        $this->assertRefused(['amount' => 150, 'advance' => true], 'advance payments are not allowed');
        $this->pay(['amount' => 100]);
        $this->assertSame(0, $this->supplier->balanceMinor());
    }

    public function test_the_dashboard_form_records_a_repeated_submission_once_and_validates_amounts(): void
    {
        $this->invoice(100);
        $form = ['amount' => 50, 'paid_on' => now()->toDateString(), 'request_key' => 'form-1'];
        $route = route('dashboard.suppliers.payments.store', $this->supplier);

        $this->actingAs($this->admin, 'admin')->post($route, $form)->assertSessionHas('success');
        $this->actingAs($this->admin, 'admin')->post($route, $form)->assertSessionHas('success');
        $this->assertSame(1, SupplierPayment::count());

        foreach (['10.001', '-5', '0', '99999999999'] as $bad) {
            $this->actingAs($this->admin, 'admin')->post($route, ['amount' => $bad, 'request_key' => "bad-{$bad}"] + $form)->assertSessionHasErrors('amount');
        }
        $this->actingAs($this->admin, 'admin')->post($route, ['amount' => 80, 'request_key' => 'form-2'] + $form)->assertSessionHas('error');
        $this->actingAs($this->admin, 'admin')->post($route, ['amount' => 80, 'advance' => 1, 'request_key' => 'form-3'] + $form)->assertSessionHas('success');
        $this->assertSame([2, -3000], [SupplierPayment::count(), $this->supplier->balanceMinor()]);
    }
}
