import type { Metadata } from 'next';
import { SearchResultsClient } from '@/modules/customer/components/SearchResultsClient';

export const metadata: Metadata = {
  title: 'Search Local Businesses',
  robots: { index: false, follow: true },
};

interface SearchPageProps {
  searchParams: {
    city?: string;
    q?: string;
    category?: string;
  };
}

export default function SearchPage({ searchParams }: SearchPageProps) {
  return (
    <SearchResultsClient
      initialCity={searchParams.city ?? 'chennai'}
      initialQuery={searchParams.q ?? ''}
      initialCategory={searchParams.category ?? ''}
    />
  );
}
