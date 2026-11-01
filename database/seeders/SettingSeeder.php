<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * The single settings row (Setting::current()). Created only when there is none,
 * so running the seeder again never overwrites what the admin saved.
 */
class SettingSeeder extends Seeder
{
    public function run(): void
    {
        if (Setting::exists()) {
            return;
        }

        Setting::create([
            'privacy_policy_ar' => 'نحن نحترم خصوصيتك. نستخدم بياناتك فقط لتنفيذ طلباتك وتقديم خدماتنا، ولا نشاركها مع أي طرف ثالث دون موافقتك.',
            'privacy_policy_en' => 'We respect your privacy. Your data is used only to fulfil your orders and provide our services, and is never shared with third parties without your consent.',
            'terms_conditions_ar' => 'باستخدامك للتطبيق فإنك توافق على شروط الاستخدام. الأسعار قابلة للتغيير، وتخضع الأدوية التي تتطلب وصفة طبية لمراجعة الصيدلي.',
            'terms_conditions_en' => 'By using the app you agree to these terms. Prices may change, and prescription medicines are subject to review by the pharmacist.',
            'facebook' => 'https://facebook.com/',
            'instagram' => 'https://instagram.com/',
            'tiktok' => 'https://tiktok.com/',
            'youtube' => 'https://youtube.com/',
            'phone_number' => '01000000000',
            'map_location_url' => 'https://maps.google.com/?q=30.0444,31.2357',
            // 5 loyalty points for every 100 spent, valid for 30 days.
            'spend_x' => 100,
            'get_y' => 5,
            'points_validity_days' => 30,
        ]);
    }
}
