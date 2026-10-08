<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AlertPopup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 07-Oct-2026: GET /api/alert-popups — my unseen Popup Alerts; returned once, then marked seen. */
class AlertPopupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = AlertPopup::where('user_id', $request->user()->id)->whereNull('seen_at')
            ->orderBy('id')->limit(20)->get(['id', 'kind', 'title', 'body', 'created_at']);
        if ($rows->isNotEmpty()) {
            AlertPopup::whereIn('id', $rows->pluck('id'))->update(['seen_at' => now()]);
        }

        return response()->json(['data' => $rows]);
    }
}
