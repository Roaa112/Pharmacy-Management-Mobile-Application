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
        Log::info('Creating contact message with data:', $data);
        $data = json_decode(json_encode($data));
        // Call the service to create the message
        $this->contactMessageService->createContactMessage($data);
    
        return response()->json([
            'success' => true,
            'message' => 'Contact message created successfully.'
        ], 201);
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
