<?php

namespace App\Http\Controllers;

use App\Models\InfoMaterial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InfoMaterialController extends Controller
{
    public function index()
    {
        $materials = InfoMaterial::orderBy('created_at', 'desc')->get();
        
        $materials->transform(function ($item) {
            $item->full_url = asset($item->file_path);
            return $item;
        });

        return response()->json($materials);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'file' => 'required|file|mimes:pdf,doc,docx,jpg,png,zip|max:10240',
        ]);

        $file = $request->file('file');

        // Store on the 'public' disk (storage/app/public) so the /storage symlink
        // can serve it. The default 'local' disk roots at storage/app/private in
        // Laravel 11+, which is not web-accessible.
        $path = $file->store('info_materials', 'public');

        $material = InfoMaterial::create([
            'uploader_id' => $request->user()->admin_id,
            'title' => $request->title,
            'file_path' => 'storage/' . $path,
            'file_type' => $file->getClientOriginalExtension(),
            'file_size' => $file->getSize(),
        ]);

        return response()->json($material, 201);
    }

    public function destroy($id)
{
    $material = InfoMaterial::find($id);

    if (!$material) {
        return response()->json(['message' => 'File not found'], 404);
    }

    // Delete the actual file from the public disk (file_path is "storage/<path>")
    Storage::disk('public')->delete(str_replace('storage/', '', $material->file_path));

    // Delete the DB record
    $material->delete();

    return response()->json(['message' => 'File deleted successfully']);
}
}