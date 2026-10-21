<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $conversations = Conversation::query()
            ->with(['product:id,title,image', 'buyer:id,name', 'seller:id,name', 'messages' => fn ($query) => $query->latest()->limit(1)])
            ->where(fn ($query) => $query->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            ->latest('updated_at')
            ->get();

        return response()->json($conversations->map(fn (Conversation $conversation) => $this->present($conversation, $user->id)));
    }

    public function start(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->status === 'Aprobado' && ! $product->sold_at, 404);
        abort_if($product->seller_id === $request->user()->id, 422, 'No puedes iniciar una conversación contigo mismo.');

        $conversation = Conversation::firstOrCreate([
            'product_id' => $product->id,
            'buyer_id' => $request->user()->id,
        ], [
            'seller_id' => $product->seller_id,
        ]);

        return response()->json($this->present($conversation->load(['product:id,title,image', 'buyer:id,name', 'seller:id,name']), $request->user()->id), 201);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($request, $conversation);
        $conversation->messages()
            ->where('sender_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json($conversation->messages()->with('sender:id,name')->oldest()->get()->map(fn ($message) => [
            'id' => $message->id,
            'from' => $message->sender_id === $request->user()->id ? 'user' : 'seller',
            'sender' => $message->sender->name,
            'text' => $message->body,
            'time' => $message->created_at->format('H:i'),
            'read_at' => $message->read_at?->toIso8601String(),
        ]));
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeParticipant($request, $conversation);
        $data = $request->validate(['text' => ['required', 'string', 'max:3000']]);

        $message = $conversation->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => trim($data['text']),
        ]);
        $conversation->touch();

        return response()->json([
            'id' => $message->id,
            'from' => 'user',
            'sender' => $request->user()->name,
            'text' => $message->body,
            'time' => $message->created_at->format('H:i'),
        ], 201);
    }

    private function authorizeParticipant(Request $request, Conversation $conversation): void
    {
        abort_unless(in_array($request->user()->id, [$conversation->buyer_id, $conversation->seller_id], true), 403);
    }

    private function present(Conversation $conversation, int $userId): array
    {
        $lastMessage = $conversation->messages->first();
        return [
            'id' => $conversation->id,
            'product_id' => $conversation->product_id,
            'title' => $conversation->product?->title,
            'image' => $conversation->product?->image,
            'other_user' => $conversation->buyer_id === $userId
                ? $conversation->seller?->name
                : $conversation->buyer?->name,
            'last_message' => $lastMessage?->body,
            'unread' => $conversation->messages()
                ->whereNull('read_at')
                ->where('sender_id', '!=', $userId)
                ->count(),
        ];
    }
}
