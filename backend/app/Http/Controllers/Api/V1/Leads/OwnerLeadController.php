<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Leads;

use App\Domains\Directory\Models\Business;
use App\Domains\Leads\Enums\LeadStatus;
use App\Domains\Leads\Models\EnquiryRecipient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Leads\ListOwnerLeadsRequest;
use App\Http\Requests\Api\V1\Leads\UpdateOwnerLeadRequest;
use App\Http\Resources\Api\V1\Leads\OwnerLeadResource;
use App\Models\User;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class OwnerLeadController extends Controller
{
    public function index(ListOwnerLeadsRequest $request, Business $business): JsonResponse
    {
        Gate::authorize('viewLeads', $business);

        $query = EnquiryRecipient::query()
            ->where('business_id', $business->id)
            ->with(['enquiry.category', 'enquiry.city', 'enquiry.locality', 'assignedUser']);

        if ($request->filled('status')) {
            $query->where('lead_status', (string) $request->query('status'));
        }

        $perPage = min(50, max(1, (int) $request->query('per_page', 15)));
        $leads = $query->orderByDesc('id')->cursorPaginate($perPage);

        return ApiResponse::paginated(
            $leads,
            OwnerLeadResource::collection($leads->items())->resolve($request)
        );
    }

    public function update(UpdateOwnerLeadRequest $request, Business $business, EnquiryRecipient $lead): JsonResponse
    {
        Gate::authorize('manageLeads', $business);

        // Strict cross-business IDOR prevention
        if ($lead->business_id !== $business->id) {
            return ApiResponse::error(
                'LEAD_NOT_FOUND',
                'The specified lead does not belong to this business.',
                Response::HTTP_NOT_FOUND
            );
        }

        $data = $request->validated();

        if (isset($data['lead_status'])) {
            $newStatus = LeadStatus::from($data['lead_status']);
            $lead->lead_status = $newStatus;

            if ($newStatus === LeadStatus::Contacted && $lead->contacted_at === null) {
                $lead->contacted_at = now();
            }

            if (! $lead->is_read) {
                $lead->is_read = true;
                $lead->read_at = now();
            }
        }

        if (isset($data['is_read'])) {
            $lead->is_read = (bool) $data['is_read'];
            if ($lead->is_read && $lead->read_at === null) {
                $lead->read_at = now();
            }
        }

        if (array_key_exists('merchant_notes', $data)) {
            $notes = $data['merchant_notes'];
            $lead->merchant_notes = $notes !== null ? trim((string) $notes) : null;
        }

        if (array_key_exists('assigned_user_public_id', $data)) {
            $assigneeUlid = $data['assigned_user_public_id'];
            if ($assigneeUlid === null || trim((string) $assigneeUlid) === '') {
                $lead->assigned_user_id = null;
            } else {
                $assignee = User::query()->where('public_id', $assigneeUlid)->first();
                if ($assignee === null) {
                    return ApiResponse::error(
                        'USER_NOT_FOUND',
                        'The designated staff member was not found.',
                        Response::HTTP_UNPROCESSABLE_ENTITY
                    );
                }

                $isAuthorizedStaff = $business->isOwnedBy($assignee)
                    || $business->activeMemberFor($assignee) !== null;

                if (! $isAuthorizedStaff) {
                    return ApiResponse::error(
                        'INVALID_STAFF_MEMBER',
                        'The assigned user is not an active team member of this business.',
                        Response::HTTP_UNPROCESSABLE_ENTITY
                    );
                }

                $lead->assigned_user_id = $assignee->id;
            }
        }

        $lead->save();
        $lead->load(['enquiry.category', 'enquiry.city', 'enquiry.locality', 'assignedUser']);

        return ApiResponse::success(
            (new OwnerLeadResource($lead))->resolve($request),
            ['message' => 'Lead updated successfully.']
        );
    }
}
