<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Display a listing of the categories.
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        $categories = Category::with('images')->get();

        return response()->json($categories);
    }

    /**
     * Store a newly created category.
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate($this->categoryRules($request, false));

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);
        $validated['image'] = $this->resolveImageValue($request);

        $category = Category::create($validated);

        return response()->json($category->load('images'), Response::HTTP_CREATED);
    }

    /**
     * Display the specified category.
     */
    public function show(Category $category): \Illuminate\Http\JsonResponse
    {
        return response()->json($category->load('images'));
    }

    /**
     * Update the specified category.
     */
    public function update(Request $request, Category $category): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate($this->categoryRules($request, true, $category->id));

        if (! empty($validated['name']) && empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $validated['image'] = $this->resolveImageValue($request, $category);

        $category->update($validated);

        return response()->json($category->load('images'));
    }

    /**
     * Remove the specified category.
     */
    public function destroy(Category $category): \Illuminate\Http\JsonResponse
    {
        foreach ($category->images()->get() as $image) {
            Storage::disk($image->disk ?: 'public')->delete($image->path);
        }

        if (! empty($category->image)) {
            Storage::disk('public')->delete($category->image);
        }

        $category->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Build validation rules for storing or updating a category.
     */
    protected function categoryRules(Request $request, bool $updating, ?int $categoryId = null): array
    {
        $maxSize = config('category.images.max_size');
        $mimes = implode(',', config('category.images.mimes'));

        $imageRule = $request->hasFile('image')
            ? ['nullable', 'image', 'mimes:'.$mimes, 'max:'.$maxSize]
            : ['nullable', 'string', 'max:255'];

        $uniqueSlug = $categoryId
            ? 'unique:categories,slug,'.$categoryId
            : 'unique:categories,slug';

        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', $uniqueSlug],
            'image' => $imageRule,
            'description' => ['nullable', 'string'],
        ];
    }

    /**
     * Resolve the image value: upload a file, keep a string path, or null.
     */
    protected function resolveImageValue(Request $request, ?Category $category = null): ?string
    {
        $oldPath = $category?->image;

        if ($request->hasFile('image')) {
            $directory = trim(config('category.images.directory'), '/');
            $path = $request->file('image')->store($directory, config('category.images.disk'));

            if (! empty($oldPath)) {
                Storage::disk(config('category.images.disk'))->delete($oldPath);
            }

            return $path;
        }

        $value = $request->input('image');

        if ($value === null && ! empty($oldPath)) {
            Storage::disk(config('category.images.disk'))->delete($oldPath);
        }

        return $value;
    }
}
