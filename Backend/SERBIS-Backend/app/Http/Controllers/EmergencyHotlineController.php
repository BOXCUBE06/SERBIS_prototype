<?php

namespace App\Http\Controllers;

use App\Models\EmergencyHotline;
use Illuminate\Http\Request;

class EmergencyHotlineController extends Controller
{
    /** Public: the app reads this before login and caches it for offline use. */
    public function index()
    {
        return response()->json(EmergencyHotline::orderBy('sort_order')->orderBy('hotline_id')->get());
    }

    public function store(Request $request)
    {
        $hotline = EmergencyHotline::create($this->validated($request));

        return response()->json($hotline, 201);
    }

    public function update(Request $request, $id)
    {
        $hotline = EmergencyHotline::findOrFail($id);
        $hotline->update($this->validated($request));

        return response()->json($hotline);
    }

    public function destroy($id)
    {
        EmergencyHotline::findOrFail($id)->delete();

        return response()->json(['message' => 'Hotline deleted successfully']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => 'required|string|max:100',
            'label_fil' => 'nullable|string|max:100',
            'numbers' => 'required|array|min:1|max:6',
            // Not PhoneNumber::REGEX: that is mobile-only, and 911 and the
            // landlines are hotlines too.
            'numbers.*.number' => ['required', 'string', 'regex:/^[0-9()+\- ]{3,20}$/'],
            'numbers.*.label' => 'nullable|string|max:30',
            'sort_order' => 'sometimes|integer|min:0|max:1000',
        ]);
    }
}
