<?php

namespace App\Modules\ContactMessage;

use Illuminate\Http\Request;
use App\Models\ContactMessage;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Modules\ContactMessage\Services\ContactMessageService;
use App\Modules\ContactMessage\Requests\StoreContactMessageRequest;
use App\Modules\ContactMessage\Requests\ListAllContactMessagesRequest;


class ApiContactMassageController extends Controller
{
    public function __construct(private ContactMessageService $contactMessageService)
    {
    }
    public function store(StoreContactMessageRequest $request)
    {
        $user = $request->user();
    
        if (!$user) {
            Log::error('Unauthenticated user attempt');
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }
    
        // Validate input and attach user_id
        $data = $request->validated();
        $data['user_id'] = $user->id;

        // Log the data for debugging (اختياري)
        Log::info('Creating contact message', ['user_id' => $user->id]);
        $data = json_decode(json_encode($data));
        // Call the service to create the message
        $this->contactMessageService->createContactMessage($data);
    
        return response()->json([
            'success' => true,
            'message' => 'Contact message created successfully.'
        ], 201);
    }
    /** The customer's conversations, newest activity first, each with its replies. */
    public function index(Request $request)
    {
        $page = ContactMessage::where('user_id', $request->user()->id)->with('replies')
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (ContactMessage $message) => $this->present($message)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, $id)
    {
        $message = $this->ownMessage($request, $id);

        return $message
            ? response()->json(['data' => $this->present($message)])
            : response()->json(['message' => api_trans('contact.not_found')], 404);
    }

    /** The customer answers the pharmacy in an existing conversation. */
    public function reply(Request $request, $id)
    {
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $message = $this->ownMessage($request, $id);

        if (!$message) {
            return response()->json(['message' => api_trans('contact.not_found')], 404);
        }

        $this->contactMessageService->replyAsCustomer($message, trim($data['body']));

        return response()->json(['message' => api_trans('contact.reply_sent'), 'data' => $this->present($message->fresh('replies'))], 201);
    }

    private function ownMessage(Request $request, $id): ?ContactMessage
    {
        return ContactMessage::where('user_id', $request->user()->id)->with('replies')->find((int) $id);
    }

    /** Staff names are not shown to customers: a pharmacy reply is from "pharmacy". */
    private function present(ContactMessage $message): array
    {
        return [
            'id' => $message->id,
            'name' => $message->name,
            'email' => $message->email,
            'message' => $message->message,
            'status' => $message->status,
            'created_at' => $message->created_at,
            'last_activity_at' => $message->last_activity_at,
            'replies' => $message->replies->map(fn ($reply) => [
                'id' => $reply->id,
                'from' => $reply->from_customer ? 'customer' : 'pharmacy',
                'body' => $reply->body,
                'created_at' => $reply->created_at,
            ])->values(),
        ];
    }

    //لو احتجتهم
    // public function update(UpdateContactMessageRequest $request, $id)
    // {
     
    //    $this->contactMessageService->updateContactMessage($id, $request->validated());
    //     return redirect()->back()->with('success', 'ContactMessage updated successfully!');
    // }

    // public function destroy($id)
    // {
    //    $this->contactMessageService->deleteContactMessage($id);
    //     return redirect()->back()->with('success', 'ContactMessage deleted successfully!');
    // }
}
