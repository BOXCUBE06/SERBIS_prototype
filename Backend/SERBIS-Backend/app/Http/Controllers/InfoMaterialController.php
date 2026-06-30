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
        
        $path = $file->store('public/info_materials');

        $material = InfoMaterial::create([
            'uploader_id' => $request->user()->admin_id,
            'title' => $request->title,
            'file_path' => str_replace('public/', 'storage/', $path),
            'file_type' => $file->getClientOriginalExtension(),
            'file_size' => $file->getSize(),
        ]);

        return response()->json($material, 201);
    }
}