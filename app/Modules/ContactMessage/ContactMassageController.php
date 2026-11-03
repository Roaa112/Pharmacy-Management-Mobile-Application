<?php

namespace App\Modules\ContactMessage;

use App\Models\AdminAuditLog;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\ContactMessage\Services\ContactMessageService;

class ContactMassageController extends Controller
{
    public function __construct(private ContactMessageService $contactMessageService)
    {
    }

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), [ContactMessage::STATUS_OPEN, ContactMessage::STATUS_REPLIED], true) ? $request->query('status') : null;

        $contactMessages = ContactMessage::with(['user', 'replies.admin'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('last_activity_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('dashboard.ContactMessages.index', [
            'ContactMessages' => $contactMessages,
            'openCount' => ContactMessage::where('status', ContactMessage::STATUS_OPEN)->count(),
        ]);
    }

    public function reply(Request $request, ContactMessage $message)
    {
        $data = $request->validate(['body' => 'required|string|max:2000']);

        $reply = $this->contactMessageService->replyAsPharmacy($message, $request->user('admin')->id, trim($data['body']));
        AdminAuditLog::record('contact_message.replied', $message, ['reply_id' => $reply->id]);

        return redirect()->back()->with('success', 'Reply sent to '.$message->name.'.');
    }

    public function resend(ContactMessage $message, ContactMessageReply $reply)
    {
        abort_unless((int) $reply->contact_message_id === $message->id &&$reply->canResend(), 404);

        $this->contactMessageService->resendReply($reply);
        AdminAuditLog::record('contact_message.reply_resent', $message, ['reply_id' => $reply->id]);

        return redirect()->back()->with('success', 'Reply sent again.');
    }

    public function destroy($id)
    {
        $this->contactMessageService->deleteContactMessage($id);
        return redirect()->back()->with('success', 'ContactMessage deleted successfully!');
    }
}
