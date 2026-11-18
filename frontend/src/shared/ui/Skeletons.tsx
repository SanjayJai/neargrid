export function DirectoryCardSkeleton({ count = 4 }: { count?: number }) {
  return (
    <div className="space-y-4" role="status" aria-label="Loading businesses">
      {Array.from({ length: count }).map((_, idx) => (
        <div
          key={idx}
          className="flex animate-pulse flex-col gap-4 rounded-xl border border-gray-200 bg-white p-4 sm:flex-row"
        >
          <div className="h-40 w-full shrink-0 rounded-lg bg-gray-200 sm:h-36 sm:w-44" />
          <div className="flex-1 space-y-3">
            <div className="h-5 w-2/3 rounded bg-gray-200" />
            <div className="flex gap-2">
              <div className="h-5 w-16 rounded bg-gray-200" />
              <div className="h-5 w-24 rounded bg-gray-200" />
            </div>
            <div className="h-4 w-5/6 rounded bg-gray-100" />
            <div className="flex gap-2 pt-2">
              <div className="h-9 w-28 rounded-lg bg-gray-200" />
              <div className="h-9 w-28 rounded-lg bg-gray-100" />
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

export function CategoryGridSkeleton({ count = 8 }: { count?: number }) {
  return (
    <div
      className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8"
      role="status"
      aria-label="Loading categories"
    >
      {Array.from({ length: count }).map((_, idx) => (
        <div
          key={idx}
          className="flex animate-pulse flex-col items-center gap-2 rounded-xl border border-gray-200 bg-white p-4"
        >
          <div className="h-12 w-12 rounded-xl bg-gray-200" />
          <div className="h-3.5 w-16 rounded bg-gray-200" />
        </div>
      ))}
    </div>
  );
}

export function DashboardPanelSkeleton() {
  return (
    <div className="animate-pulse space-y-4 rounded-xl border border-gray-200 bg-white p-6" role="status" aria-label="Loading panel">
      <div className="h-5 w-48 rounded bg-gray-200" />
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div className="h-20 rounded-lg bg-gray-100" />
        <div className="h-20 rounded-lg bg-gray-100" />
        <div className="h-20 rounded-lg bg-gray-100" />
      </div>
      <div className="h-32 rounded-lg bg-gray-100" />
    </div>
  );
}
