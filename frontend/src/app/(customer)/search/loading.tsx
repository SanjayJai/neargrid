import { DirectoryCardSkeleton } from '@/shared/ui/Skeletons';

export default function SearchLoading() {
  return (
    <div className="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
      <div className="h-12 animate-pulse rounded-xl bg-gray-100" />
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-4">
        <div className="hidden h-96 animate-pulse rounded-xl bg-gray-50 lg:block" />
        <div className="space-y-4 lg:col-span-3">
          <DirectoryCardSkeleton count={4} />
        </div>
      </div>
    </div>
  );
}
