<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domains\Leads\Models\Enquiry;
use App\Domains\Leads\Services\EnquiryManagementService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Leads\CancelEnquiryRequest;
use App\Http\Resources\Api\V1\Leads\EnquiryResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class AdminEnquiryController extends Controller
{
    public function __construct(
        private readonly EnquiryManagementService $enquiryService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAdmin', Enquiry::class);

        $query = Enquiry::query()->with(['recipients.business', 'category', 'city', 'locality', 'targetBusiness']);

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('enquiry_type')) {
            $query->where('enquiry_type', (string) $request->query('enquiry_type'));
        }

        if ($request->filled('phone_e164')) {
            $query->where('phone_e164', (string) $request->query('phone_e164'));
        }

        if ($request->filled('city_id')) {
            $query->where('city_id', (int) $request->query('city_id'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->query('category_id'));
        }

        $enquiries = $query->orderByDesc('created_at')->paginate(25);

        return ApiResponse::paginated(
            $enquiries,
            EnquiryResource::collection($enquiries)->resolve($request)
        );
    }

    public function show(Request $request, Enquiry $enquiry): JsonResponse
    {
        Gate::authorize('viewAdmin', Enquiry::class);

        $enquiry->load(['recipients.business', 'category', 'city', 'locality', 'targetBusiness', 'cancelledBy']);

        return ApiResponse::success(
            (new EnquiryResource($enquiry))->resolve($request)
        );
    }

    public function cancel(CancelEnquiryRequest $request, Enquiry $enquiry): JsonResponse
    {
        Gate::authorize('cancelAdmin', $enquiry);

        $cancelled = $this->enquiryService->cancelEnquiryByAdmin(
            $enquiry,
            $request->user(),
            (string) $request->validated()['reason']
        );

        $cancelled->load(['recipients.business', 'category', 'city', 'locality', 'targetBusiness', 'cancelledBy']);

        return ApiResponse::success(
            (new EnquiryResource($cancelled))->resolve($request),
            ['message' => 'Enquiry has been administratively cancelled.']
        );
    }
}
