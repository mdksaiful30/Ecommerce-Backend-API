<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    /**
     * Display a listing of the products grouped by category.
     */
    public function index(): \Illuminate\Http\JsonResponse
    {
        $categories = \App\Models\Category::with(['products.images'])->get();

        return response()->json($categories);
    }

    /**
     * Store a newly created product.
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate($this->productRules($request, false));

        $validated['slug'] = $validated['slug'] ?? Str::slug($validated['name']);

        $validated['image'] = $this->resolveImageValue($request);

        $product = Product::create($validated);

        return response()->json($product->load(['category', 'images']), Response::HTTP_CREATED);
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product): \Illuminate\Http\JsonResponse
    {
        return response()->json($product->load(['category', 'images']));
    }

    /**
     * Update the specified product.
     */
    public function update(Request $request, Product $product): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate($this->productRules($request, true, $product->id));

        if (! empty($validated['name']) && empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $validated['image'] = $this->resolveImageValue($request, $product);

        $product->update($validated);

        return response()->json($product->load(['category', 'images']));
    }

    /**
     * Remove the specified product.
     */
    public function destroy(Product $product): \Illuminate\Http\JsonResponse
    {
        // Remove the stored image files before deleting the records.
        foreach ($product->images()->get() as $image) {
            Storage::disk($image->disk ?: 'public')->delete($image->path);
        }

        if (! empty($product->image)) {
            Storage::disk('public')->delete($product->image);
        }

        $product->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Build validation rules for storing or updating a product.
     * The "image" rule adapts to whether a file or a string was sent.
     */
    protected function productRules(Request $request, bool $updating, ?int $productId = null): array
    {
        $maxSize = config('product.images.max_size');
        $mimes = implode(',', config('product.images.mimes'));

        $imageRule = $request->hasFile('image')
            ? ['nullable', 'image', 'mimes:'.$mimes, 'max:'.$maxSize]
            : ['nullable', 'string', 'max:255'];

        $uniqueSlug = $productId
            ? 'unique:products,slug,'.$productId
            : 'unique:products,slug';

        $required = $updating ? 'sometimes' : 'required';

        return [
            'category_id' => [$required, 'exists:categories,id'],
            'name' => [$required, 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:150', $uniqueSlug],
            'description' => ['nullable', 'string'],
            'price' => [$required, 'numeric', 'min:0'],
            'offer_price' => ['nullable', 'numeric', 'min:0'],
            'image' => $imageRule,
            'stock' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Resolve the image value: upload a file, keep a string path, or null.
     * Also removes the previous file when a new one is uploaded or image is cleared.
     */
    protected function resolveImageValue(Request $request, ?Product $product = null): ?string
    {
        $oldPath = $product?->image;

        // A file was uploaded -> store it and remove the old one.
        if ($request->hasFile('image')) {
            $directory = trim(config('product.images.directory'), '/');
            $path = $request->file('image')->store($directory, config('product.images.disk'));

            if (! empty($oldPath)) {
                Storage::disk(config('product.images.disk'))->delete($oldPath);
            }

            return $path;
        }

        // A string path (or null) was sent explicitly.
        $value = $request->input('image');

        // Image cleared -> remove the old file.
        if ($value === null && ! empty($oldPath)) {
            Storage::disk(config('product.images.disk'))->delete($oldPath);
        }

        return $value;
    }
}
