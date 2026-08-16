# Music Player

A simple HTML5+php Audio Player
Plays .flac .mp3 .wav files

Requierments:
PHP 8.0 or newer
A web server such as:
  • Apache + PHP
  • Nginx + PHP-FPM
  • PHP's built-in server for testing

Player scans folder and its subfolders specified in app.php in line 8
ex.
const MUSIC_ROOT = '/var/www/html';
                            🡅
                            |
                            |
                 Folder with music files
                    and/or subfolders
