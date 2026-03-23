<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;

class VideoController extends Controller
{
    /**
     * Get direct download links and info for any social media URL.
     */
    public function getVideoInfo(Request $request)
    {
        $url = $request->input('url');
        
        // Command to get direct media URLs and metadata in JSON format
        $command = "yt-dlp --dump-json --no-playlist \"{$url}\"";
        $result = Process::run($command);

        if ($result->failed()) {
            return response()->json(['error' => 'Unsupported URL or Video Unavailable'], 400);
        }

        $data = json_decode($result->output());

        return response()->json([
            'title' => $data->title,
            'thumbnail' => $data->thumbnail,
            'duration' => $data->duration_string,
            'download_url' => $data->url, // Direct stream link
            'formats' => collect($data->formats)->where('vcodec', '!=', 'none')->values(),
        ]);
    }

    /**
     * Get "Famous" or Trending videos like Vidmate.
     */
    public function getTrendingVideos()
    {
        // Fetches top 12 trending videos from YouTube
        $url = "https://www.youtube.com";
        $command = "yt-dlp --dump-json --flat-playlist --playlist-end 12 \"{$url}\"";
        
        $result = Process::run($command);
        $output = explode("\n", trim($result->output()));
        
        $videos = array_map(fn($line) => json_decode($line), $output);

        return response()->json($videos);
    }
}
