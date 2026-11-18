<?php

declare(strict_types=1);

namespace Database\Seeders\Reference;

use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryTranslation;
use Illuminate\Database\Seeder;

class CategoriesSeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            [
                'slug' => 'healthcare',
                'name' => 'Healthcare',
                'name_ta' => 'மருத்துவம் மற்றும் சுகாதாரம்',
                'schema' => 'MedicalBusiness',
                'icon' => 'heart-pulse',
                'featured' => true,
                'synonyms_en' => 'medical, clinic, doctor, health care, maruthuvam',
                'synonyms_ta' => 'மருத்துவம், மருத்துவர், கிளினிக், சுகாதாரம்',
                'children' => [
                    [
                        'slug' => 'dentists',
                        'name' => 'Dentists',
                        'name_ta' => 'பல் மருத்துவர்கள்',
                        'schema' => 'Dentist',
                        'icon' => 'tooth',
                        'featured' => true,
                        'synonyms_en' => 'dental clinic, tooth doctor, orthodontist, root canal, pal maruthuvar',
                        'synonyms_ta' => 'பல் மருத்துவர், பல் மருத்துவமனை, பல் சிகிச்சை',
                    ],
                    [
                        'slug' => 'hospitals',
                        'name' => 'Hospitals',
                        'name_ta' => 'மருத்துவமனைகள்',
                        'schema' => 'Hospital',
                        'icon' => 'hospital',
                        'featured' => true,
                        'synonyms_en' => 'multispeciality hospital, nursing home, emergency care, maruthuvamanai',
                        'synonyms_ta' => 'மருத்துவமனை, ஆஸ்பத்திரி, அவசர சிகிச்சை',
                    ],
                    [
                        'slug' => 'pharmacies',
                        'name' => 'Pharmacies & Chemists',
                        'name_ta' => 'மருந்தகங்கள்',
                        'schema' => 'Pharmacy',
                        'icon' => 'pill',
                        'featured' => true,
                        'synonyms_en' => 'medical shop, chemist, medicine store, marunthagam',
                        'synonyms_ta' => 'மருந்து கடை, மருந்தகம், மெடிக்கல்',
                    ],
                    [
                        'slug' => 'diagnostic-labs',
                        'name' => 'Diagnostic & Pathology Labs',
                        'name_ta' => 'பரிசோதனை மையங்கள்',
                        'schema' => 'DiagnosticLab',
                        'icon' => 'flask',
                        'featured' => false,
                        'synonyms_en' => 'blood test, scan center, pathology lab, x-ray',
                        'synonyms_ta' => 'இரத்த பரிசோதனை, ஸ்கேன் மையம், லேப்',
                    ],
                ],
            ],
            [
                'slug' => 'food-and-dining',
                'name' => 'Food & Dining',
                'name_ta' => 'உணவு மற்றும் உணவகங்கள்',
                'schema' => 'FoodEstablishment',
                'icon' => 'utensils',
                'featured' => true,
                'synonyms_en' => 'dining, eateries, food, unavu',
                'synonyms_ta' => 'உணவு, சாப்பாடு, ஹோட்டல்',
                'children' => [
                    [
                        'slug' => 'restaurants',
                        'name' => 'Restaurants',
                        'name_ta' => 'உணவகங்கள்',
                        'schema' => 'Restaurant',
                        'icon' => 'utensils',
                        'featured' => true,
                        'synonyms_en' => 'hotel, veg restaurant, non veg, family dining, unavagam',
                        'synonyms_ta' => 'உணவகம், ஹோட்டல், சைவ உணவகம், அசைவ உணவகம்',
                    ],
                    [
                        'slug' => 'bakeries-and-sweets',
                        'name' => 'Bakeries & Sweet Shops',
                        'name_ta' => 'பேக்கரிகள் மற்றும் இனிப்பகங்கள்',
                        'schema' => 'Bakery',
                        'icon' => 'cake',
                        'featured' => false,
                        'synonyms_en' => 'cake shop, bakery, sweets, snacks, inippagam',
                        'synonyms_ta' => 'பேக்கரி, கேக் கடை, இனிப்பகம், ஸ்வீட் ஸ்டால்',
                    ],
                    [
                        'slug' => 'caterers',
                        'name' => 'Caterers',
                        'name_ta' => 'உணவு தயாரிப்பாளர்கள்',
                        'schema' => 'FoodEstablishment',
                        'icon' => 'chef-hat',
                        'featured' => false,
                        'synonyms_en' => 'wedding catering, event catering, samayal',
                        'synonyms_ta' => 'கேட்டரிங், திருமண சமையல், உணவு சப்ளை',
                    ],
                ],
            ],
            [
                'slug' => 'home-services',
                'name' => 'Home Services & Repairs',
                'name_ta' => 'வீட்டு சேவைகள் மற்றும் பழுதுபார்ப்பு',
                'schema' => 'HomeAndConstructionBusiness',
                'icon' => 'wrench',
                'featured' => true,
                'synonyms_en' => 'repairs, maintenance, home improvement, veetu sevaigal',
                'synonyms_ta' => 'வீட்டு சேவை, பழுதுபார்ப்பு, பராமரிப்பு',
                'children' => [
                    [
                        'slug' => 'plumbers',
                        'name' => 'Plumbers',
                        'name_ta' => 'குழாய் பழுதுபார்ப்பவர்கள்',
                        'schema' => 'Plumber',
                        'icon' => 'droplet',
                        'featured' => true,
                        'synonyms_en' => 'plumbing, pipe leak, tap repair, motor fitting',
                        'synonyms_ta' => 'பிளம்பர், குழாய் பழுது, தண்ணீர் குழாய்',
                    ],
                    [
                        'slug' => 'electricians',
                        'name' => 'Electricians',
                        'name_ta' => 'மின்சார தொழில்நுட்ப வல்லுநர்கள்',
                        'schema' => 'Electrician',
                        'icon' => 'zap',
                        'featured' => true,
                        'synonyms_en' => 'electrical repair, wiring, inverter, minsaram',
                        'synonyms_ta' => 'எலக்ட்ரீஷியன், மின்சார பழுது, வயரிங்',
                    ],
                    [
                        'slug' => 'ac-repair-services',
                        'name' => 'AC Repair & Service',
                        'name_ta' => 'ஏசி பழுதுபார்ப்பு மற்றும் சேவை',
                        'schema' => 'HVACBusiness',
                        'icon' => 'wind',
                        'featured' => true,
                        'synonyms_en' => 'AC, air conditioner, gas filling, split ac service',
                        'synonyms_ta' => 'ஏசி சர்வீஸ், ஏசி ரிப்பேர், குளிரூட்டி',
                    ],
                    [
                        'slug' => 'pest-control',
                        'name' => 'Pest Control Services',
                        'name_ta' => 'பூச்சி கட்டுப்பாட்டு சேவைகள்',
                        'schema' => 'HomeAndConstructionBusiness',
                        'icon' => 'shield',
                        'featured' => false,
                        'synonyms_en' => 'termite control, cockroach control, mosquito',
                        'synonyms_ta' => 'பூச்சி ஒழிப்பு, கரையான் கட்டுப்பாடு',
                    ],
                ],
            ],
            [
                'slug' => 'automotive',
                'name' => 'Automotive & Transport',
                'name_ta' => 'வாகன சேவைகள் மற்றும் போக்குவரத்து',
                'schema' => 'AutomotiveBusiness',
                'icon' => 'car',
                'featured' => true,
                'synonyms_en' => 'auto care, garage, transport, vaganam',
                'synonyms_ta' => 'வாகனம், கார் சர்வீஸ், போக்குவரத்து',
                'children' => [
                    [
                        'slug' => 'car-repair-and-service',
                        'name' => 'Car Repair & Service',
                        'name_ta' => 'கார் பழுதுபார்ப்பு மற்றும் சேவை',
                        'schema' => 'AutoRepair',
                        'icon' => 'wrench',
                        'featured' => true,
                        'synonyms_en' => 'car mechanic, car wash, garage, auto repair',
                        'synonyms_ta' => 'கார் மெக்கானிக், கார் சர்வீஸ், கேரேஜ்',
                    ],
                    [
                        'slug' => 'packers-and-movers',
                        'name' => 'Packers & Movers',
                        'name_ta' => 'பேக்கர்ஸ் மற்றும் மூவர்ஸ்',
                        'schema' => 'MovingCompany',
                        'icon' => 'truck',
                        'featured' => true,
                        'synonyms_en' => 'house shifting, relocation, cargo movers',
                        'synonyms_ta' => 'வீடு மாற்றம், சரக்கு போக்குவரத்து, மூவர்ஸ்',
                    ],
                ],
            ],
            [
                'slug' => 'education',
                'name' => 'Education & Training',
                'name_ta' => 'கல்வி மற்றும் பயிற்சி',
                'schema' => 'EducationalOrganization',
                'icon' => 'graduation-cap',
                'featured' => true,
                'synonyms_en' => 'tuition, institute, classes, kalvi',
                'synonyms_ta' => 'கல்வி, பயிற்சி, வகுப்புகள்',
                'children' => [
                    [
                        'slug' => 'coaching-institutes',
                        'name' => 'Coaching & Training Institutes',
                        'name_ta' => 'பயிற்சி மையங்கள்',
                        'schema' => 'EducationalOrganization',
                        'icon' => 'book-open',
                        'featured' => true,
                        'synonyms_en' => 'NEET coaching, TNPSC, UPSC, IT training, tuition center',
                        'synonyms_ta' => 'பயிற்சி மையம், டியூஷன், போட்டித் தேர்வு பயிற்சி',
                    ],
                ],
            ],
            [
                'slug' => 'events-and-weddings',
                'name' => 'Events & Weddings',
                'name_ta' => 'நிகழ்வுகள் மற்றும் திருமண சேவைகள்',
                'schema' => 'EventVenue',
                'icon' => 'sparkles',
                'featured' => true,
                'synonyms_en' => 'marriage hall, kalyana mandapam, event planner',
                'synonyms_ta' => 'திருமணம், கல்யாண மண்டபம், நிகழ்வு ஏற்பாட்டாளர்',
                'children' => [
                    [
                        'slug' => 'banquet-and-marriage-halls',
                        'name' => 'Banquet & Marriage Halls',
                        'name_ta' => 'திருமண மண்டபங்கள்',
                        'schema' => 'EventVenue',
                        'icon' => 'building',
                        'featured' => true,
                        'synonyms_en' => 'kalyana mandapam, party hall, wedding venue',
                        'synonyms_ta' => 'கல்யாண மண்டபம், திருமண மண்டபம், விருந்து அரங்கம்',
                    ],
                ],
            ],
        ];

        $rootOrder = 1;
        foreach ($tree as $rootData) {
            $root = Category::query()->updateOrCreate(
                ['slug' => $rootData['slug']],
                [
                    'parent_id' => null,
                    'name' => $rootData['name'],
                    'schema_org_type' => $rootData['schema'],
                    'icon_name' => $rootData['icon'],
                    'depth' => 0,
                    'is_featured' => $rootData['featured'],
                    'sort_order' => $rootOrder++,
                    'is_active' => true,
                ]
            );

            $this->upsertTranslations($root, $rootData);

            $childOrder = 1;
            foreach ($rootData['children'] as $childData) {
                $child = Category::query()->updateOrCreate(
                    ['slug' => $childData['slug']],
                    [
                        'parent_id' => $root->id,
                        'name' => $childData['name'],
                        'schema_org_type' => $childData['schema'],
                        'icon_name' => $childData['icon'],
                        'depth' => 1,
                        'is_featured' => $childData['featured'],
                        'sort_order' => $childOrder++,
                        'is_active' => true,
                    ]
                );

                $this->upsertTranslations($child, $childData);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsertTranslations(Category $category, array $data): void
    {
        CategoryTranslation::query()->updateOrCreate(
            ['category_id' => $category->id, 'locale' => 'en'],
            [
                'name' => $data['name'],
                'synonyms' => $data['synonyms_en'],
                'seo_title_template' => "Best {$data['name']} in {city}",
                'seo_description_template' => "Compare verified {$data['name']} in {city} with ratings, reviews, timings, and direct contact details.",
            ]
        );

        CategoryTranslation::query()->updateOrCreate(
            ['category_id' => $category->id, 'locale' => 'ta'],
            [
                'name' => $data['name_ta'],
                'synonyms' => $data['synonyms_ta'],
                'seo_title_template' => "{city} சிறந்த {$data['name_ta']}",
                'seo_description_template' => "{city} பகுதியில் உள்ள சரிபார்க்கப்பட்ட {$data['name_ta']}, மதிப்பீடுகள் மற்றும் தொடர்பு விவரங்களைப் பார்க்கவும்.",
            ]
        );
    }
}
