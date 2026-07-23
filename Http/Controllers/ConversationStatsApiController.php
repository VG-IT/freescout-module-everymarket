<?php

namespace Modules\Everymarket\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Everymarket\Services\ConversationSummaryService;

class ConversationStatsApiController extends Controller
{
    use ApiTokenAuth;

    /**
     * GET /everymarket/api/conversation-stats
     *
     * Query: order_number=... OR customer_email=...
     *        all=1 (optional) — return all matches; default is latest only
     *
     * Auth: header X-Everymarket-Api-Token, Authorization: Bearer <token>, or ?api_token=
     */
    public function index(Request $request, ConversationSummaryService $summaryService)
    {
        if (!$this->isAuthorized($request)) {
            return $this->unauthorizedResponse();
        }

        $orderNumber = trim((string) $request->query('order_number', ''));
        $customerEmail = trim((string) $request->query('customer_email', ''));
        $all = filter_var($request->query('all'), FILTER_VALIDATE_BOOLEAN)
            || $request->query('all') === '1';

        if ($orderNumber === '' && $customerEmail === '') {
            return response()->json([
                'status' => 'error',
                'msg'    => __('Provide order_number or customer_email'),
            ], 400);
        }

        if ($orderNumber !== '' && $customerEmail !== '') {
            return response()->json([
                'status' => 'error',
                'msg'    => __('Provide only one of order_number or customer_email'),
            ], 400);
        }

        if ($orderNumber !== '') {
            $conversations = $summaryService->findByOrderNumber($orderNumber, $all);
        } else {
            $conversations = $summaryService->findByCustomerEmail($customerEmail, $all);
        }

        if ($conversations->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'msg'    => __('No conversations found'),
            ], 404);
        }

        $summaries = $conversations->map(function ($conversation) use ($summaryService) {
            return $summaryService->summarize($conversation);
        })->values();

        if ($all) {
            return response()->json([
                'status' => 'success',
                'count'  => $summaries->count(),
                'data'   => $summaries,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $summaries->first(),
        ]);
    }
}
