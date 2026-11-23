<?php

namespace App\Http\Controllers\Api\Quote;

use App\Http\Controllers\Controller;
use App\Models\{QuoteRequest, User};
use App\Support\QuoteRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The company's side: sent requests, answered with a price or turned down. */
class QuoteInboxController extends Controller
{
    private const FILTERS = ['all', 'submitted', 'quoted', 'accepted', 'declined', 'rejected', 'cancelled'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(self::FILTERS)],
            'q' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        $sent = fn () => QuoteRequest::where('status', '!=', 'draft');
        $search = trim((string) ($data['q'] ?? ''));
        $page = $sent()->with('items')
            ->when(($data['status'] ?? 'all') !== 'all', fn ($query) => $query->where('status', $data['status']))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $query->where(fn ($q) => $q->where('number', 'like', $like)->orWhere('title', 'like', $like)
                    ->orWhereIn('customer_id', User::withoutGlobalScopes()->where(fn ($u) => $u->where('name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)->orWhere('email', 'like', $like))->select('id')));
            })
            ->orderByDesc('submitted_at')->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (QuoteRequest $quote) => QuoteRequests::present($quote, true))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => $sent()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n),
        ]);
    }

    public function show(string $quote): JsonResponse
    {
        return response()->json(['quote' => QuoteRequests::present($this->sent($quote), true)]);
    }

    public function answer(Request $request, string $quote): JsonResponse
    {
        $data = $request->validate(QuoteRequests::answerRules());
        return response()->json(['quote' => QuoteRequests::present(QuoteRequests::answer($request->user(), $this->sent($quote), $data), true)]);
    }

    public function reject(Request $request, string $quote): JsonResponse
    {
        $data = $request->validate(['response_note' => 'required|string|max:5000']);
        return response()->json(['quote' => QuoteRequests::present(QuoteRequests::reject($request->user(), $this->sent($quote), $data['response_note']), true)]);
    }

    public function file(Request $request, string $quote, string $item)
    {
        return QuoteFileResponse::make($this->sent($quote), $item, $request->boolean('download'));
    }

    // A draft is the customer's own until it is sent.
    private function sent(string $id): QuoteRequest
    {
        abort_unless(ctype_digit($id), 404);
        return QuoteRequest::where('status', '!=', 'draft')->findOrFail((int) $id);
    }
}
