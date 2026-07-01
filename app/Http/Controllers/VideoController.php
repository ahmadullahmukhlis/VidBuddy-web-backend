<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

class VideoController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | SEARCH VIDEOS
    |--------------------------------------------------------------------------
    */
    public function search(Request $request)
    {
        $query = trim((string) $request->input('q', 'trending'));
        $count = (int) $request->input('count', 12);
        $count = max(1, min($count, 25));

        $searchTerm = "ytsearch{$count}:{$query}";
        $command = $this->ytDlpBinary() . " --dump-json --flat-playlist " . escapeshellarg($searchTerm);

        $result = Process::timeout(120)->run($command);

        if ($result->failed()) {
            return response()->json([
                'error' => 'Search failed',
                'details' => $result->errorOutput()
            ], 500);
        }

        $lines = array_filter(explode("\n", trim($result->output())));
        $videos = array_map(fn($line) => json_decode($line), $lines);

        return response()->json($this->formatVideoList($videos));
    }

    /*
    |--------------------------------------------------------------------------
    | EXTRACT VIDEO INFO
    |--------------------------------------------------------------------------
    */
    public function extractInfo(Request $request)
    {
        $url = trim((string) $request->input('url', ''));

        if (!$url) {
            return response()->json(['error' => 'URL is required'], 400);
        }

        $data = $this->fetchVideoData($url);

        if (!$data) {
            return response()->json([
                'error' => 'Could not extract video info'
            ], 500);
        }

        return response()->json($this->buildInfoResponse($data));
    }

    public function getVideoInfo(Request $request)
    {
        return $this->extractInfo($request);
    }

    /*
    |--------------------------------------------------------------------------
    | FETCH VIDEO DATA (FIXED yt-dlp)
    |--------------------------------------------------------------------------
    */
    private function fetchVideoData(string $url)
{
    // 1. Normalize short URLs and remove tracking parameters
    if (preg_match('/youtu\.be\/([^?&#]+)/', $url, $m)) {
        $url = 'https://www.youtube.com/watch?v=' . $m[1];
    }

    // 2. Strict whitelist validation to ensure it's a valid YouTube URL format
    if (!preg_match('/^https?:\/\/(www\.)?(youtube\.com|youtu\.be)\/.+$/i', $url)) {
        Log::warning('Rejected invalid YouTube URL pattern', ['url' => $url]);
        return null;
    }

    $binary = $this->ytDlpBinary();

    // 3. Keep arguments securely isolated in an array rather than a single string
    // This allows Laravel's Process component to escape parameters safely automatically.
    $command = [
        $binary,
        '--dump-single-json',
        '--no-playlist',
        '--no-warnings',
        '--geo-bypass',
        '--extractor-args', 'youtube:player_client=android',
        $url
    ];

    // Running via array format removes the need for manual escapeshellarg()
    $result = Process::timeout(300)->run($command);

    if ($result->failed()) {
        Log::error('yt-dlp failed', [
            'url'    => $url,
            'stderr' => $result->errorOutput(),
            'stdout' => $result->output(),
        ]);
        return null;
    }

    $output = trim($result->output());
    if (!$output) {
        return null;
    }

    // 4. Safely decode to prevent memory faults on broken JSON streams
    $decoded = json_decode($output);
    if (json_last_error() !== JSON_ERROR_NONE) {
        Log::error('yt-dlp returned invalid JSON structure', ['error' => json_last_error_msg()]);
        return null;
    }

    return $decoded;
}
    /*
    |--------------------------------------------------------------------------
    | DOWNLOAD STREAM (FIXED)
    |--------------------------------------------------------------------------
    */
    public function downloadFile(Request $request)
    {
        $url = trim((string) $request->input('url', ''));
        $formatId = trim((string) $request->input('format_id', ''));

        if (!$url) {
            return response()->json(['error' => 'URL required'], 400);
        }

        $metadata = $this->fetchVideoData($url);

        $filename = $this->safeFilename(
            $metadata->title ?? 'video',
            'mp4'
        );

        $binary = $this->ytDlpBinary();

        $format = $formatId
            ? escapeshellarg($formatId)
            : '"bestvideo+bestaudio/best"';

        $command =
            $binary .
            " --no-progress " .
            " --merge-output-format mp4 " .
            " -f " . $format .
            " -o - " .
            escapeshellarg($url);

        $process = SymfonyProcess::fromShellCommandline($command);
        $process->setTimeout(null);
        $process->start();

        return response()->streamDownload(function () use ($process) {
            foreach ($process as $type => $data) {
                if ($type === SymfonyProcess::OUT) {
                    echo $data;
                    flush();
                }
            }
        }, $filename, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | SHORTS PAGINATION (FIXED)
    |--------------------------------------------------------------------------
    */
    public function getShorts(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min((int) $request->input('per_page', 5), 20));

        $maxLimit = 200;
        $totalNeeded = min($page * $perPage, $maxLimit);

        $searchTerm = "ytsearch{$totalNeeded}:shorts";

        $command = $this->ytDlpBinary() .
            " --dump-json --flat-playlist " .
            escapeshellarg($searchTerm);

        $result = Process::timeout(120)->run($command);

        if ($result->failed()) {
            return response()->json([
                'error' => 'Could not fetch shorts',
                'details' => $result->errorOutput()
            ], 500);
        }

        $lines = array_filter(explode("\n", trim($result->output())));
        $videos = array_map(fn($l) => json_decode($l), $lines);

        $formatted = $this->formatVideoList($videos);

        $offset = ($page - 1) * $perPage;

        return response()->json([
            'page' => $page,
            'per_page' => $perPage,
            'items' => $formatted->slice($offset, $perPage)->values(),
            'total_fetched' => $formatted->count(),
            'has_more' => $formatted->count() > ($offset + $perPage),
            'max_limit' => $maxLimit
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PLAYLIST SEARCH
    |--------------------------------------------------------------------------
    */
    public function searchPlaylists(Request $request)
    {
        $query = trim((string) $request->input('q', ''));

        if (!$query) return response()->json([]);

        $count = max(1, min((int)$request->input('count', 12), 25));

        $url = "https://www.youtube.com/results?search_query=" .
            urlencode($query) . "&sp=EgIQAw%3D%3D";

        $command = $this->ytDlpBinary() .
            " --dump-json --flat-playlist --playlist-end {$count} " .
            escapeshellarg($url);

        $result = Process::timeout(120)->run($command);

        if ($result->failed()) {
            return response()->json(['error' => 'Playlist search failed'], 500);
        }

        $lines = array_filter(explode("\n", trim($result->output())));
        $items = array_map(fn($l) => json_decode($l), $lines);

        return response()->json(
            collect($items)
                ->filter(fn($p) => isset($p->url) && str_contains($p->url, 'list='))
                ->values()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PLAYLIST VIDEOS
    |--------------------------------------------------------------------------
    */
    public function getPlaylistVideos(Request $request)
    {
        $id = trim((string)$request->input('list', ''));

        if (!$id) {
            return response()->json(['error' => 'Playlist id required'], 400);
        }

        $url = "https://www.youtube.com/playlist?list={$id}";

        $command = $this->ytDlpBinary() .
            " --dump-json --flat-playlist " .
            escapeshellarg($url);

        $result = Process::timeout(120)->run($command);

        if ($result->failed()) {
            return response()->json(['error' => 'Playlist fetch failed'], 500);
        }

        $lines = array_filter(explode("\n", trim($result->output())));
        $videos = array_map(fn($l) => json_decode($l), $lines);

        return response()->json($this->formatVideoList($videos));
    }

    /*
    |--------------------------------------------------------------------------
    | TRENDING
    |--------------------------------------------------------------------------
    */
    public function getTrendingVideos()
    {
        $command = $this->ytDlpBinary() .
            " --dump-json --flat-playlist --playlist-end 12 https://www.youtube.com";

        $result = Process::timeout(120)->run($command);

        if ($result->failed()) {
            return response()->json(['error' => 'Trending failed'], 500);
        }

        $lines = array_filter(explode("\n", trim($result->output())));
        $videos = array_map(fn($l) => json_decode($l), $lines);

        return response()->json($this->formatVideoList($videos));
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */
    private function ytDlpBinary(): string
    {
        $path = trim(shell_exec('which yt-dlp'));
        return $path ?: 'yt-dlp';
    }

    private function formatVideoList(array $videos)
    {
        return collect($videos)->map(function ($v) {
            $id = $v->id ?? null;

            return [
                'id' => $id,
                'title' => $v->title ?? 'Untitled',
                'thumbnail' => $v->thumbnail ?? ($id ? "https://i.ytimg.com/vi/{$id}/hqdefault.jpg" : ''),
                'url' => $v->url ?? ($id ? "https://www.youtube.com/watch?v={$id}" : ''),
                'views' => isset($v->view_count) ? number_format($v->view_count) : null,
            ];
        });
    }

    private function buildInfoResponse($data): array
    {
        $formats = collect($data->formats ?? []);

        $best = $formats->last();

        return [
            'id' => $data->id ?? null,
            'title' => $data->title ?? null,
            'thumbnail' => $data->thumbnail ?? null,
            'duration' => $data->duration ?? null,
            'uploader' => $data->uploader ?? null,
            'view_count' => $data->view_count ?? 0,
            'best_format_id' => $best->format_id ?? null,
            'formats' => $formats->values(),
        ];
    }

    private function safeFilename(string $title, string $ext): string
    {
        $title = preg_replace('/[^A-Za-z0-9._-]+/', '_', $title);
        return trim($title ?: 'video', '_') . '.' . $ext;
    }
}