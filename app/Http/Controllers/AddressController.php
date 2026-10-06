<?php

namespace App\Http\Controllers;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    /**
     * List all addresses for the authenticated user.
     */
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $addresses = $request->user()->addresses()
            ->orderByDesc('is_default')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json($addresses);
    }

    /**
     * Store a new address for the authenticated user.
     */
    public function store(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate($this->addressRules());

        $validated['user_id'] = $request->user()->id;

        $address = DB::transaction(function () use ($request, $validated) {
            if (! empty($validated['is_default'])) {
                $request->user()->addresses()->update(['is_default' => false]);
            }

            return Address::create($validated);
        });

        return response()->json($address, Response::HTTP_CREATED);
    }

    /**
     * Display the specified address.
     */
    public function show(Request $request, Address $address): \Illuminate\Http\JsonResponse
    {
        $this->ensureOwnedByUser($request, $address);

        return response()->json($address);
    }

    /**
     * Update the specified address.
     */
    public function update(Request $request, Address $address): \Illuminate\Http\JsonResponse
    {
        $this->ensureOwnedByUser($request, $address);

        $validated = $request->validate($this->addressRules(true));

        DB::transaction(function () use ($request, $address, $validated) {
            if (! empty($validated['is_default']) && ! $address->is_default) {
                $request->user()->addresses()->update(['is_default' => false]);
            }

            $address->update($validated);
        });

        return response()->json($address);
    }

    /**
     * Remove the specified address.
     */
    public function destroy(Request $request, Address $address): \Illuminate\Http\JsonResponse
    {
        $this->ensureOwnedByUser($request, $address);

        $address->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Mark the given address as the user's default address.
     */
    public function setDefault(Request $request, Address $address): \Illuminate\Http\JsonResponse
    {
        $this->ensureOwnedByUser($request, $address);

        DB::transaction(function () use ($request, $address) {
            $request->user()->addresses()->update(['is_default' => false]);

            $address->update(['is_default' => true]);
        });

        return response()->json($address);
    }

    /**
     * Validation rules for storing or updating an address.
     */
    protected function addressRules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'type' => [$required, 'in:home,office'],
            'full_name' => [$required, 'string', 'max:150'],
            'phone' => [$required, 'string', 'max:20'],
            'address_line1' => [$required, 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => [$required, 'string', 'max:150'],
            'state' => [$required, 'string', 'max:150'],
            'pincode' => [$required, 'string', 'max:20'],
            'country' => [$required, 'string', 'max:150'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Abort with 404 if the address does not belong to the authenticated user.
     */
    protected function ensureOwnedByUser(Request $request, Address $address): void
    {
        abort_if($address->user_id !== $request->user()->id, Response::HTTP_NOT_FOUND, 'Address not found.');
    }
}
