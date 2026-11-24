<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Media;

use App\Domains\Directory\Jobs\DispatchRevalidationWebhookJob;
use App\Domains\Directory\Models\Business;
use App\Domains\Media\Enums\BusinessMediaType;
use App\Domains\Media\Enums\MediaUploadStatus;
use App\Domains\Media\Models\BusinessMedia;
use App\Domains\Media\Services\BusinessMediaService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Media\UploadBusinessMediaRequest;
use App\Http\Resources\Api\V1\Media\BusinessMediaResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

final class BusinessMediaController extends Controller
{
    public function __construct(
        private readonly BusinessMediaService $mediaService,
    ) {}

    public function index(Request $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('view', $business);

        $media = $business->media()->get();

        return ApiResponse::success(BusinessMediaResource::collection($media)->resolve($request));
    }

    public function store(UploadBusinessMediaRequest $request, Business $business): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('manageMedia', $business);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $validated = $request->validated();

        $media = $this->mediaService->uploadPublicMedia(
            actor: $user,
            business: $business,
            file: $file,
            mediaType: BusinessMediaType::from((string) $validated['media_type']),
            altText: isset($validated['alt_text']) ? (string) $validated['alt_text'] : null,
            sortOrder: isset($validated['sort_order']) ? (int) $validated['sort_order'] : 0,
        );

        $status = $media->upload_status === MediaUploadStatus::Ready
            ? Response::HTTP_CREATED
            : Response::HTTP_ACCEPTED;

        $citySlug = $business->city?->slug ?? 'chennai';
        if ($business->slug) {
            DispatchRevalidationWebhookJob::dispatch([
                "/{$citySlug}/business/{$business->slug}",
            ], 'business_media_uploaded')->afterCommit();
        }

        return ApiResponse::success(
            data: (new BusinessMediaResource($media))->resolve($request),
            status: $status,
        );
    }

    public function destroy(Request $request, Business $business, BusinessMedia $media): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::forUser($user)->authorize('delete', [$media, $business]);

        $this->mediaService->deletePublicMedia($user, $business, $media);

        $citySlug = $business->city?->slug ?? 'chennai';
        if ($business->slug) {
            DispatchRevalidationWebhookJob::dispatch([
                "/{$citySlug}/business/{$business->slug}",
            ], 'business_media_deleted')->afterCommit();
        }

        return ApiResponse::success(['deleted' => true]);
    }
}
