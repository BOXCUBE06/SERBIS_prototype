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
            // Ask the disk for the URL instead of building one with asset().
            // On the local disk this resolves to the same /storage/... path as
            // before; on object storage it is the bucket's URL, which asset()
            // could never produce.
            $item->full_url = Storage::disk(self::publicDisk())->url(self::relativePath($item->file_path));
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

        // Public disk (storage/app/public locally) so the /storage symlink can
        // serve it. The default 'local' disk roots at storage/app/private in
        // Laravel 11+, which is not web-accessible.
        $path = $file->store('info_materials', self::publicDisk());

        $material = InfoMaterial::create([
            'uploader_id' => $request->user()->admin_id,
            'title' => $request->title,
            // Disk-relative, with no 'storage/' prefix: that prefix is a fact
            // about the local symlink, not about the file, and it is wrong the
            // moment this disk becomes a bucket. Rows written before this keep
            // the prefix and are handled by relativePath().
            'file_path' => $path,
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

    Storage::disk(self::publicDisk())->delete(self::relativePath($material->file_path));

    // Delete the DB record
    $material->delete();

    return response()->json(['message' => 'File deleted successfully']);
}

    private static function publicDisk(): string
    {
        return config('filesystems.uploads.public');
    }

    // Rows created before the path shape changed are stored as "storage/<path>",
    // which is a URL fragment rather than a disk path. Strip it so both shapes
    // address the same file.
    private static function relativePath(?string $filePath): string
    {
        return preg_replace('#^storage/#', '', (string) $filePath);
    }
}