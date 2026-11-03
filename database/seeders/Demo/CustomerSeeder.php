<?php

namespace Database\Seeders\Demo;

use App\Models\Address;
use App\Models\Admin;
use App\Models\Cart;
use App\Models\ContactMessage;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo customers with addresses, favorites, reviews, carts and contact messages.
 * Every demo account signs in with the password "password".
 */
class CustomerSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** email => [name, phone, verified] */
    public const CUSTOMERS = [
        'sara@example.com' => ['Sara Ahmed', '01011111111', true],
        'omar@example.com' => ['Omar Hassan', '01022222222', true],
        'mona@example.com' => ['Mona Ali', '01033333333', true],
        'youssef@example.com' => ['Youssef Ibrahim', '01044444444', true],
        'nour@example.com' => ['Nour Khaled', '01055555555', false],
    ];

    public function run(): void
    {
        foreach (self::CUSTOMERS as $email => [$name, $phone, $verified]) {
            // "password" is hashed by the model's "hashed" cast.
            $user = User::create(['name' => $name, 'email' => $email, 'phone' => $phone, 'password' => self::PASSWORD]);
            $user->forceFill(['is_verified' => $verified])->save();
        }

        $this->addresses();
        $this->favorites();
        $this->reviews();
        $this->carts();
        $this->contactMessages();
    }

    public static function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function addresses(): void
    {
        $addresses = [
            ['sara@example.com', 'Cairo', 'Nasr City', 'Abbas El Akkad St', '14', '5', 'Near City Stars', 'home', true],
            ['sara@example.com', 'Cairo', 'Heliopolis', 'Al Merghany St', '32', '2', null, 'work', false],
            ['omar@example.com', 'Giza', 'Dokki', 'Tahrir St', '8', '3', 'Opposite the metro station', 'home', true],
            ['mona@example.com', 'Alexandria', 'Smouha', 'Victor Emmanuel St', '21', '7', null, 'home', true],
            ['youssef@example.com', 'Dakahlia', 'Mansoura', 'Gomhoreya St', '5', '1', 'Behind the university', 'home', true],
            ['nour@example.com', 'Cairo', 'Maadi', 'Road 9', '40', '4', null, 'home', false],
        ];

        foreach ($addresses as [$email, $governorate, $city, $street, $building, $apartment, $landmark, $type, $default]) {
            Address::create([
                'user_id' => self::user($email)->id,
                'governorate' => $governorate,
                'city' => $city,
                'street' => $street,
                'building' => $building,
                'apartment' => $apartment,
                'landmark' => $landmark,
                'type' => $type,
                'is_default' => $default,
            ]);
        }
    }

    private function favorites(): void
    {
        $favorites = [
            'sara@example.com' => ['Vitamin C 1000 mg Effervescent', 'Intense Moisturizing Cream', 'Prenatal Multivitamin'],
            'omar@example.com' => ['Ibuprofen 400 mg', 'Omega-3 Fish Oil'],
            'mona@example.com' => ['Baby Diaper Cream', 'No-Tears Baby Shampoo'],
            'youssef@example.com' => ['Blood Glucose Meter Kit', 'Glucose Test Strips', 'Digital Blood Pressure Monitor'],
        ];

        foreach ($favorites as $email => $products) {
            foreach ($products as $name) {
                Favorite::create(['user_id' => self::user($email)->id, 'product_id' => Product::where('name_en', $name)->value('id')]);
            }
        }
    }

    private function reviews(): void
    {
        $reviews = [
            ['Paracetamol 500 mg', 'sara@example.com', 5, 'Works fast for my headaches.'],
            ['Paracetamol 500 mg', 'omar@example.com', 4, 'Good value.'],
            ['Paracetamol 500 mg', 'youssef@example.com', 4, null],
            ['Vitamin C 1000 mg Effervescent', 'sara@example.com', 5, 'طعمه لطيف ويذوب بسرعة.'],
            ['Intense Moisturizing Cream', 'mona@example.com', 4, 'My skin feels much better after a week.'],
            ['Baby Diaper Cream', 'mona@example.com', 5, 'ممتاز لبشرة طفلي الحساسة.'],
            ['Blood Glucose Meter Kit', 'youssef@example.com', 3, 'Accurate, but the strips are expensive.'],
            ['Ibuprofen 400 mg', 'omar@example.com', 4, null],
        ];

        foreach ($reviews as [$name, $email, $rate, $text]) {
            ProductReview::create([
                'product_id' => Product::where('name_en', $name)->value('id'),
                'user_id' => self::user($email)->id,
                'rate' => $rate,
                'review' => $text,
            ]);
        }

        // Same average the review endpoint stores on the product.
        foreach (Product::has('reviews')->get() as $product) {
            $product->rate = $product->reviews()->avg('rate');
            $product->save();
        }
    }

    private function carts(): void
    {
        $carts = [
            'sara@example.com' => [['Vitamin D3 5000 IU', 0, 1], ['Sunscreen SPF 50+', 0, 1]],
            'omar@example.com' => [['Honey Cough Syrup', 1, 2]],
        ];

        foreach ($carts as $email => $lines) {
            foreach ($lines as [$name, $sizeIndex, $quantity]) {
                $product = Product::where('name_en', $name)->firstOrFail();
                $size = $product->sizes()->orderBy('id')->get()[$sizeIndex];

                // The cart stores the catalogue price; checkout re-reads live prices anyway.
                Cart::create([
                    'user_id' => self::user($email)->id,
                    'product_id' => $product->id,
                    'product_size_id' => $size->id,
                    'product_quantity' => $quantity,
                    'price_at_time' => $size->price,
                    'original_price' => $size->price,
                    'total_price' => $size->price * $quantity,
                ]);
            }
        }
    }

    private function contactMessages(): void
    {
        $messages = [
            ['omar@example.com', 'Do you deliver to 6th of October City?'],
            ['mona@example.com', 'هل يتوفر حليب أطفال للمرحلة الثانية؟'],
            ['youssef@example.com', 'I would like to order test strips in bulk every month.'],
        ];

        foreach ($messages as [$email, $message]) {
            $user = self::user($email);
            ContactMessage::create(['user_id' => $user->id, 'name' => $user->name, 'email' => $email, 'message' => $message]);
        }

        // Conversations: a delivered pharmacy reply (recorded as sent, nothing is pushed while
        // seeding), and one the customer answered so it waits for the pharmacy again.
        $adminId = Admin::query()->value('id');
        $omar = ContactMessage::where('email', 'omar@example.com')->first();
        $omar->replies()->create(['admin_id' => $adminId, 'body' => 'Yes, we deliver to 6th of October City within one working day.',
            'sent_at' => now(), 'push_devices' => 0]);
        $omar->update(['status' => ContactMessage::STATUS_REPLIED, 'last_activity_at' => now()]);

        $mona = ContactMessage::where('email', 'mona@example.com')->first();
        $mona->replies()->create(['admin_id' => $adminId, 'body' => 'نعم، متوفر. هل تفضلين نوعًا معينًا؟',
            'sent_at' => now(), 'push_devices' => 0]);
        $mona->replies()->create(['from_customer' => true, 'body' => 'أي نوع خالٍ من اللاكتوز من فضلك.']);
        $mona->update(['status' => ContactMessage::STATUS_OPEN, 'last_activity_at' => now()]);
    }
}
