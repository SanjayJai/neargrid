<?php

declare(strict_types=1);

namespace Database\Seeders\Reference;

use App\Domains\Location\Models\City;
use App\Domains\Location\Models\CityTranslation;
use App\Domains\Location\Models\Locality;
use App\Domains\Location\Models\LocalityTranslation;
use App\Domains\Location\Models\State;
use Illuminate\Database\Seeder;

class LocationsSeeder extends Seeder
{
    public function run(): void
    {
        $states = [
            'tamil-nadu' => ['name' => 'Tamil Nadu', 'country_code' => 'IN'],
            'maharashtra' => ['name' => 'Maharashtra', 'country_code' => 'IN'],
            'karnataka' => ['name' => 'Karnataka', 'country_code' => 'IN'],
            'delhi' => ['name' => 'Delhi', 'country_code' => 'IN'],
            'telangana' => ['name' => 'Telangana', 'country_code' => 'IN'],
        ];

        $stateModels = [];
        foreach ($states as $slug => $data) {
            $stateModels[$slug] = State::query()->updateOrCreate(
                ['country_code' => $data['country_code'], 'slug' => $slug],
                ['name' => $data['name'], 'is_active' => true]
            );
        }

        $cities = [
            [
                'state' => 'tamil-nadu',
                'slug' => 'chennai',
                'name' => 'Chennai',
                'name_ta' => 'சென்னை',
                'lat' => 13.0826802,
                'lng' => 80.2707184,
                'is_popular' => true,
                'sort_order' => 1,
                'localities' => [
                    ['slug' => 't-nagar', 'name' => 'T. Nagar', 'name_ta' => 'தி. நகர்', 'pincode' => '600017', 'lat' => 13.0417590, 'lng' => 80.2340750, 'popular' => true],
                    ['slug' => 'adyar', 'name' => 'Adyar', 'name_ta' => 'அடையாறு', 'pincode' => '600020', 'lat' => 13.0011774, 'lng' => 80.2564957, 'popular' => true],
                    ['slug' => 'anna-nagar', 'name' => 'Anna Nagar', 'name_ta' => 'அண்ணா நகர்', 'pincode' => '600040', 'lat' => 13.0849557, 'lng' => 80.2101342, 'popular' => true],
                    ['slug' => 'velachery', 'name' => 'Velachery', 'name_ta' => 'வேளச்சேரி', 'pincode' => '600042', 'lat' => 12.9815100, 'lng' => 80.2180200, 'popular' => true],
                ],
            ],
            [
                'state' => 'tamil-nadu',
                'slug' => 'coimbatore',
                'name' => 'Coimbatore',
                'name_ta' => 'கோயம்புத்தூர்',
                'lat' => 11.0168445,
                'lng' => 76.9558321,
                'is_popular' => true,
                'sort_order' => 2,
                'localities' => [
                    ['slug' => 'rs-puram', 'name' => 'R.S. Puram', 'name_ta' => 'ஆர்.எஸ். புரம்', 'pincode' => '641002', 'lat' => 11.0086500, 'lng' => 76.9510000, 'popular' => true],
                    ['slug' => 'gandhipuram', 'name' => 'Gandhipuram', 'name_ta' => 'காந்திபுரம்', 'pincode' => '641012', 'lat' => 11.0183000, 'lng' => 76.9688000, 'popular' => true],
                    ['slug' => 'peelamedu', 'name' => 'Peelamedu', 'name_ta' => 'பீளமேடு', 'pincode' => '641004', 'lat' => 11.0283000, 'lng' => 77.0028000, 'popular' => true],
                ],
            ],
            [
                'state' => 'tamil-nadu',
                'slug' => 'madurai',
                'name' => 'Madurai',
                'name_ta' => 'மதுரை',
                'lat' => 9.9252007,
                'lng' => 78.1197754,
                'is_popular' => true,
                'sort_order' => 3,
                'localities' => [
                    ['slug' => 'anna-nagar-madurai', 'name' => 'Anna Nagar', 'name_ta' => 'அண்ணா நகர்', 'pincode' => '625020', 'lat' => 9.9225000, 'lng' => 78.1489000, 'popular' => true],
                    ['slug' => 'kk-nagar-madurai', 'name' => 'K.K. Nagar', 'name_ta' => 'கே.கே. நகர்', 'pincode' => '625020', 'lat' => 9.9369000, 'lng' => 78.1460000, 'popular' => true],
                ],
            ],
            [
                'state' => 'tamil-nadu',
                'slug' => 'vellore',
                'name' => 'Vellore',
                'name_ta' => 'வேலூர்',
                'lat' => 12.9165167,
                'lng' => 79.1324986,
                'is_popular' => false,
                'sort_order' => 4,
                'localities' => [
                    ['slug' => 'katpadi', 'name' => 'Katpadi', 'name_ta' => 'காட்பாடி', 'pincode' => '632007', 'lat' => 12.9698000, 'lng' => 79.1459000, 'popular' => true],
                    ['slug' => 'gandhi-nagar-vellore', 'name' => 'Gandhi Nagar', 'name_ta' => 'காந்தி நகர்', 'pincode' => '632006', 'lat' => 12.9547000, 'lng' => 79.1382000, 'popular' => true],
                ],
            ],
            [
                'state' => 'maharashtra',
                'slug' => 'mumbai',
                'name' => 'Mumbai',
                'name_ta' => 'மும்பை',
                'lat' => 19.0759837,
                'lng' => 72.8776559,
                'is_popular' => true,
                'sort_order' => 5,
                'localities' => [
                    ['slug' => 'andheri-west', 'name' => 'Andheri West', 'name_ta' => 'அந்தேரி மேற்கு', 'pincode' => '400053', 'lat' => 19.1364000, 'lng' => 72.8296000, 'popular' => true],
                    ['slug' => 'bandra-west', 'name' => 'Bandra West', 'name_ta' => 'பாந்த்ரா மேற்கு', 'pincode' => '400050', 'lat' => 19.0596000, 'lng' => 72.8295000, 'popular' => true],
                ],
            ],
            [
                'state' => 'karnataka',
                'slug' => 'bengaluru',
                'name' => 'Bengaluru',
                'name_ta' => 'பெங்களூரு',
                'lat' => 12.9715987,
                'lng' => 77.5945627,
                'is_popular' => true,
                'sort_order' => 6,
                'localities' => [
                    ['slug' => 'indiranagar', 'name' => 'Indiranagar', 'name_ta' => 'இந்திரா நகர்', 'pincode' => '560038', 'lat' => 12.9784000, 'lng' => 77.6408000, 'popular' => true],
                    ['slug' => 'koramangala', 'name' => 'Koramangala', 'name_ta' => 'கோரமங்களா', 'pincode' => '560034', 'lat' => 12.9352000, 'lng' => 77.6245000, 'popular' => true],
                ],
            ],
            [
                'state' => 'delhi',
                'slug' => 'new-delhi',
                'name' => 'New Delhi',
                'name_ta' => 'புது தில்லி',
                'lat' => 28.6139391,
                'lng' => 77.2090212,
                'is_popular' => true,
                'sort_order' => 7,
                'localities' => [
                    ['slug' => 'connaught-place', 'name' => 'Connaught Place', 'name_ta' => 'கன்னாட் பிளேஸ்', 'pincode' => '110001', 'lat' => 28.6315000, 'lng' => 77.2167000, 'popular' => true],
                ],
            ],
            [
                'state' => 'telangana',
                'slug' => 'hyderabad',
                'name' => 'Hyderabad',
                'name_ta' => 'ஹைதராபாத்',
                'lat' => 17.3850440,
                'lng' => 78.4866710,
                'is_popular' => true,
                'sort_order' => 8,
                'localities' => [
                    ['slug' => 'banjara-hills', 'name' => 'Banjara Hills', 'name_ta' => 'பஞ்சாரா ஹில்ஸ்', 'pincode' => '500034', 'lat' => 17.4156000, 'lng' => 78.4347000, 'popular' => true],
                ],
            ],
        ];

        foreach ($cities as $cityData) {
            $city = City::query()->updateOrCreate(
                ['slug' => $cityData['slug']],
                [
                    'state_id' => $stateModels[$cityData['state']]->id,
                    'name' => $cityData['name'],
                    'latitude' => $cityData['lat'],
                    'longitude' => $cityData['lng'],
                    'timezone' => 'Asia/Kolkata',
                    'is_popular' => $cityData['is_popular'],
                    'sort_order' => $cityData['sort_order'],
                    'seo_title' => "Local Businesses & Services in {$cityData['name']}",
                    'seo_description' => "Discover verified local businesses, doctors, restaurants, and home services in {$cityData['name']}.",
                    'is_active' => true,
                ]
            );

            CityTranslation::query()->updateOrCreate(
                ['city_id' => $city->id, 'locale' => 'en'],
                [
                    'name' => $cityData['name'],
                    'seo_title' => "Local Businesses & Services in {$cityData['name']}",
                    'seo_description' => "Find top-rated verified services in {$cityData['name']}.",
                ]
            );

            CityTranslation::query()->updateOrCreate(
                ['city_id' => $city->id, 'locale' => 'ta'],
                [
                    'name' => $cityData['name_ta'],
                    'seo_title' => "{$cityData['name_ta']} உள்ளூர் வணிகங்கள் மற்றும் சேவைகள்",
                    'seo_description' => "{$cityData['name_ta']} பகுதியில் சரிபார்க்கப்பட்ட வணிகங்கள் மற்றும் சேவைகளை கண்டறியுங்கள்.",
                ]
            );

            foreach ($cityData['localities'] as $locData) {
                $locality = Locality::query()->updateOrCreate(
                    ['city_id' => $city->id, 'slug' => $locData['slug']],
                    [
                        'name' => $locData['name'],
                        'pincode' => $locData['pincode'],
                        'latitude' => $locData['lat'],
                        'longitude' => $locData['lng'],
                        'is_popular' => $locData['popular'],
                        'is_active' => true,
                    ]
                );

                LocalityTranslation::query()->updateOrCreate(
                    ['locality_id' => $locality->id, 'locale' => 'en'],
                    ['name' => $locData['name']]
                );

                LocalityTranslation::query()->updateOrCreate(
                    ['locality_id' => $locality->id, 'locale' => 'ta'],
                    ['name' => $locData['name_ta']]
                );
            }
        }
    }
}
