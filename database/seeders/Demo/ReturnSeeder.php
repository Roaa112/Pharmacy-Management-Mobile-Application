<?php

namespace Database\Seeders\Demo;

use App\Models\Admin;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Modules\Orders\Services\ReturnDecisionService;
use Illuminate\Database\Seeder;

/**
 * One pending, one rejected and one accepted (partial) return. Decisions go through
 * ReturnDecisionService, so the accepted return also creates its return items, the
 * quarantine stock movement and the proportional loyalty point reversal.
 */
class ReturnSeeder extends Seeder
{
    public function run(): void
    {
        $decisions = app(ReturnDecisionService::class);
        $adminId = Admin::value('id');

        // Sara sends back the sunscreen from her coupon order: accepted, unit received back.
        $saraOrder = $this->completedOrder('sara@example.com', 'Sunscreen SPF 50+');
        $sunscreen = $saraOrder->items->firstWhere('product_name_en', 'Sunscreen SPF 50+');
        $accepted = $this->request($saraOrder, 'The product caused skin irritation', $sunscreen->total_price, 'zaincash', 'return_images/sunscreen.png');
        $decisions->accept($accepted->id, null, [$sunscreen->id => 1], $adminId);

        // Youssef's blood pressure monitor: rejected (opened device, outside the return policy).
        $youssefOrder = $this->completedOrder('youssef@example.com', 'Digital Blood Pressure Monitor');
        $rejected = $this->request($youssefOrder, 'Changed my mind', $youssefOrder->total_price, 'cash');
        $decisions->reject($rejected->id, 'Opened medical devices cannot be returned.', $adminId);

        // Mona's diaper cream: still waiting for the admin.
        $monaOrder = $this->completedOrder('mona@example.com', 'Baby Diaper Cream');
        $cream = $monaOrder->items->firstWhere('product_name_en', 'Baby Diaper Cream');
        $this->request($monaOrder, 'The tube arrived damaged', $cream->total_price, 'cash', 'return_images/damaged-tube.png');
    }

    private function completedOrder(string $email, string $productName): Order
    {
        return Order::withoutGlobalScopes()
            ->with('items')
            ->where('user_id', User::where('email', $email)->value('id'))
            ->where('status', 'completed')
            ->whereHas('items', fn ($q) => $q->where('product_name_en', $productName))
            ->firstOrFail();
    }

    private function request(Order $order, string $reason, $refund, string $method, ?string $image = null): ReturnRequest
    {
        $return = ReturnRequest::create([
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'reason' => $reason,
            'refund_amount' => $refund,
            'refund_method' => $method,
        ]);

        if ($image) {
            $return->images()->create(['path' => $image]);
        }

        return $return;
    }
}
