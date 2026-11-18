'use client';

import { useRouter } from 'next/navigation';
import { FormEvent, useState } from 'react';
import { MapPin, Search } from 'lucide-react';
import { DEMO_CITIES } from '@/shared/data/demo-directory';

interface HeroSearchFormProps {
  initialCity?: string;
  initialQuery?: string;
}

export function HeroSearchForm({
  initialCity = 'chennai',
  initialQuery = '',
}: HeroSearchFormProps) {
  const router = useRouter();
  const [city, setCity] = useState(initialCity);
  const [query, setQuery] = useState(initialQuery);

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const params = new URLSearchParams();
    if (city) params.set('city', city);
    if (query.trim()) params.set('q', query.trim());
    router.push(`/search?${params.toString()}`);
  }

  return (
    <form
      onSubmit={handleSubmit}
      role="search"
      aria-label="Search local businesses"
      className="flex flex-col gap-2 rounded-2xl border border-zinc-200 bg-white p-2 shadow-sm transition-shadow focus-within:border-zinc-400 focus-within:shadow-md sm:flex-row sm:items-center"
    >
      <div className="flex items-center gap-2 border-b border-zinc-100 px-3 py-2 sm:w-52 sm:border-b-0 sm:border-r sm:border-zinc-200">
        <MapPin className="h-4 w-4 shrink-0 text-zinc-600" aria-hidden="true" />
        <label htmlFor="hero-city" className="sr-only">
          Select city
        </label>
        <select
          id="hero-city"
          value={city}
          onChange={(e) => setCity(e.target.value)}
          className="w-full bg-transparent text-sm font-medium text-zinc-950 focus:outline-none"
        >
          {DEMO_CITIES.map((c) => (
            <option key={c.slug} value={c.slug}>
              {c.name} ({c.nameTa})
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-1 items-center gap-2 px-3 py-2">
        <Search className="h-4 w-4 shrink-0 text-zinc-500" aria-hidden="true" />
        <label htmlFor="hero-query" className="sr-only">
          Search services, categories, or business names
        </label>
        <input
          id="hero-query"
          type="search"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="Search dentists, AC repair, restaurants, பல் மருத்துவர்..."
          className="w-full bg-transparent text-sm text-zinc-950 placeholder:text-zinc-400 focus:outline-none"
        />
      </div>

      <button
        type="submit"
        className="inline-flex items-center justify-center rounded-xl bg-zinc-950 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-zinc-800"
      >
        Search
      </button>
    </form>
  );
}
