# Audio Player

A simple HTML5+PHP Audio Player.  
Plays `.flac`, `.mp3`, `.wav` files.

## Requirements

- PHP 8.0 or newer
- A web server such as:
  - Apache + PHP
  - Nginx + PHP-FPM
  - PHP's built-in server for testing

## Audio folder

Player scans the folder and its subfolders specified in `api.php` on line 8.
Make sure PHP has access to that folder.

Example:

```php
const MUSIC_ROOT = '/var/www/html';
						↑
						|
						|
			 Folder with music files
				and/or subfolders
