<?php

namespace App\Modules\HealthIssue\Services;

use App\Models\HealthIssue;

use App\Modules\HealthIssue\Resources\HealthIssueCollection;
use App\Modules\HealthIssue\Repositories\HealthIssuesRepository;
use App\Modules\HealthIssue\Requests\ListAllHealthIssuesRequest;

class HealthIssueService
{
    public function __construct(private HealthIssuesRepository $healthIssuesRepository)
    {
    }
  
    public function createHealthIssue($request)
    {
        $healthIssue = $this->constructHealthIssueModel($request);
        return $this->healthIssuesRepository->create($healthIssue);
    }

    public function updateHealthIssue($id, $request)
    {
       
        $healthIssue = $this->constructHealthIssueModel($request); 
      
        return $this->healthIssuesRepository->update($id, $healthIssue);
    }

    public function deleteHealthIssue($id)
    {
        return $this->healthIssuesRepository->delete($id);
    }

    public function listAllHealthIssues(array $queryParameters)
    {
      
        $listAllHealthIssues= (new ListAllHealthIssuesRequest)->constructQueryCriteria($queryParameters);
        $healthIssues= $this->healthIssuesRepository->findAllBy($listAllHealthIssues );

        return [
            'data' => new HealthIssueCollection($healthIssues['data']),
            'count' => $healthIssues['count']
        ];
    }

    public function getHealthIssueById($id)
    {
        return $this->healthIssuesRepository->find($id);
    }

    public function constructHealthIssueModel($request)
    {
        $healthIssueModel = [
            'name_en' => $request['name_en'],
            'name_ar' => $request['name_ar'],
              'image' => $request['image'],
        ];
        return $healthIssueModel;
    }
    
   
  
}
