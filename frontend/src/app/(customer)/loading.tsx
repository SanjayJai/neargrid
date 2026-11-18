import { CategoryGridSkeleton, DirectoryCardSkeleton } from '@/shared/ui/Skeletons';

export default function CustomerLoading() {
  return (
    <div className="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
      <div className="h-14 animate-pulse rounded-xl bg-gray-200" />
      <CategoryGridSkeleton count={8} />
      <DirectoryCardSkeleton count={3} />
    </div>
  );
}
