<?php

declare(strict_types=1);

namespace Database\Seeders\Reference;

use App\Domains\Search\Models\SearchSynonym;
use App\Domains\Taxonomy\Models\Category;
use Illuminate\Database\Seeder;
use Normalizer;

class SearchSynonymsSeeder extends Seeder
{
    public function run(): void
    {
        $categories = Category::query()->pluck('id', 'slug');

        $groups = [
            [
                'category_slug' => 'plumbers',
                'canonical' => 'plumber',
                'entries' => [
                    ['plumber', 'en', 1.00],
                    ['plumbing', 'en', 0.95],
                    ['pipe leak', 'en', 0.90],
                    ['tap repair', 'en', 0.90],
                    ['பிளம்பர்', 'ta', 1.00],
                    ['குழாய் பழுது', 'ta', 0.95],
                    ['குழாய் பழுதுபார்ப்பவர்', 'ta', 0.95],
                    ['குழாய் பழுதுபார்ப்பவர்கள்', 'ta', 0.95],
                    ['தண்ணீர் குழாய்', 'ta', 0.85],
                    ['plambar', 'ta-Latn', 1.00],
                    ['kuzhai pazhuthu', 'ta-Latn', 0.90],
                ],
            ],
            [
                'category_slug' => 'electricians',
                'canonical' => 'electrician',
                'entries' => [
                    ['electrician', 'en', 1.00],
                    ['electrical repair', 'en', 0.95],
                    ['wiring', 'en', 0.90],
                    ['inverter repair', 'en', 0.85],
                    ['எலக்ட்ரீஷியன்', 'ta', 1.00],
                    ['மின்சார பழுது', 'ta', 0.95],
                    ['மின்சாரம்', 'ta', 0.90],
                    ['வயரிங்', 'ta', 0.90],
                    ['electrisian', 'ta-Latn', 0.95],
                    ['minsaram', 'ta-Latn', 0.95],
                ],
            ],
            [
                'category_slug' => 'hospitals',
                'canonical' => 'hospital',
                'entries' => [
                    ['hospital', 'en', 1.00],
                    ['hospitals', 'en', 1.00],
                    ['nursing home', 'en', 0.90],
                    ['emergency care', 'en', 0.90],
                    ['மருத்துவமனை', 'ta', 1.00],
                    ['மருத்துவமனைகள்', 'ta', 1.00],
                    ['ஆஸ்பத்திரி', 'ta', 0.95],
                    ['அவசர சிகிச்சை', 'ta', 0.90],
                    ['maruthuvamanai', 'ta-Latn', 1.00],
                    ['aaspathiri', 'ta-Latn', 0.95],
                ],
            ],
            [
                'category_slug' => 'restaurants',
                'canonical' => 'hotel',
                'entries' => [
                    ['hotel', 'en', 1.00],
                    ['restaurant', 'en', 1.00],
                    ['restaurants', 'en', 1.00],
                    ['veg restaurant', 'en', 0.90],
                    ['dining', 'en', 0.85],
                    ['உணவகம்', 'ta', 1.00],
                    ['உணவகங்கள்', 'ta', 1.00],
                    ['ஹோட்டல்', 'ta', 1.00],
                    ['சாப்பாடு', 'ta', 0.90],
                    ['unavagam', 'ta-Latn', 1.00],
                    ['saapadu', 'ta-Latn', 0.90],
                ],
            ],
            [
                'category_slug' => 'dentists',
                'canonical' => 'dentist',
                'entries' => [
                    ['dentist', 'en', 1.00],
                    ['dental clinic', 'en', 1.00],
                    ['tooth doctor', 'en', 0.95],
                    ['root canal', 'en', 0.90],
                    ['பல் மருத்துவர்', 'ta', 1.00],
                    ['பல் மருத்துவர்கள்', 'ta', 1.00],
                    ['பல் மருத்துவமனை', 'ta', 1.00],
                    ['பல் சிகிச்சை', 'ta', 0.95],
                    ['pal maruthuvar', 'ta-Latn', 1.00],
                ],
            ],
            [
                'category_slug' => 'pharmacies',
                'canonical' => 'pharmacy',
                'entries' => [
                    ['pharmacy', 'en', 1.00],
                    ['medical shop', 'en', 1.00],
                    ['chemist', 'en', 0.95],
                    ['மருந்தகம்', 'ta', 1.00],
                    ['மருந்து கடை', 'ta', 1.00],
                    ['மெடிக்கல்', 'ta', 0.95],
                    ['marunthagam', 'ta-Latn', 1.00],
                    ['marunthu kadai', 'ta-Latn', 0.95],
                ],
            ],
            [
                'category_slug' => 'ac-repair-services',
                'canonical' => 'ac repair',
                'entries' => [
                    ['ac', 'en', 0.95],
                    ['ac repair', 'en', 1.00],
                    ['ac service', 'en', 1.00],
                    ['air conditioner', 'en', 0.95],
                    ['ஏசி சர்வீஸ்', 'ta', 1.00],
                    ['ஏசி ரிப்பேர்', 'ta', 1.00],
                    ['குளிரூட்டி', 'ta', 0.90],
                ],
            ],
            [
                'category_slug' => 'banquet-and-marriage-halls',
                'canonical' => 'marriage hall',
                'entries' => [
                    ['marriage hall', 'en', 1.00],
                    ['banquet hall', 'en', 1.00],
                    ['wedding venue', 'en', 0.95],
                    ['கல்யாண மண்டபம்', 'ta', 1.00],
                    ['திருமண மண்டபம்', 'ta', 1.00],
                    ['kalyana mandapam', 'ta-Latn', 1.00],
                    ['thirumana mandapam', 'ta-Latn', 0.95],
                ],
            ],
            [
                'category_slug' => 'bakeries-and-sweets',
                'canonical' => 'bakery',
                'entries' => [
                    ['bakery', 'en', 1.00],
                    ['cake shop', 'en', 0.95],
                    ['sweets', 'en', 0.90],
                    ['பேக்கரி', 'ta', 1.00],
                    ['இனிப்பகம்', 'ta', 1.00],
                    ['inippagam', 'ta-Latn', 1.00],
                ],
            ],
            [
                'category_slug' => 'car-repair-and-service',
                'canonical' => 'car repair',
                'entries' => [
                    ['car repair', 'en', 1.00],
                    ['car mechanic', 'en', 1.00],
                    ['garage', 'en', 0.90],
                    ['கார் மெக்கானிக்', 'ta', 1.00],
                    ['கார் சர்வீஸ்', 'ta', 1.00],
                ],
            ],
        ];

        foreach ($groups as $group) {
            $categoryId = $categories[$group['category_slug']] ?? null;
            $canonical = self::normalize($group['canonical']);

            foreach ($group['entries'] as [$term, $locale, $weight]) {
                $normalized = self::normalize($term);

                SearchSynonym::query()->updateOrCreate(
                    [
                        'normalized_term' => $normalized,
                        'canonical_term' => $canonical,
                        'locale' => $locale,
                    ],
                    [
                        'term' => $term,
                        'category_id' => $categoryId,
                        'weight' => $weight,
                        'is_active' => true,
                    ]
                );
            }
        }
    }

    private static function normalize(string $value): string
    {
        $trimmed = trim((string) preg_replace('/\s+/u', ' ', $value));
        if (class_exists(Normalizer::class)) {
            $nfc = Normalizer::normalize($trimmed, Normalizer::FORM_C);
            if (is_string($nfc)) {
                $trimmed = $nfc;
            }
        }

        return mb_strtolower($trimmed, 'UTF-8');
    }
}
