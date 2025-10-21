<?php

namespace App\Modules\Category;

use App\Models\Category;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Modules\Category\Services\CategoryService;
use App\Modules\Category\Requests\SubCategoryRequest;
use App\Modules\Category\Requests\StoreCategoryRequest;
use App\Modules\Category\Requests\UpdateCategoryRequest;

class CategoryController extends Controller
{
    public function __construct(private CategoryService $categoryService)
    {
    }

    public function index(Request $request)
    {
        $categories = $this->categoryService->listAllCategories($request->all());
    
        return view('dashboard.Categories.index', [
            'categories' => $categories['data'], // contains parent categories with subcategories loaded
        ]);
    }
    

    public function store(StoreCategoryRequest $request)
    {
     $data = $request->validated();

    if ($request->hasFile('image')) {
    $filename = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
    $request->file('image')->move(public_path('category'), $filename);
    $data['image'] = 'category/' . $filename;
}

        $this->categoryService->createCategory( $data );
        return redirect()->back()->with('success', 'Category created successfully!');
    }
    public function storeSubcategory(StoreCategoryRequest $request, Category $category)
{
     $data = $request->validated();

    if ($request->hasFile('image')) {
    $filename = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
    $request->file('image')->move(public_path('subcategory'), $filename);
    $data['image'] = 'subcategory/' . $filename;
}
    $this->categoryService->createSubcategory($data, $category);
    return redirect()->back()->with('success', 'Subcategory added successfully.');
}

    public function update(UpdateCategoryRequest $request, $id)
    {
        $category = Category::findOrFail($id);
      $data = $request->validated();
    
        // Check if a new image is uploaded
       if ($request->hasFile('image')) {
    $filename = uniqid() . '.' . $request->file('image')->getClientOriginalExtension();
    $request->file('image')->move(public_path('category'), $filename);
    $data['image'] = 'category/' . $filename;
}else {
            // Retain the old image if no new image is uploaded
            $data['image'] = $category->image;
        }
        $this->categoryService->updateCategory($id, $data);
        return redirect()->back()->with('success', 'Category updated successfully!');
    }

    public function destroy($id)
    {
        $this->categoryService->deleteCategory($id);
        return redirect()->back()->with('success', 'Category deleted successfully!');
    }
}
