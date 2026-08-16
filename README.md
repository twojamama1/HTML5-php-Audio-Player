# Music Player

A simple HTML5+PHP Audio Player.  
Plays `.flac`, `.mp3`, `.wav` files.

## Requirements

- PHP 8.0 or newer
- A web server such as:
  - Apache + PHP
  - Nginx + PHP-FPM
  - PHP's built-in server for testing

## Music folder

Player scans the folder and its subfolders specified in `app.php` on line 8.

Example:

```php
const MUSIC_ROOT = '/var/www/html';
						↑
						|
						|
			 Folder with music files
				and/or subfolders
