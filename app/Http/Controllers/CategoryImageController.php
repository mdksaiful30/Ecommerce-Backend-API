<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CategoryImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CategoryImageController extends Controller
{
    /**
     * List every image belonging to the given category.
     */
    public function index(Category $category): \Illuminate\Http\JsonResponse
    {
        return response()->json($category->images()->get());
    }

    /**
     * Upload one or more images for the given category.
     */
    public function store(Request $request, Category $category): \Illuminate\Http\JsonResponse
    {
        $maxSize = config('category.images.max_size');
        $maxCount = config('category.images.max_count');
        $mimes = implode(',', config('category.images.mimes'));

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

        $existing = $category->images()->count();

        if ($existing + count($files) > $maxCount) {
            throw ValidationException::withMessages([
                'images' => ["A category may not have more than {$maxCount} images."],
            ]);
        }

        $disk = config('category.images.disk');
        $directory = trim(config('category.images.directory'), '/').'/'.$category->id;

        $images = DB::transaction(function () use ($category, $files, $disk, $directory) {
            $created = [];
            $hasPrimary = $category->images()->where('is_primary', true)->exists();
            $position = (int) $category->images()->max('sort_order');

            foreach ($files as $file) {
                $path = $file->store($directory, $disk);

                $isPrimary = ! $hasPrimary && empty($created);

                $image = $category->images()->create([
                    'path' => $path,
                    'disk' => $disk,
                    'is_primary' => $isPrimary,
                    'sort_order' => ++$position,
                ]);

                if ($isPrimary) {
                    $category->update(['image' => $path]);
                }

                $created[] = $image;
            }

            return $created;
        });

        return response()->json($images, Response::HTTP_CREATED);
    }

    /**
     * Mark the given image as the category's primary image.
     */
    public function setPrimary(Category $category, CategoryImage $image): \Illuminate\Http\JsonResponse
    {
        $this->ensureOwnedByCategory($category, $image);

        DB::transaction(function () use ($category, $image) {
            $category->images()->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);

            $category->update(['image' => $image->path]);
        });

        return response()->json($image->fresh());
    }

    /**
     * Delete the given image from storage and the database.
     */
    public function destroy(Category $category, CategoryImage $image): \Illuminate\Http\JsonResponse
    {
        $this->ensureOwnedByCategory($category, $image);

        DB::transaction(function () use ($category, $image) {
            Storage::disk($image->disk ?: config('category.images.disk'))->delete($image->path);

            $wasPrimary = $image->is_primary;

            $image->delete();

            if (! $wasPrimary) {
                return;
            }

            $next = $category->images()->first();

            if ($next) {
                $next->update(['is_primary' => true]);
                $category->update(['image' => $next->path]);
            } else {
                $category->update(['image' => null]);
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
     * Abort with a 404 when the image does not belong to the category.
     */
    protected function ensureOwnedByCategory(Category $category, CategoryImage $image): void
    {
        abort_if($image->category_id !== $category->id, Response::HTTP_NOT_FOUND, 'Image not found for this category.');
    }
}
