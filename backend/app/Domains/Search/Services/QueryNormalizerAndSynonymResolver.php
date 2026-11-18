<?php

declare(strict_types=1);

namespace App\Domains\Search\Services;

use App\Domains\Search\DTOs\ParsedQuery;
use App\Domains\Search\Models\SearchSynonym;
use App\Domains\Taxonomy\Models\Category;
use App\Domains\Taxonomy\Models\CategoryTranslation;
use Normalizer;

final class QueryNormalizerAndSynonymResolver
{
    /**
     * Common English stop-words excluded from standalone token matching.
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'a', 'an', 'and', 'at', 'best', 'by', 'for', 'from', 'in', 'me',
        'near', 'nearby', 'of', 'on', 'or', 'the', 'to', 'top', 'with',
    ];

    public function parse(?string $rawQuery): ParsedQuery
    {
        if ($rawQuery === null) {
            return new ParsedQuery(
                originalQuery: null,
                normalizedQuery: null,
                tokens: [],
                expandedTerms: [],
                matchedCategoryIds: [],
                containsTamil: false,
            );
        }

        // Preserve original trimmed display query safely (strip control chars only).
        $original = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $rawQuery));
        $original = trim((string) preg_replace('/\s+/u', ' ', $original));

        if ($original === '') {
            return new ParsedQuery(
                originalQuery: null,
                normalizedQuery: null,
                tokens: [],
                expandedTerms: [],
                matchedCategoryIds: [],
                containsTamil: false,
            );
        }

        $normalized = $this->normalizeUnicode($original);
        $containsTamil = preg_match('/\p{Tamil}/u', $normalized) === 1;

        // Extract safe alphanumeric / Tamil Unicode tokens (strips SQL punctuation & operators).
        $cleanedForTokens = (string) preg_replace('/[^\p{L}\p{N}\p{M}\s]+/u', ' ', $normalized);
        $cleanedForTokens = trim((string) preg_replace('/\s+/u', ' ', $cleanedForTokens));

        $rawTokens = $cleanedForTokens === '' ? [] : (preg_split('/\s+/u', $cleanedForTokens) ?: []);
        $tokens = [];
        foreach ($rawTokens as $token) {
            if ($token === '' || mb_strlen($token, 'UTF-8') < 2) {
                continue;
            }
            if (! $containsTamil && in_array($token, self::STOP_WORDS, true)) {
                continue;
            }
            $tokens[] = $token;
        }
        $tokens = array_values(array_unique($tokens));

        if ($tokens === [] && $cleanedForTokens !== '') {
            $tokens = [$cleanedForTokens];
        }

        [$expandedTerms, $matchedCategoryIds] = $this->resolveSynonymsAndCategories($cleanedForTokens, $tokens, $containsTamil);

        return new ParsedQuery(
            originalQuery: $original,
            normalizedQuery: $cleanedForTokens !== '' ? $cleanedForTokens : $normalized,
            tokens: $tokens,
            expandedTerms: $expandedTerms,
            matchedCategoryIds: $matchedCategoryIds,
            containsTamil: $containsTamil,
        );
    }

    public function normalizeUnicode(string $value): string
    {
        $trimmed = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $trimmed));

        if (class_exists(Normalizer::class)) {
            $nfc = Normalizer::normalize($collapsed, Normalizer::FORM_C);
            if (is_string($nfc)) {
                $collapsed = $nfc;
            }
        }

        return mb_strtolower($collapsed, 'UTF-8');
    }

    /**
     * @param  list<string>  $tokens
     * @return array{0: list<string>, 1: list<int>}
     */
    private function resolveSynonymsAndCategories(string $normalizedPhrase, array $tokens, bool $containsTamil): array
    {
        $expanded = [];
        if ($normalizedPhrase !== '') {
            $expanded[] = $normalizedPhrase;
        }
        foreach ($tokens as $token) {
            $expanded[] = $token;
        }

        $matchedCategoryIds = [];
        $matchedCanonicals = [];

        $synonyms = SearchSynonym::query()
            ->where('is_active', true)
            ->get(['normalized_term', 'canonical_term', 'category_id', 'locale']);

        $candidates = array_values(array_unique(array_filter([$normalizedPhrase, ...$tokens])));

        foreach ($synonyms as $synonym) {
            $synNorm = $synonym->normalized_term;
            $matched = false;

            foreach ($candidates as $candidate) {
                if ($candidate === $synNorm) {
                    $matched = true;
                    break;
                }

                // Handle Tamil inflectional suffixes (e.g. மருத்துவமனைகள் / மருத்துவமனையில் -> மருத்துவமனை)
                if ($containsTamil && mb_strlen($synNorm, 'UTF-8') >= 4 && str_starts_with($candidate, $synNorm)) {
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                $matchedCanonicals[] = $synonym->canonical_term;
                $expanded[] = $synonym->canonical_term;
                if ($synonym->category_id !== null) {
                    $matchedCategoryIds[] = (int) $synonym->category_id;
                }
            }
        }

        // Pull sibling synonyms sharing the same canonical term (e.g. plambar -> plumber, பிளம்பர்)
        if ($matchedCanonicals !== []) {
            $canonicalSet = array_values(array_unique($matchedCanonicals));
            foreach ($synonyms as $synonym) {
                if (in_array($synonym->canonical_term, $canonicalSet, true)) {
                    $expanded[] = $synonym->normalized_term;
                    if ($synonym->category_id !== null) {
                        $matchedCategoryIds[] = (int) $synonym->category_id;
                    }
                }
            }
        }

        // Also match directly against active category translations (EN + TA names & synonyms)
        $translations = CategoryTranslation::query()
            ->whereHas('category', static fn ($q) => $q->where('is_active', true))
            ->get(['category_id', 'name', 'synonyms']);

        foreach ($translations as $translation) {
            $nameNorm = $this->normalizeUnicode((string) $translation->name);
            $synList = array_filter(array_map(
                fn (string $part): string => $this->normalizeUnicode($part),
                explode(',', (string) ($translation->synonyms ?? ''))
            ));

            foreach ($candidates as $candidate) {
                if ($candidate === $nameNorm || in_array($candidate, $synList, true)) {
                    $matchedCategoryIds[] = (int) $translation->category_id;
                    $expanded[] = $nameNorm;
                    break;
                }

                if ($containsTamil && mb_strlen($candidate, 'UTF-8') >= 4) {
                    if (str_starts_with($nameNorm, $candidate) || str_starts_with($candidate, $nameNorm)) {
                        $matchedCategoryIds[] = (int) $translation->category_id;
                        $expanded[] = $nameNorm;
                        break;
                    }
                }
            }
        }

        // Expand parent category matches to include active child subcategories
        $matchedCategoryIds = array_values(array_unique($matchedCategoryIds));
        if ($matchedCategoryIds !== []) {
            $childIds = Category::query()
                ->where('is_active', true)
                ->whereIn('parent_id', $matchedCategoryIds)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $matchedCategoryIds = array_values(array_unique([...$matchedCategoryIds, ...$childIds]));
        }

        $expanded = array_values(array_unique(array_filter($expanded, static fn (string $t): bool => $t !== '')));

        return [$expanded, $matchedCategoryIds];
    }
}
