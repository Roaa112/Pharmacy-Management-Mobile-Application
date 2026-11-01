<?php

namespace Tests\Feature\Marketing;

use App\Models\Admin;
use App\Models\Banner;
use App\Models\Country;
use App\Models\OpeningAd;
use App\Support\StoreClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Customers only ever see active, scheduled-now content for their country and device. */
class BannersAndAdsTest extends TestCase
{
    use RefreshDatabase;

    private function banner(array $attributes = []): Banner
    {
        return Banner::create($attributes + ['title_en' => 'B'.uniqid(), 'title_ar' => 'ب', 'image' => 'banners/a.png', 'status' => true, 'order_of_appearance' => 1]);
    }

    private function bannerTitles(string $query = ''): array
    {
        return array_column($this->getJson('/api/v1/user/banners'.$query)->assertOk()->json('data'), 'title');
    }

    public function test_scheduling_expiry_and_status(): void
    {
        $now = StoreClock::now();
        $this->banner(['title_en' => 'Live']);
        $this->banner(['title_en' => 'Window', 'starts_at' => $now->copy()->subHour(), 'ends_at' => $now->copy()->addHour()]);
        $this->banner(['title_en' => 'Later', 'starts_at' => $now->copy()->addHour()]);
        $this->banner(['title_en' => 'Over', 'ends_at' => $now->copy()->subMinute()]);
        $this->banner(['title_en' => 'Off', 'status' => false]);

        $this->assertEqualsCanonicalizing(['Live', 'Window'], $this->bannerTitles());

        $this->travel(2)->hours();
        $this->assertEqualsCanonicalizing(['Live', 'Later'], $this->bannerTitles());
    }

    public function test_country_device_and_position_targeting(): void
    {
        $abroad = Country::create(['iso2' => 'IQ', 'name_en' => 'Iraq', 'name_ar' => 'العراق', 'currency_code' => 'IQD', 'timezone' => 'Asia/Baghdad', 'phone_code' => '+964']);
        $this->banner(['title_en' => 'Egypt', 'country_id' => Country::default()->id]);
        $this->banner(['title_en' => 'Abroad', 'country_id' => $abroad->id]);
        $this->banner(['title_en' => 'iOS only', 'device' => 'ios']);
        $this->banner(['title_en' => 'Offers page', 'position' => 'offers']);

        $this->assertEqualsCanonicalizing(['Egypt', 'iOS only', 'Offers page'], $this->bannerTitles());
        $this->assertEqualsCanonicalizing(['Egypt', 'Offers page'], $this->bannerTitles('?device=android'));
        $this->assertSame(['Offers page'], $this->bannerTitles('?position=offers'));
    }

    public function test_opening_ads_follow_the_same_rules_and_come_by_priority(): void
    {
        $now = StoreClock::now();
        OpeningAd::create(['image' => 'a.png', 'is_active' => true, 'title_en' => 'Low', 'priority' => 1]);
        OpeningAd::create(['image' => 'b.png', 'is_active' => true, 'title_en' => 'High', 'priority' => 9, 'display_frequency' => 'once_per_day']);
        OpeningAd::create(['image' => 'c.png', 'is_active' => false, 'title_en' => 'Off']);
        OpeningAd::create(['image' => 'd.png', 'is_active' => true, 'title_en' => 'Expired', 'ends_at' => $now->copy()->subDay()]);
        OpeningAd::create(['image' => 'e.png', 'is_active' => true, 'title_en' => 'Web', 'device' => 'web']);

        $ads = $this->getJson('/api/v1/user/openingAd?device=ios')->assertOk()->json('data');
        $this->assertSame(['High', 'Low'], array_column($ads, 'title'));
        $this->assertSame('once_per_day', $ads[0]['display_frequency']);
    }

    public function test_uploads_accept_raster_images_only_and_store_them_under_a_random_name(): void
    {
        Storage::fake('public');
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret-password']);
        $base = ['title_en' => 'New', 'title_ar' => 'جديد', 'status' => 1];

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($admin, 'admin')->from('/dashboard/banners')->post('/dashboard/banners', $base + ['image' => $svg])->assertSessionHasErrors('image');

        $php = UploadedFile::fake()->createWithContent('shell.php.png', '<?php echo 1;');
        $this->actingAs($admin, 'admin')->from('/dashboard/banners')->post('/dashboard/banners', $base + ['image' => $php])->assertSessionHasErrors('image');

        $this->actingAs($admin, 'admin')->from('/dashboard/banners')->post('/dashboard/banners', $base + ['image' => UploadedFile::fake()->image('../../evil name.png', 800, 400)])
            ->assertSessionHasNoErrors();
        $banner = Banner::sole();
        $this->assertMatchesRegularExpression('#^storage/marketing/banners/[A-Za-z0-9]{40}\.png$#', $banner->image);
        Storage::disk('public')->assertExists(substr($banner->image, strlen('storage/')));

        $this->actingAs($admin, 'admin')->from('/dashboard/OpeningAd')->post('/dashboard/OpeningAd', ['image' => UploadedFile::fake()->image('ad.jpg', 5000, 100), 'is_active' => 1])
            ->assertSessionHasErrors('image');
    }

    public function test_banners_are_display_only_and_never_change_prices(): void
    {
        $this->banner(['title_en' => 'Sale', 'link' => url('/api/v1/user/products/on/sale')]);
        $this->assertSame(0, \App\Models\DiscountRule::count());
        $this->assertSame([], app(\App\Modules\Promotions\Engine\PromotionCatalog::class)->promotions(StoreClock::now())->all());
    }
}
