<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportedDataController extends Controller
{
    /**
     * Get paginated imported customer data scoped strictly to the authenticated user.
     *
     * GET /api/imported-data
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userImportIds = $user->imports()->pluck('id');

        // Bounded scope: Only records reliably attributed to this user's imports
        $baseQuery = Customer::whereNotNull('import_id')
            ->whereIn('import_id', $userImportIds);

        $totalEligibleCount = (clone $baseQuery)->count();

        // Optional search filtering across primary identifier and profile fields
        $search = trim((string) $request->query('search', ''));
        $query = clone $baseQuery;

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('customer_id', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('country', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);
        $paginated = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->items(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'per_page' => $paginated->perPage(),
            'total' => $paginated->total(),
            'from' => $paginated->firstItem(),
            'to' => $paginated->lastItem(),
            'total_eligible_count' => $totalEligibleCount,
        ], 200);
    }

    /**
     * Safely delete all imported customer records within the authenticated user's scope.
     *
     * Requires explicit confirmation phrase 'DELETE ALL'.
     * Never truncates, never touches unrelated/pre-existing records (import_id IS NULL),
     * and preserves all import history, error logs, and queue jobs.
     *
     * DELETE /api/imported-data
     */
    public function destroy(Request $request): JsonResponse
    {
        $confirmation = $request->input('confirmation');

        if ($confirmation !== 'DELETE ALL') {
            return response()->json([
                'message' => "Explicit confirmation string 'DELETE ALL' is required to execute this operation.",
                'errors' => [
                    'confirmation' => ["The confirmation phrase must be exactly 'DELETE ALL'."],
                ],
            ], 422);
        }

        $user = $request->user();
        $userImportIds = $user->imports()->pluck('id');

        if ($userImportIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No imported records were eligible for deletion.',
                'deleted_count' => 0,
            ], 200);
        }

        $importId = $request->input('import_id');
        if ($importId !== null) {
            if (! $userImportIds->contains((int) $importId)) {
                return response()->json([
                    'message' => 'Unauthorized import scope specified.',
                ], 403);
            }
            $targetImportIds = [(int) $importId];
        } else {
            $targetImportIds = $userImportIds->all();
        }

        // Safe, bounded batch deletion to handle large volumes without table locks or transaction overflow
        $deletedCount = 0;
        $batchSize = 1000;

        do {
            $ids = Customer::whereNotNull('import_id')
                ->whereIn('import_id', $targetImportIds)
                ->limit($batchSize)
                ->pluck('id')
                ->all();

            if (empty($ids)) {
                break;
            }

            DB::transaction(function () use ($ids) {
                Customer::whereIn('id', $ids)->delete();
            });

            $deletedCount += count($ids);
        } while (count($ids) === $batchSize);

        return response()->json([
            'success' => true,
            'message' => $deletedCount > 0
                ? "Successfully deleted {$deletedCount} imported records."
                : 'No imported records were eligible for deletion.',
            'deleted_count' => $deletedCount,
        ], 200);
    }
}
