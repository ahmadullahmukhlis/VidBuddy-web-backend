<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class VideoController extends Controller
{
    /**
     * SEARCH: Search YouTube for keywords and return a list of videos.
     */
    public function search(Request $request)
    {
        $query = $request->input('q', 'trending'); // Default to trending if empty
        $count = $request->input('count', 10); // Number of results
        
        // Command: ytsearchN:query returns N search results as JSON
        $command = "yt-dlp \"ytsearch{$count}:{$query}\" --dump-json --flat-playlist";
        $result = Process::run($command);

        if ($result->failed()) {
            return response()->json(['error' => 'Search failed'], 500);
        }

        $lines = explode("\n", trim($result->output()));
        $videos = array_map(fn($line) => json_decode($line), $lines);

        return response()->json($this->formatVideoList($videos));
    }

    /**
     * DOWNLOAD: Get the direct MP4 link and all quality options for a URL.
     */
    public function extractInfo(Request $request)
    {
        $url = $request->input('url');
        
        // Command: -j dumps the full video metadata including all download formats
        $command = "yt-dlp -j \"{$url}\"";
        $result = Process::run($command);

        if ($result->failed()) {
            return response()->json(['error' => 'Could not extract video info'], 400);
        }

        $data = json_decode($result->output());

        return response()->json([
            'id' => $data->id,
            'title' => $data->title,
            'thumbnail' => $data->thumbnail,
            'duration' => $data->duration_string ?? '0:00',
            'uploader' => $data->uploader ?? 'Unknown',
            'formats' => collect($data->formats)
                ->where('vcodec', '!=', 'none') // Filter out audio-only formats
                ->map(fn($f) => [
                    'quality' => $f->format_note ?? $f->resolution,
                    'ext' => $f->ext,
                    'url' => $f->url, // Direct download link
                    'size' => size_format($f->filesize ?? 0),
                ])->values()
        ]);
    }

    private function formatVideoList($videos) {
        return collect($videos)->map(fn($v) => [
            'id' => $v->id,
            'title' => $v->title,
            'thumbnail' => "https://i.ytimg.com{$v->id}/hqdefault.jpg",
            'url' => "https://www.youtube.com{$v->id}",
            'views' => number_format($v->view_count ?? 0),
        ]);
    }
}
