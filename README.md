# Universal Social Media Downloader (Laravel + Next.js)

A full-stack application to download videos from YouTube, TikTok, Instagram, Facebook, and more using `yt-dlp` and `FFmpeg`.

## 🚀 Server-Side Setup (Ubuntu)

Before running the Laravel backend, you must install the required system dependencies to handle video extraction and processing.

### 1. Update System & Install Core Dependencies
First, ensure your package list is up to date and install **Python 3** (to run the downloader) and **FFmpeg** (to merge high-quality video and audio).

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install ffmpeg python3 -y
