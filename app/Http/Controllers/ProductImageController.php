<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductImageController extends Controller
{
    /**
     * List every image belonging to the given product.
     */
    public function index(Product $product): \Illuminate\Http\JsonResponse
    {
        return response()->json($product->images()->get());
    }

    /**
     * Upload one or more images for the given product.
     */
    public function store(Request $request, Product $product): \Illuminate\Http\JsonResponse
    {
        $maxSize = config('product.images.max_size');
        $maxCount = config('product.images.max_count');
        $mimes = implode(',', config('product.images.mimes'));

        $request->validate([
            'images' => ['nullable', 'array', 'max:'.$maxCount],
            'images.*' => ['required', 'image', 'mimes:'.$mimes, 'max:'.$maxSize],
            'image' => ['nullable', 'image', 'mimes:'.$mimes, 'max:'.$maxSize],
        ]);

        $files = $this->collectUploadedFiles($request);

        if (empty($files)) {
            throw ValidationException::withMessages([
                'images' => ['Please upload at least one image.'],
            ]);
        }

        $existing = $product->images()->count();

        if ($existing + count($files) > $maxCount) {
            throw ValidationException::withMessages([
                'images' => ["A product may not have more than {$maxCount} images."],
            ]);
        }

        $disk = config('product.images.disk');
        $directory = trim(config('product.images.directory'), '/').'/'.$product->id;

        $images = DB::transaction(function () use ($product, $files, $disk, $directory) {
            $created = [];
            $hasPrimary = $product->images()->where('is_primary', true)->exists();
            $position = (int) $product->images()->max('sort_order');

            foreach ($files as $file) {
                $path = $file->store($directory, $disk);

                $isPrimary = ! $hasPrimary && empty($created);

                $image = $product->images()->create([
                    'path' => $path,
                    'disk' => $disk,
                    'is_primary' => $isPrimary,
                    'sort_order' => ++$position,
                ]);

                if ($isPrimary) {
                    $product->update(['image' => $path]);
                }

                $created[] = $image;
            }

            return $created;
        });

        return response()->json($images, Response::HTTP_CREATED);
    }

    /**
     * Mark the given image as the product's primary image.
     */
    public function setPrimary(Product $product, ProductImage $image): \Illuminate\Http\JsonResponse
    {
        $this->ensureOwnedByProduct($product, $image);

        DB::transaction(function () use ($product, $image) {
            $product->images()->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);

            $product->update(['image' => $image->path]);
        });

        return response()->json($image->fresh());
    }

    /**
     * Delete the given image from storage and the database.
     */
    public function destroy(Product $product, ProductImage $image): \Illuminate\Http\JsonResponse
    {
        $this->ensureOwnedByProduct($product, $image);

        DB::transaction(function () use ($product, $image) {
            Storage::disk($image->disk ?: config('product.images.disk'))->delete($image->path);

            $wasPrimary = $image->is_primary;

            $image->delete();

            if (! $wasPrimary) {
                return;
            }

            // Promote the next available image to primary.
            $next = $product->images()->first();

            if ($next) {
                $next->update(['is_primary' => true]);
                $product->update(['image' => $next->path]);
            } else {
                $product->update(['image' => null]);
            }
        });

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Normalise single and multiple upload inputs into a flat array of files.
     *
     * @return array<int, \Illuminate\Http\UploadedFile>
     */
    protected function collectUploadedFiles(Request $request): array
    {
        $files = [];

        if ($request->hasFile('image')) {
            $files[] = $request->file('image');
        }

        foreach ((array) $request->file('images', []) as $file) {
            if ($file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Abort with a 404 when the image does not belong to the product.
     */
    protected function ensureOwnedByProduct(Product $product, ProductImage $image): void
    {
        abort_if($image->product_id !== $product->id, Response::HTTP_NOT_FOUND, 'Image not found for this product.');
    }
}
