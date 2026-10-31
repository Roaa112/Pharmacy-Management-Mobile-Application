<?php

namespace App\Modules\ContactMessage;

use App\Models\ContactMessage;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\ContactMessage\Services\ContactMessageService;
use App\Modules\ContactMessage\Requests\StoreContactMessageRequest;
use App\Modules\ContactMessage\Requests\UpdateContactMessageRequest;

class ContactMassageController extends Controller
{
    public function __construct(private ContactMessageService $contactMessageService)
    {
    }

    public function index(Request $request)
{
   
    $contactMessages = \App\Models\ContactMessage::latest()->paginate(25);

    
    return view('dashboard.ContactMessages.index', [
        'ContactMessages' => $contactMessages, 
    ]);
}
public function destroy($id)
    {
        $this->contactMessageService->deleteContactMessage($id);
        return redirect()->back()->with('success', 'ContactMessage deleted successfully!');
    }
   
}
