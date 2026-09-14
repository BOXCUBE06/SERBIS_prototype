<?php

namespace App\Http\Controllers;

use App\Models\InfoMaterial;
use App\Traits\ResolvesUploadDisks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InfoMaterialController extends Controller
{
    use ResolvesUploadDisks;

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
            // No doc/docx/zip. Unlike every other upload in this system these
            // land on the PUBLIC disk and are handed out by URL from the
            // agency's own origin, so whatever is accepted here is something
            // the MDRRMO is publishing: a .doc carries macros, and a .zip is an
            // opaque container that turns a disaster-advisory library into a
            // general file host. Both were accepted only because this endpoint
            // was written before the disk was public. PDFs and images cover
            // what the library actually publishes — an advisory, an infographic,
            // an evacuation map.
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
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

    /**
     * Marks a material as checked by MDRRMO, or takes that mark back.
     *
     * One endpoint for both directions rather than separate verify/unverify
     * routes: the panel holds a toggle, and a toggle that can only be switched
     * on is a mark nobody can correct after a mistaken click.
     */
    public function verify(Request $request, $id)
    {
        $material = InfoMaterial::find($id);

        if (! $material) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $validated = $request->validate([
            'verified' => 'required|boolean',
        ]);

        $material->verified = $validated['verified'];
        $material->save();

        return response()->json($material);
    }

    public function destroy($id)
    {
        $material = InfoMaterial::find($id);

        if (! $material) {
            return response()->json(['message' => 'File not found'], 404);
        }

        Storage::disk(self::publicDisk())->delete(self::relativePath($material->file_path));

        // Delete the DB record
        $material->delete();

        return response()->json(['message' => 'File deleted successfully']);
    }

    // Rows created before the path shape changed are stored as "storage/<path>",
    // which is a URL fragment rather than a disk path. Strip it so both shapes
    // address the same file.
    private static function relativePath(?string $filePath): string
    {
        return preg_replace('#^storage/#', '', (string) $filePath);
    }
}
