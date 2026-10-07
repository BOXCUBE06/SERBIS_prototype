<?php

namespace App\Http\Controllers;

use App\Models\Responder;
use App\Support\PhoneNumber;
use App\Traits\ResolvesUploadDisks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ResponderController extends Controller
{
    use ResolvesUploadDisks;

    public function index()
    {
        return response()->json($this->withPhotoUrls(Responder::all()));
    }

    /** Names and positions only, for the trip log's driver picker — ambulance staff do not hold the responders section. */
    public function names()
    {
        return response()->json(Responder::orderBy('name')->get(['name', 'position']));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_no' => ['required', 'string', 'max:32', 'regex:'.PhoneNumber::REGEX],
            'position' => ['required', Rule::in(Responder::POSITIONS)],
            'status' => ['sometimes', 'required', Rule::in(Responder::STATUSES)],
        ]);

        $responder = Responder::create($validated);

        return response()->json($this->withPhotoUrl($responder), 201);
    }

    public function show($id)
    {
        $responder = Responder::find($id);

        if (! $responder) {
            return response()->json(['message' => 'Responder not found'], 404);
        }

        return response()->json($this->withPhotoUrl($responder));
    }

    public function update(Request $request, $id)
    {
        $responder = Responder::find($id);

        if (! $responder) {
            return response()->json(['message' => 'Responder not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'contact_no' => ['sometimes', 'required', 'string', 'max:32', 'regex:'.PhoneNumber::REGEX],
            'position' => ['sometimes', 'required', Rule::in(Responder::POSITIONS)],
            'status' => ['sometimes', 'required', Rule::in(Responder::STATUSES)],
        ]);

        $responder->update($validated);

        return response()->json($this->withPhotoUrl($responder));
    }

    public function destroy($id)
    {
        $responder = Responder::find($id);

        if (! $responder) {
            return response()->json(['message' => 'Responder not found'], 404);
        }

        if ($responder->status === 'deployed') {
            return response()->json([
                'message' => 'Cannot delete this responder: currently deployed on a request.',
            ], 422);
        }

        if ($responder->photo_path) {
            Storage::disk(self::publicDisk())->delete($responder->photo_path);
        }

        $responder->delete();

        return response()->json(['message' => 'Responder successfully deleted']);
    }

    /**
     * Same shape as EquipmentBorrowingController::uploadPhoto(): store first,
     * swap the column, delete the old file only after the new path is saved.
     * Public disk, not private — a responder's photo is not a government ID
     * scan, and the resident-facing API links it directly (photo_url).
     */
    public function uploadPhoto(Request $request, $id)
    {
        $responder = Responder::find($id);

        if (! $responder) {
            return response()->json(['message' => 'Responder not found'], 404);
        }

        $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png|max:4096',
        ]);

        $file = $request->file('photo');

        $path = $file->storeAs(
            'responder-photos/'.$responder->getKey(),
            (string) Str::uuid().'.'.$file->extension(),
            self::publicDisk()
        );

        $previous = $responder->photo_path;

        // Not in $fillable — see Responder.php. Assigned directly, same as
        // EquipmentBorrowing's handover-photo columns.
        $responder->photo_path = $path;
        $responder->save();

        if ($previous && $previous !== $path) {
            Storage::disk(self::publicDisk())->delete($previous);
        }

        return response()->json($this->withPhotoUrl($responder));
    }

    public function deletePhoto($id)
    {
        $responder = Responder::find($id);

        if (! $responder) {
            return response()->json(['message' => 'Responder not found'], 404);
        }

        $previous = $responder->photo_path;
        $responder->photo_path = null;
        $responder->save();

        if ($previous) {
            Storage::disk(self::publicDisk())->delete($previous);
        }

        return response()->json($this->withPhotoUrl($responder));
    }

    private function withPhotoUrl(Responder $responder): Responder
    {
        $responder->photo_url = $responder->photo_path
            ? Storage::disk(self::publicDisk())->url($responder->photo_path)
            : null;

        return $responder;
    }

    private function withPhotoUrls(iterable $responders): iterable
    {
        foreach ($responders as $responder) {
            $this->withPhotoUrl($responder);
        }

        return $responders;
    }
}
