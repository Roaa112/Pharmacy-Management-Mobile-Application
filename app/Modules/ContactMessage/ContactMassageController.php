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
   
    $contactMessages = $this->contactMessageService->listAllContactMessages($request->all());

    
    return view('dashboard.ContactMessages.index', [
        'ContactMessages' => $contactMessages['data'], 
    ]);
}
public function destroy($id)
    {
        $this->contactMessageService->deleteContactMessage($id);
        return redirect()->back()->with('success', 'ContactMessage deleted successfully!');
    }
   
}
