<?php

namespace App\Modules\ContactMessage\Services;

use App\Models\ContactMessage;

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

   
    
    
   
  
}
