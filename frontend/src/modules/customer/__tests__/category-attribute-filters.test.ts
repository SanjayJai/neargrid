import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { HorizontalFilterBar } from '@/modules/customer/components/HorizontalFilterBar';
import {
  buildShareableSearchUrl,
  parseRawSearchParams,
  urlSearchParamsToRecord,
} from '@/modules/customer/lib/search-url';
import type { SearchFiltersDataDto } from '@/shared/api/directory-types';

(globalThis as unknown as { React: typeof React }).React = React;

const sampleFiltersData: SearchFiltersDataDto = {
  cities: [{ slug: 'chennai', name: 'Chennai', is_popular: true }],
  localities: [],
  categories: [{ public_id: '01ARZ3NDEKTSV4RRFFQ69G5FAV', slug: 'clinics', name: 'Clinics', depth: 1, is_featured: true }],
  amenities: [],
  payment_methods: [],
  rating_options: [{ value: 4, label: '4.0 & above' }],
  sort_options: [{ value: 'relevance', label: 'Relevance' }],
  radius: { min_km: 0.5, max_km: 50, default_km: 10 },
  category_filters: [
    {
      filter_key: 'emergency_24x7',
      filter_type: 'toggle',
      data_type: 'boolean',
      label: '24x7 Emergency',
      label_en: '24x7 Emergency',
      label_ta: null,
      unit: null,
      options: [],
      min: null,
      max: null,
      sort_order: 1,
    },
    {
      filter_key: 'hospital_tier',
      filter_type: 'select',
      data_type: 'enum',
      label: 'Hospital Tier',
      label_en: 'Hospital Tier',
      label_ta: null,
      unit: null,
      options: [{ value: 'multi_speciality', label: 'Multi Speciality' }],
      min: null,
      max: null,
      sort_order: 2,
    },
    {
      filter_key: 'specialities',
      filter_type: 'checkbox',
      data_type: 'multi_enum',
      label: 'Specialities',
      label_en: 'Specialities',
      label_ta: null,
      unit: null,
      options: [
        { value: 'cardiology', label: 'Cardiology' },
        { value: 'orthopedics', label: 'Orthopedics' },
      ],
      min: null,
      max: null,
      sort_order: 3,
    },
    {
      filter_key: 'bed_count',
      filter_type: 'range',
      data_type: 'number',
      label: 'Bed Count',
      label_en: 'Bed Count',
      label_ta: null,
      unit: 'beds',
      options: [],
      min: 1,
      max: 1000,
      sort_order: 4,
    },
  ],
};

describe('M-A3 M3 Category-Aware Search URL Sync & HorizontalFilterBar', () => {
  it('round-trips boolean, select, multi_select and range attr filters through URL search params (back/forward & SSR)', () => {
    const builtUrl = buildShareableSearchUrl({
      city: 'chennai',
      category: 'clinics',
      attr: {
        emergency_24x7: true,
        hospital_tier: 'multi_speciality',
        specialities: ['cardiology', 'orthopedics'],
        bed_count: { min: 10, max: 100 },
      },
    });

    const query = builtUrl.split('?')[1] ?? '';
    const parsedBack = parseRawSearchParams(urlSearchParamsToRecord(new URLSearchParams(query)));

    expect(parsedBack.city).toBe('chennai');
    expect(parsedBack.category).toBe('clinics');
    expect(parsedBack.attr).toEqual({
      emergency_24x7: '1',
      hospital_tier: 'multi_speciality',
      specialities: ['cardiology', 'orthopedics'],
      bed_count: { min: 10, max: 100 },
    });
  });

  it('drops inverted min > max range and enforces max 8 attribute filters in URL parser', () => {
    const raw: Record<string, string> = {
      'attr[bed_count][min]': '200',
      'attr[bed_count][max]': '50',
      'attr[f1]': '1',
      'attr[f2]': '1',
      'attr[f3]': '1',
      'attr[f4]': '1',
      'attr[f5]': '1',
      'attr[f6]': '1',
      'attr[f7]': '1',
      'attr[f8]': '1',
      'attr[f9]': '1',
    };

    const parsed = parseRawSearchParams(raw);
    expect(parsed.attr?.bed_count).toBeUndefined();
    expect(Object.keys(parsed.attr ?? {}).length).toBeLessThanOrEqual(8);
  });

  it('renders More Filters badge count and removable active category attribute chips', () => {
    const html = renderToStaticMarkup(
      React.createElement(HorizontalFilterBar, {
        currentParams: {
          city: 'chennai',
          category: 'clinics',
          attr: {
            emergency_24x7: true,
            bed_count: { min: 10, max: 50 },
          },
        },
        filtersData: sampleFiltersData,
        onFilterChange: vi.fn(),
        onResetFilters: vi.fn(),
        hasActiveFilters: true,
        totalResults: 5,
      })
    );

    expect(html).toContain('More Filters (2)');
    expect(html).toContain('emergency_24x7: Yes');
    expect(html).toContain('bed_count: 10–50');
  });
});
