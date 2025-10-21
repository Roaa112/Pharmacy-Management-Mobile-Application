<?php

namespace App\Modules\HealthIssue;

use App\Models\HealthIssue;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\HealthIssue\Services\HealthIssueService;
use App\Modules\HealthIssue\Requests\StoreHealthIssueRequest;
use App\Modules\HealthIssue\Requests\UpdateHealthIssueRequest;

class HealthIssueController extends Controller
{
    public function __construct(private HealthIssueService $healthIssueService)
    {
    }

    public function index(Request $request)
    {
        $healthIssues = $this->healthIssueService->listAllHealthIssues($request->all());
    
        return view('dashboard.HealthIssues.index', [
            'healthIssues' => $healthIssues['data'], 
        ]);
    }
    

  public function store(StoreHealthIssueRequest $request)
{
    $data = $request->validated();

   
     if ($request->hasFile('image')) {
    $filename = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
    $request->file('image')->move(public_path('healthissue'), $filename);
    $data['image'] = 'healthissue/' . $filename;
}

    $this->healthIssueService->createHealthIssue($data); // Use $data with image

    return redirect()->back()->with('success', 'HealthIssue created successfully!');
}

public function update(UpdateHealthIssueRequest $request, $id)
{
    $healthissue = HealthIssue::findOrFail($id);
    $data = $request->validated();

    // Handle image upload if exists
    if ($request->hasFile('image')) {
        $filename = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
        $request->file('image')->move(public_path('healthissue'), $filename);
        $data['image'] = 'healthissue/' . $filename;
    } else {
        // Retain old image
        $data['image'] = $healthissue->image;
    }


    $this->healthIssueService->updateHealthIssue($id, $data);

    return redirect()->back()->with('success', 'HealthIssue updated successfully!');
}


    public function destroy($id)
    {
        $this->healthIssueService->deleteHealthIssue($id);
        return redirect()->back()->with('success', 'HealthIssue deleted successfully!');
    }
}
