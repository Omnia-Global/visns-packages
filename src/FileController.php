<?php

namespace Visnsstudio\VisnsPackages;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

class FileController extends \App\Http\Controllers\Controller
{
	public function downloadContent($id)
	{
		$file = File::find($id);

		if (!$file) {
			return response()->json(["error" => "File not found."], 404);
		}

		$content = Storage::get($file->file_path);
		$contentType = $this->getContentType($file->file_extension);

		return Response::make($content, 200, [
			"Content-Type" => $contentType,
			"Content-Disposition" =>
				'inline; filename="' . $file->file_name . '"',
		]);
	}

	private function getContentType($extension)
	{
		$types = [
			"css" => "text/css",
			"csv" => "text/csv",
			"doc" => "application/msword",
			"docx" =>
				"application/vnd.openxmlformats-officedocument.wordprocessingml.document",
			"flac" => "audio/flac",
			"gif" => "image/gif",
			"html" => "text/html",
			"jpeg" => "image/jpeg",
			"jpg" => "image/jpeg",
			"js" => "application/javascript",
			"json" => "application/json",
			"mp3" => "audio/mpeg",
			"mp4" => "video/mp4",
			"ogg" => "audio/ogg",
			"pdf" => "application/pdf",
			"png" => "image/png",
			"ppt" => "application/vnd.ms-powerpoint",
			"pptx" =>
				"application/vnd.openxmlformats-officedocument.presentationml.presentation",
			"rar" => "application/x-rar-compressed",
			"svg" => "image/svg+xml",
			"txt" => "text/plain",
			"wav" => "audio/wav",
			"xls" => "application/vnd.ms-excel",
			"xlsx" =>
				"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
			"xml" => "application/xml",
			"zip" => "application/zip",
			// ... add more types as needed ...
			"default" => "application/octet-stream",
		];

		return $types[strtolower($extension)] ?? $types["default"];
	}

	public function sort_update(Request $request)
	{
		$request->validate(["list" => "required"]);

		foreach ($request->input("list") as $index => $item) {
			File::where("id", $item["id"])->update(["sort_order" => $index]);
		}

		return response()->json(["error" => ""]);
	}

	// Function to convert folder name from plural to singular and capitalize the first letter
	private function singularizeAndCapitalize($folder)
	{
		// Convert folder name from plural to singular
		// This is a basic implementation and might need to be expanded based on specific naming conventions
		if (substr($folder, -1) == "s") {
			$folder = substr($folder, 0, -1);
		}

		// Capitalize the first letter
		return ucfirst($folder);
	}

	public function download($id, $folder)
	{
		$file = $this->getFile($id, $folder);
		$filename = $this->formatFileName($file);
		$filepath = $this->determineFilePath($file, $folder);

		if (config("filesystems.default") == "s3") {
			return $this->downloadFromS3($filepath, $filename);
		}

		return $this->downloadFromFilesystem($filepath, $filename);
	}

	protected function getFile($id, $folder)
	{
		$type = "App\\Models\\" . $this->singularizeAndCapitalize($folder);

		return File::where("fileable_id", $id)
			->where("fileable_type", $type)
			->firstOrFail();
	}

	protected function formatFileName($file)
	{
		$filename = str_replace([" ", "-"], "_", $file->file_name);
		if (strpos($file->file_name, "." . $file->file_extension) === false) {
			$filename .= "." . $file->file_extension;
		}
		return str_replace(",", "", $filename);
	}

	protected function determineFilePath($file, $folder)
	{
		if (strpos($file->file_path, $folder . "/") === false) {
			return $folder . "/" . $file->file_path;
		}
		return $file->file_path;
	}

	protected function downloadFromS3($filepath, $filename)
	{
		$url = Storage::temporaryUrl($filepath, now()->addMinutes(5), [
			"ResponseContentDisposition" => "attachment; filename=" . $filename,
		]);

		return redirect()->away($url);
	}

	protected function downloadFromFilesystem($filepath, $filename)
	{
		return Storage::download(
			storage_path() . "/app/" . $filepath,
			$filename
		);
	}

	public function show($id)
	{
		$file = File::find($id);

		return response()->json($file);
	}

	public function update(Request $request, $id)
	{
		$error = "";

		$file = File::find($id);
		$file->file_name = $request->input("file_name");
		$file->save();

		return response()->json([
			"error" => $error,
		]);
	}

	public function delete(Request $request)
	{
		$error = "";

		if ($request->has("file_id") && $request->filled("file_id")) {
			$file = File::find($request->input("file_id"));
			$file->delete();
		}

		return response()->json([
			"error" => $error,
		]);
	}
}
