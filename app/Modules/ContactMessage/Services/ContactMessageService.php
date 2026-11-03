<?php

namespace App\Modules\ContactMessage\Services;

use App\Jobs\DeliverContactReply;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use Illuminate\Support\Facades\DB;

use App\Modules\ContactMessage\Resources\ContactMessageCollection;
use App\Modules\ContactMessage\Repositories\ContactMessagesRepository;
use App\Modules\ContactMessage\Requests\ListAllContactMessagesRequest;

class ContactMessageService
{
    public function __construct(private ContactMessagesRepository $contactMessagesRepository)
    {
    }
  
    public function createContactMessage( $request)
    {

        $ContactMessage = $this->constructContactMessageModel($request);

        return $this->contactMessagesRepository->create($ContactMessage);
    }
    public function constructContactMessageModel($request)
    {
        $contactMessageModel = [
         
            'name'    => $request->name,
            'email'   => $request->email,
            'message' => $request->message,
            'user_id' => $request->user_id,
        ];
    
        return $contactMessageModel;
    }
    public function updateContactMessage($id, $request)
    {
       
        $ContactMessage = $this->constructContactMessageModel($request); 
      
        return $this->ContactMessagesRepository->update($id, $ContactMessage);
    }

    public function deleteContactMessage($id)
    {
        return $this->contactMessagesRepository->delete($id);
    }

    public function listAllContactMessages(array $queryParameters)
    {
      
        $listAllContactMessages= (new ListAllContactMessagesRequest)->constructQueryCriteria($queryParameters);
        $contactMessages= $this->contactMessagesRepository->findAllBy($listAllContactMessages );

        return [
            'data' => new ContactMessageCollection($contactMessages['data']),
            'count' => $contactMessages['count']
        ];
    }

    public function getContactMessageById($id)
    {
        return $this->ContactMessagesRepository->find($id);
    }

    /** The pharmacy answers; the customer gets it as an app notification (push + notification history) after the response. */
    public function replyAsPharmacy(ContactMessage $message, int $adminId, string $body): ContactMessageReply
    {
        $reply = DB::transaction(function () use ($message, $adminId, $body) {
            $reply = $message->replies()->create(['admin_id' => $adminId, 'body' => $body]);
            $message->update(['status' => ContactMessage::STATUS_REPLIED, 'last_activity_at' => now()]);

            return $reply;
        });

        DeliverContactReply::dispatchAfterResponse($reply->id);

        return $reply;
    }

    /** Send a pharmacy reply again (it failed, or its delivery was lost). */
    public function resendReply(ContactMessageReply $reply): void
    {
        $reply->update(['sent_at' => null, 'push_devices' => null, 'delivery_error' => null]);

        DeliverContactReply::dispatchAfterResponse($reply->id);
    }

    /** The customer answers back from the app: the conversation waits for the pharmacy again. */
    public function replyAsCustomer(ContactMessage $message, string $body): ContactMessageReply
    {
        return DB::transaction(function () use ($message, $body) {
            $reply = $message->replies()->create(['from_customer' => true, 'body' => $body]);
            $message->update(['status' => ContactMessage::STATUS_OPEN, 'last_activity_at' => now()]);

            return $reply;
        });
    }
}
