<?php

declare(strict_types=1);

namespace App\Domains\Seo\Services;

use App\Domains\Governance\Models\UrlRedirect;
use App\Domains\Seo\Exceptions\SeoException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class RedirectLifecycleService
{
    private const SAFE_INTERNAL_PATH_REGEX = '/^\/[a-z0-9\-\/]+$/i';

    public function registerRedirect(
        string $sourcePath,
        string $targetPath,
        ?string $entityType = null,
        ?int $entityId = null,
        int $httpStatus = 301,
        ?int $domainId = null,
    ): UrlRedirect {
        $source = $this->normalizePath($sourcePath);
        $target = $this->normalizePath($targetPath);
        $scopeKey = UrlRedirect::scopeKeyFor($domainId);

        if ($source === $target) {
            throw new SeoException(
                'REDIRECT_SELF_LOOP',
                'Redirect source and target paths cannot be identical.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return DB::transaction(function () use ($source, $target, $entityType, $entityId, $httpStatus, $domainId, $scopeKey): UrlRedirect {
            // Collapse existing chains pointing to old sourcePath directly to new targetPath within scope.
            UrlRedirect::query()
                ->where('scope_key', $scopeKey)
                ->where('target_path', $source)
                ->update(['target_path' => $target]);

            // Remove any reverse redirect that would create a 2-hop loop within scope.
            UrlRedirect::query()
                ->where('scope_key', $scopeKey)
                ->where('source_path', $target)
                ->where('target_path', $source)
                ->delete();

            return UrlRedirect::query()->updateOrCreate(
                [
                    'scope_key' => $scopeKey,
                    'source_path' => $source,
                ],
                [
                    'domain_id' => $domainId,
                    'target_path' => $target,
                    'http_status' => $httpStatus,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'is_active' => true,
                ]
            );
        });
    }

    public function resolvePath(string $sourcePath, ?int $domainId = null): ?string
    {
        $current = $this->normalizePath($sourcePath);
        $scopeKeys = array_values(array_unique([UrlRedirect::scopeKeyFor($domainId), 'global']));
        $visited = [$current => true];
        $resolved = null;

        for ($hop = 0; $hop < 5; $hop++) {
            $redirect = UrlRedirect::query()
                ->where('source_path', $current)
                ->where('is_active', true)
                ->whereIn('scope_key', $scopeKeys)
                ->orderByRaw('CASE WHEN scope_key = ? THEN 0 ELSE 1 END', [UrlRedirect::scopeKeyFor($domainId)])
                ->first();

            if ($redirect === null) {
                break;
            }

            $next = trim((string) $redirect->target_path);
            if (! $this->isSafeInternalPath($next) || isset($visited[$next])) {
                return null;
            }

            $visited[$next] = true;
            $resolved = $next;
            $current = $next;
        }

        return $resolved;
    }

    private function normalizePath(string $path): string
    {
        $clean = '/'.ltrim(trim($path), '/');
        if (! $this->isSafeInternalPath($clean)) {
            throw new SeoException(
                'INVALID_REDIRECT_PATH',
                "Redirect path '{$path}' must be a safe internal path.",
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $clean;
    }

    private function isSafeInternalPath(string $path): bool
    {
        return preg_match(self::SAFE_INTERNAL_PATH_REGEX, $path) === 1;
    }
}
