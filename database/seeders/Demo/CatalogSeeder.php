<?php

namespace Database\Seeders\Demo;

use App\Models\Admin;
use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\HealthIssue;
use App\Models\Media;
use App\Models\OpeningAd;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\StockMovement;
use Illuminate\Database\Seeder;

/**
 * Categories, brands, health issues, products with sizes, images, banners and the opening ad.
 * Opening stock goes through ProductSize::setStock(), so every size has its "initial" ledger row.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $categories = $this->categories();
        $brands = $this->brands();
        $issues = $this->healthIssues();
        $adminId = Admin::value('id');

        foreach ($this->products() as $data) {
            $product = Product::create([
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'description_ar' => $data['description_ar'],
                'description_en' => $data['description_en'],
                'image' => "products/{$data['slug']}.png",
                'category_id' => $categories[$data['category']]->id,
                'brand_id' => $brands[$data['brand']]->id,
            ]);

            foreach ($data['sizes'] as [$label, $price, $stock]) {
                $size = $product->sizes()->create(['size' => $label, 'price' => $price, 'stock' => 0]);

                ProductSize::setStock($size->id, $stock, StockMovement::TYPE_INITIAL, [
                    'admin_id' => $adminId,
                    'reason' => 'Product created',
                ]);
            }

            foreach (range(1, $data['images']) as $i) {
                $product->productImages()->create(['image_path' => "products/{$data['slug']}-{$i}.png"]);
            }

            $product->healthIssues()->attach(collect($data['issues'])->map(fn ($key) => $issues[$key]->id)->all());
        }

        $this->banners();

        OpeningAd::create(['image' => 'opening_ads/welcome.png', 'is_active' => true]);
        OpeningAd::create(['image' => 'opening_ads/old-campaign.png', 'is_active' => false]);
    }

    /** @return array<string, Category> */
    private function categories(): array
    {
        $make = fn (string $en, string $ar, string $slug, ?Category $parent = null) => Category::create([
            'name_en' => $en,
            'name_ar' => $ar,
            'image' => "categories/{$slug}.png",
            'parent_id' => $parent?->id,
        ]);

        $medicines = $make('Medicines', 'الأدوية', 'medicines');

        return [
            'medicines' => $medicines,
            'pain' => $make('Pain Relief', 'المسكنات', 'pain-relief', $medicines),
            'cold' => $make('Cold & Flu', 'البرد والإنفلونزا', 'cold-flu', $medicines),
            'vitamins' => $make('Vitamins & Supplements', 'الفيتامينات والمكملات', 'vitamins'),
            'skin' => $make('Skin Care', 'العناية بالبشرة', 'skin-care'),
            'baby' => $make('Mother & Baby', 'الأم والطفل', 'mother-baby'),
            'personal' => $make('Personal Care', 'العناية الشخصية', 'personal-care'),
            'devices' => $make('Medical Devices', 'الأجهزة الطبية', 'medical-devices'),
        ];
    }

    /** @return array<string, Brand> */
    private function brands(): array
    {
        $make = fn (string $en, string $ar, string $slug) => Brand::create(['name_en' => $en, 'name_ar' => $ar, 'image' => "brands/{$slug}.png"]);

        return [
            'nile' => $make('Nile Pharma', 'نايل فارما', 'nile-pharma'),
            'vita' => $make('VitaPlus', 'فيتا بلس', 'vitaplus'),
            'derma' => $make('DermaCare', 'ديرما كير', 'dermacare'),
            'baby' => $make('BabySoft', 'بيبي سوفت', 'babysoft'),
            'fresh' => $make('FreshMed', 'فريش ميد', 'freshmed'),
            'check' => $make('CheckWell', 'تشيك ويل', 'checkwell'),
        ];
    }

    /** @return array<string, HealthIssue> */
    private function healthIssues(): array
    {
        $make = fn (string $en, string $ar, string $slug) => HealthIssue::create(['name_en' => $en, 'name_ar' => $ar, 'image' => "health_issues/{$slug}.png"]);

        return [
            'headache' => $make('Headache', 'الصداع', 'headache'),
            'cold' => $make('Cold & Flu', 'البرد والإنفلونزا', 'cold-flu'),
            'immunity' => $make('Immunity', 'المناعة', 'immunity'),
            'dry_skin' => $make('Dry Skin', 'جفاف البشرة', 'dry-skin'),
            'diabetes' => $make('Diabetes Care', 'رعاية مرضى السكري', 'diabetes'),
            'joints' => $make('Joint Pain', 'آلام المفاصل', 'joint-pain'),
        ];
    }

    /**
     * sizes: [label, price, opening stock]. One product is completely sold out and one size is
     * at low stock, so the "out of stock" and "low stock" screens have data.
     */
    private function products(): array
    {
        return [
            [
                'slug' => 'paracetamol-500', 'category' => 'pain', 'brand' => 'nile', 'images' => 2, 'issues' => ['headache', 'cold'],
                'name_en' => 'Paracetamol 500 mg', 'name_ar' => 'باراسيتامول 500 مجم',
                'description_en' => 'Relieves mild to moderate pain and reduces fever.',
                'description_ar' => 'يخفف الآلام الخفيفة والمتوسطة ويخفض الحرارة.',
                'sizes' => [['20 tablets', 35, 120], ['40 tablets', 60, 80]],
            ],
            [
                'slug' => 'ibuprofen-400', 'category' => 'pain', 'brand' => 'nile', 'images' => 1, 'issues' => ['headache', 'joints'],
                'name_en' => 'Ibuprofen 400 mg', 'name_ar' => 'إيبوبروفين 400 مجم',
                'description_en' => 'Anti-inflammatory pain reliever for headaches, muscle and joint pain.',
                'description_ar' => 'مسكن مضاد للالتهاب لآلام الرأس والعضلات والمفاصل.',
                'sizes' => [['20 tablets', 48, 60]],
            ],
            [
                'slug' => 'joint-gel', 'category' => 'pain', 'brand' => 'fresh', 'images' => 1, 'issues' => ['joints'],
                'name_en' => 'Joint Relief Gel', 'name_ar' => 'جل لتخفيف آلام المفاصل',
                'description_en' => 'Cooling gel for sore joints and muscles.',
                'description_ar' => 'جل مبرد لآلام المفاصل والعضلات.',
                'sizes' => [['50 g', 75, 3], ['100 g', 130, 25]],
            ],
            [
                'slug' => 'cold-flu-day', 'category' => 'cold', 'brand' => 'nile', 'images' => 1, 'issues' => ['cold'],
                'name_en' => 'Cold & Flu Day Relief', 'name_ar' => 'أقراص البرد والإنفلونزا النهارية',
                'description_en' => 'Relieves congestion, headache and fever without drowsiness.',
                'description_ar' => 'تخفف احتقان الأنف والصداع والحرارة دون التسبب في النعاس.',
                'sizes' => [['12 tablets', 55, 70]],
            ],
            [
                'slug' => 'cough-syrup', 'category' => 'cold', 'brand' => 'fresh', 'images' => 1, 'issues' => ['cold'],
                'name_en' => 'Honey Cough Syrup', 'name_ar' => 'شراب الكحة بالعسل',
                'description_en' => 'Soothes dry and chesty coughs.',
                'description_ar' => 'يهدئ الكحة الجافة والمصحوبة بالبلغم.',
                'sizes' => [['100 ml', 42, 50], ['200 ml', 70, 30]],
            ],
            [
                'slug' => 'vitamin-c-1000', 'category' => 'vitamins', 'brand' => 'vita', 'images' => 2, 'issues' => ['immunity', 'cold'],
                'name_en' => 'Vitamin C 1000 mg Effervescent', 'name_ar' => 'فيتامين سي 1000 مجم فوار',
                'description_en' => 'Supports the immune system. One tablet a day dissolved in water.',
                'description_ar' => 'يدعم الجهاز المناعي. قرص واحد يوميًا يذاب في الماء.',
                'sizes' => [['10 tablets', 65, 90], ['20 tablets', 115, 40]],
            ],
            [
                'slug' => 'vitamin-d3', 'category' => 'vitamins', 'brand' => 'vita', 'images' => 1, 'issues' => ['immunity', 'joints'],
                'name_en' => 'Vitamin D3 5000 IU', 'name_ar' => 'فيتامين د3 5000 وحدة',
                'description_en' => 'Supports bones, muscles and immunity.',
                'description_ar' => 'يدعم صحة العظام والعضلات والمناعة.',
                'sizes' => [['30 capsules', 140, 45]],
            ],
            [
                'slug' => 'zinc-plus', 'category' => 'vitamins', 'brand' => 'vita', 'images' => 1, 'issues' => ['immunity'],
                'name_en' => 'Zinc Plus', 'name_ar' => 'زنك بلس',
                'description_en' => 'Zinc with vitamin C for everyday immune support.',
                'description_ar' => 'زنك مع فيتامين سي لدعم المناعة يوميًا.',
                'sizes' => [['30 tablets', 90, 0]], // sold out
            ],
            [
                'slug' => 'omega-3', 'category' => 'vitamins', 'brand' => 'vita', 'images' => 1, 'issues' => ['joints'],
                'name_en' => 'Omega-3 Fish Oil', 'name_ar' => 'أوميجا 3 زيت السمك',
                'description_en' => 'Supports heart and joint health.',
                'description_ar' => 'يدعم صحة القلب والمفاصل.',
                'sizes' => [['60 capsules', 210, 35]],
            ],
            [
                'slug' => 'moisturizing-cream', 'category' => 'skin', 'brand' => 'derma', 'images' => 2, 'issues' => ['dry_skin'],
                'name_en' => 'Intense Moisturizing Cream', 'name_ar' => 'كريم ترطيب مكثف',
                'description_en' => '24-hour hydration for dry and very dry skin.',
                'description_ar' => 'ترطيب لمدة 24 ساعة للبشرة الجافة والجافة جدًا.',
                'sizes' => [['50 ml', 185, 40], ['200 ml', 420, 15]],
            ],
            [
                'slug' => 'sunscreen-spf50', 'category' => 'skin', 'brand' => 'derma', 'images' => 1, 'issues' => [],
                'name_en' => 'Sunscreen SPF 50+', 'name_ar' => 'واقي شمس SPF 50+',
                'description_en' => 'High protection, non-greasy, suitable for sensitive skin.',
                'description_ar' => 'حماية عالية، غير دهني، مناسب للبشرة الحساسة.',
                'sizes' => [['50 ml', 260, 30]],
            ],
            [
                'slug' => 'baby-diaper-cream', 'category' => 'baby', 'brand' => 'baby', 'images' => 1, 'issues' => ['dry_skin'],
                'name_en' => 'Baby Diaper Cream', 'name_ar' => 'كريم الحفاض للأطفال',
                'description_en' => 'Protects and soothes the baby’s skin.',
                'description_ar' => 'يحمي ويهدئ بشرة الطفل.',
                'sizes' => [['50 g', 58, 55]],
            ],
            [
                'slug' => 'baby-shampoo', 'category' => 'baby', 'brand' => 'baby', 'images' => 1, 'issues' => [],
                'name_en' => 'No-Tears Baby Shampoo', 'name_ar' => 'شامبو أطفال بدون دموع',
                'description_en' => 'Gentle formula for daily use.',
                'description_ar' => 'تركيبة لطيفة للاستخدام اليومي.',
                'sizes' => [['200 ml', 65, 40], ['500 ml', 120, 20]],
            ],
            [
                'slug' => 'prenatal-vitamins', 'category' => 'baby', 'brand' => 'vita', 'images' => 1, 'issues' => ['immunity'],
                'name_en' => 'Prenatal Multivitamin', 'name_ar' => 'فيتامينات الحمل المتعددة',
                'description_en' => 'Folic acid, iron and DHA for pregnancy.',
                'description_ar' => 'حمض الفوليك والحديد وأوميجا DHA للحامل.',
                'sizes' => [['30 tablets', 175, 30]],
            ],
            [
                'slug' => 'mouthwash', 'category' => 'personal', 'brand' => 'fresh', 'images' => 1, 'issues' => [],
                'name_en' => 'Antiseptic Mouthwash', 'name_ar' => 'غسول فم مطهر',
                'description_en' => 'Kills germs and freshens breath.',
                'description_ar' => 'يقضي على الجراثيم وينعش النفس.',
                'sizes' => [['250 ml', 55, 60]],
            ],
            [
                'slug' => 'glucose-meter', 'category' => 'devices', 'brand' => 'check', 'images' => 2, 'issues' => ['diabetes'],
                'name_en' => 'Blood Glucose Meter Kit', 'name_ar' => 'جهاز قياس السكر',
                'description_en' => 'Meter with 10 test strips and lancets.',
                'description_ar' => 'جهاز مع 10 شرائط اختبار وإبر وخز.',
                'sizes' => [['Kit', 650, 12]],
            ],
            [
                'slug' => 'glucose-strips', 'category' => 'devices', 'brand' => 'check', 'images' => 1, 'issues' => ['diabetes'],
                'name_en' => 'Glucose Test Strips', 'name_ar' => 'شرائط قياس السكر',
                'description_en' => 'Compatible with the CheckWell meter.',
                'description_ar' => 'متوافقة مع جهاز تشيك ويل.',
                'sizes' => [['25 strips', 180, 40], ['50 strips', 330, 20]],
            ],
            [
                'slug' => 'bp-monitor', 'category' => 'devices', 'brand' => 'check', 'images' => 1, 'issues' => [],
                'name_en' => 'Digital Blood Pressure Monitor', 'name_ar' => 'جهاز قياس الضغط الرقمي',
                'description_en' => 'Upper-arm monitor with memory for two users.',
                'description_ar' => 'جهاز على الذراع مع ذاكرة لمستخدمين.',
                'sizes' => [['1 device', 950, 8]],
            ],
        ];
    }

    private function banners(): void
    {
        $banners = [
            ['title_en' => 'Winter immunity offers', 'title_ar' => 'عروض المناعة في الشتاء', 'slug' => 'immunity', 'status' => 1],
            ['title_en' => 'Skin care essentials', 'title_ar' => 'أساسيات العناية بالبشرة', 'slug' => 'skin-care', 'status' => 1],
            ['title_en' => 'Everything for your baby', 'title_ar' => 'كل ما يحتاجه طفلك', 'slug' => 'baby', 'status' => 1],
            ['title_en' => 'Summer campaign (ended)', 'title_ar' => 'حملة الصيف (منتهية)', 'slug' => 'summer', 'status' => 0],
        ];

        foreach ($banners as $i => $banner) {
            $model = Banner::create([
                'title_en' => $banner['title_en'],
                'title_ar' => $banner['title_ar'],
                'image' => "banners/{$banner['slug']}.png",
                'link' => null,
                'status' => $banner['status'],
                'order_of_appearance' => $i + 1,
            ]);

            // The media table is not read by the app yet; a few rows keep it populated.
            if ($i === 0) {
                $media = new Media(['url' => 'media/immunity-banner.png', 'type' => 'image']);
                $media->mediaable()->associate($model)->save();

                $video = new Media(['url' => 'media/immunity-banner.mp4', 'type' => 'video']);
                $video->mediaable()->associate($model)->save();
            }
        }
    }
}
