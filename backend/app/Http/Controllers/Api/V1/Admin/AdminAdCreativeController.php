<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Advertising\Models\AdCampaign;
use App\Domains\Advertising\Models\AdCreative;
use App\Domains\Governance\Services\AuditLogger;
use App\Domains\Media\Exceptions\MediaException;
use App\Domains\Media\Services\FileSignatureValidator;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class AdminAdCreativeController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly FileSignatureValidator $signatureValidator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:140'],
            'locale' => ['nullable', 'string', Rule::in(['en', 'ta'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'paused', 'rejected', 'archived'])],
            'campaign_public_id' => ['nullable', 'string', 'size:26'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $query = AdCreative::query()->with('campaign:id,public_id,name,status');

        if (! empty($validated['q'])) {
            $term = trim((string) $validated['q']);
            $query->where(static function ($q) use ($term): void {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('subtitle', 'like', "%{$term}%")
                    ->orWhere('destination_url', 'like', "%{$term}%");
            });
        }
        if (! empty($validated['locale'])) {
            $query->where('locale', (string) $validated['locale']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }
        if (! empty($validated['campaign_public_id'])) {
            $campaignId = AdCampaign::query()->where('public_id', $validated['campaign_public_id'])->value('id');
            if ($campaignId !== null) {
                $query->where('ad_campaign_id', $campaignId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $creatives = $query->orderByDesc('created_at')->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::paginated(
            $creatives,
            collect($creatives->items())->map(fn (AdCreative $cr): array => $this->serializeCreative($cr))->values()->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'ad_campaign_public_id' => ['required', 'string', 'exists:ad_campaigns,public_id'],
            'title' => ['required', 'string', 'min:2', 'max:140'],
            'subtitle' => ['nullable', 'string', 'max:240'],
            'cta_label' => ['nullable', 'string', 'max:40'],
            'destination_url' => ['required', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'mobile_image_url' => ['nullable', 'string', 'max:500'],
            'alt_text' => ['nullable', 'string', 'max:200'],
            'locale' => ['required', 'string', Rule::in(['en', 'ta'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'paused'])],
        ]);

        $campaign = AdCampaign::query()->where('public_id', $validated['ad_campaign_public_id'])->firstOrFail();
        /** @var User $actor */
        $actor = $request->user();

        try {
            $creative = DB::transaction(function () use ($actor, $campaign, $validated, $request): AdCreative {
                $record = AdCreative::query()->create([
                    'ad_campaign_id' => $campaign->id,
                    'title' => trim((string) $validated['title']),
                    'subtitle' => isset($validated['subtitle']) ? trim((string) $validated['subtitle']) : null,
                    'cta_label' => isset($validated['cta_label']) ? trim((string) $validated['cta_label']) : null,
                    'destination_url' => trim((string) $validated['destination_url']),
                    'image_url' => $validated['image_url'] ?? null,
                    'mobile_image_url' => $validated['mobile_image_url'] ?? null,
                    'alt_text' => $validated['alt_text'] ?? null,
                    'locale' => (string) $validated['locale'],
                    'status' => (string) ($validated['status'] ?? 'active'),
                ]);

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'ad_creative.created',
                    auditable: $record,
                    newValues: ['campaign_public_id' => $campaign->public_id, 'locale' => $record->locale],
                    actorRole: 'super_admin',
                    ipAddress: $request->ip(),
                );

                return $record;
            });
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error('INVALID_CREATIVE_DATA', $e->getMessage(), 422);
        }

        $creative->load('campaign:id,public_id,name,status');

        return ApiResponse::created($this->serializeCreative($creative), ['message' => 'Ad creative created.']);
    }

    public function update(Request $request, AdCreative $adCreative): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'min:2', 'max:140'],
            'subtitle' => ['nullable', 'string', 'max:240'],
            'cta_label' => ['nullable', 'string', 'max:40'],
            'destination_url' => ['sometimes', 'string', 'max:500'],
            'image_url' => ['nullable', 'string', 'max:500'],
            'mobile_image_url' => ['nullable', 'string', 'max:500'],
            'alt_text' => ['nullable', 'string', 'max:200'],
            'locale' => ['sometimes', 'string', Rule::in(['en', 'ta'])],
            'status' => ['sometimes', 'string', Rule::in(['active', 'paused', 'rejected'])],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            DB::transaction(function () use ($actor, $adCreative, $validated, $request): void {
                $adCreative->fill($validated)->save();

                $this->auditLogger->record(
                    actor: $actor,
                    action: 'ad_creative.updated',
                    auditable: $adCreative,
                    newValues: ['status' => $adCreative->status, 'locale' => $adCreative->locale],
                    actorRole: 'super_admin',
                    ipAddress: $request->ip(),
                );
            });
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error('INVALID_CREATIVE_DATA', $e->getMessage(), 422);
        }

        $adCreative->load('campaign:id,public_id,name,status');

        return ApiResponse::success($this->serializeCreative($adCreative), ['message' => 'Ad creative updated.']);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        $request->validate([
            'image' => ['required', 'file', 'max:5120'],
            'campaign_public_id' => ['nullable', 'string', 'size:26', 'regex:/^[0-9A-Za-z]{26}$/', 'exists:ad_campaigns,public_id'],
        ]);

        $file = $request->file('image');
        if ($file === null) {
            return ApiResponse::error('INVALID_UPLOAD', 'Image file is required.', 422);
        }

        try {
            $validatedUpload = $this->signatureValidator->validatePublicImage($file);
        } catch (MediaException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $ext = match ($validatedUpload->mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $folder = 'ads/creatives/'.($request->input('campaign_public_id') ?: 'library');
        $filename = Str::lower((string) Str::ulid()).'.'.$ext;
        $storedPath = $file->storeAs($folder, $filename, 'public');

        return ApiResponse::created([
            'image_url' => Storage::disk('public')->url($storedPath),
            'mime_type' => $validatedUpload->mimeType,
            'width' => $validatedUpload->width,
            'height' => $validatedUpload->height,
        ], ['message' => 'Creative image uploaded securely.']);
    }

    public function destroy(Request $request, AdCreative $adCreative): JsonResponse
    {
        Gate::authorize('manageAdmin', User::class);

        /** @var User $actor */
        $actor = $request->user();

        DB::transaction(function () use ($actor, $adCreative, $request): void {
            $adCreative->status = 'archived';
            $adCreative->save();
            $adCreative->delete();

            $this->auditLogger->record(
                actor: $actor,
                action: 'ad_creative.archived',
                auditable: $adCreative,
                actorRole: 'super_admin',
                ipAddress: $request->ip(),
            );
        });

        return ApiResponse::success(['archived' => true], ['message' => 'Creative archived.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCreative(AdCreative $cr): array
    {
        return [
            'public_id' => $cr->public_id,
            'title' => $cr->title,
            'subtitle' => $cr->subtitle,
            'cta_label' => $cr->cta_label,
            'destination_url' => $cr->destination_url,
            'image_url' => $cr->image_url,
            'mobile_image_url' => $cr->mobile_image_url,
            'alt_text' => $cr->alt_text,
            'locale' => $cr->locale,
            'status' => $cr->status,
            'campaign' => $cr->campaign ? [
                'public_id' => $cr->campaign->public_id,
                'name' => $cr->campaign->name,
                'status' => $cr->campaign->status,
            ] : null,
            'created_at' => $cr->created_at?->toIso8601String(),
        ];
    }
}
