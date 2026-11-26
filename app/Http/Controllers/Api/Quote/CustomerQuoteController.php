<?php

namespace App\Http\Controllers\Api\Quote;

use App\Http\Controllers\Controller;
use App\Models\{QuoteRequest, QuoteRequestItem, User};
use App\Support\QuoteRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer's side: one draft to fill and send, and the sent requests. */
class CustomerQuoteController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        QuoteRequests::customer($request);
        return response()->json(QuoteRequests::options());
    }

    public function index(Request $request): JsonResponse
    {
        $customer = QuoteRequests::customer($request);
        $draft = QuoteRequests::draft($customer);
        $quotes = QuoteRequest::with('items')->where('customer_id', $customer->id)->where('status', '!=', 'draft')
            ->orderByDesc('submitted_at')->orderByDesc('id')->limit(200)->get();
        return response()->json([
            'draft' => $draft ? QuoteRequests::present($draft) : null,
            'quotes' => $quotes->map(fn (QuoteRequest $quote) => QuoteRequests::present($quote))->values(),
        ]);
    }

    public function show(Request $request, string $quote): JsonResponse
    {
        return response()->json(['quote' => QuoteRequests::present($this->own(QuoteRequests::customer($request), $quote))]);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $customer = QuoteRequests::customer($request);
        $data = $request->validate(['file' => 'required|file|max:'.QuoteRequests::MAX_FILE_KB] + QuoteRequests::itemRules());
        $item = QuoteRequests::addItem($customer, $request->file('file'), $data);
        return response()->json(['draft' => QuoteRequests::present($item->quote()->firstOrFail()), 'item_id' => $item->id], 201);
    }

    public function storeAssembly(Request $request): JsonResponse
    {
        $customer = QuoteRequests::customer($request);
        $request->validate(['key' => 'required|uuid', 'parts' => 'required|string|max:6000000',
            'files' => 'required|array|min:1|max:'.QuoteRequests::MAX_ITEMS, 'files.*' => 'required|file|max:'.QuoteRequests::MAX_FILE_KB]);
        $files = $request->file('files');
        $parts = json_decode($request->input('parts'), true);
        $rules = ['parts' => 'required|array|size:'.count($files),
            'parts.*.fields' => 'required|array', 'parts.*.geometry' => 'required|array',
            'parts.*.geometry.view' => 'required|array', 'parts.*.geometry.view.part.outline' => 'required|array',
            'parts.*.geometry.view.part.holes' => 'present|array',
            'parts.*.geometry.view.thickness' => 'required|numeric|min:0.1|max:300',
            'parts.*.geometry.placement.type' => 'required|in:free',
            'parts.*.geometry.placement.matrix' => 'required|array|size:16',
            'parts.*.geometry.placement.matrix.*' => 'required|numeric',
            'parts.*.geometry.mode' => 'required|in:free'];
        validator(['parts' => $parts], $rules)->validate();
        abort_unless(array_keys($files) === range(0, count($files) - 1) && array_keys($files) === array_keys($parts), 422);
        foreach ($files as $i => $file) {
            abort_unless(strtolower($file->getClientOriginalExtension()) === 'dxf', 422);
            $parts[$i]['fields'] = validator($parts[$i]['fields'], QuoteRequests::itemRules())->validate();
        }
        $draft = QuoteRequests::addAssembly($customer, $request->input('key'), $parts, $files);
        return response()->json(['draft' => QuoteRequests::present($draft)], 201);
    }

    public function updateItem(Request $request, string $item): JsonResponse
    {
        $customer = QuoteRequests::customer($request);
        $data = $request->validate(QuoteRequests::itemRules());
        $item = QuoteRequests::updateItem($customer, QuoteRequestItem::findOrFail($item), $data);
        return response()->json(['draft' => QuoteRequests::present($item->quote()->firstOrFail())]);
    }

    public function destroyItem(Request $request, string $item): JsonResponse
    {
        $customer = QuoteRequests::customer($request);
        QuoteRequests::removeItem($customer, QuoteRequestItem::findOrFail($item));
        $draft = QuoteRequests::draft($customer);
        return response()->json(['draft' => $draft ? QuoteRequests::present($draft) : null]);
    }

    public function submit(Request $request): JsonResponse
    {
        $customer = QuoteRequests::customer($request);
        $quote = QuoteRequests::submit($customer, $request->validate(QuoteRequests::submitRules()));
        return response()->json(['quote' => QuoteRequests::present($quote)], 201);
    }

    public function cancel(Request $request, string $quote): JsonResponse
    {
        $customer = QuoteRequests::customer($request);
        return response()->json(['quote' => QuoteRequests::present(QuoteRequests::cancel($customer, $this->own($customer, $quote)))]);
    }

    public function decide(Request $request, string $quote): JsonResponse
    {
        $customer = QuoteRequests::customer($request);
        $data = $request->validate(['decision' => 'required|in:accept,decline']);
        $quote = QuoteRequests::decide($customer, $this->own($customer, $quote), $data['decision'] === 'accept');
        return response()->json(['quote' => QuoteRequests::present($quote)]);
    }

    public function file(Request $request, string $quote, string $item)
    {
        $quote = $this->own(QuoteRequests::customer($request), $quote);
        return QuoteFileResponse::make($quote, $item, $request->boolean('download'), $request->boolean('geometry'));
    }

    private function own(User $customer, string $id): QuoteRequest
    {
        abort_unless(ctype_digit($id), 404);
        return QuoteRequest::where('customer_id', $customer->id)->findOrFail((int) $id);
    }
}
