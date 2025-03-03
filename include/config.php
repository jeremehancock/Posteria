<?php

# Posteria: A Media Poster Collection App
# Save all your favorite custom media server posters in one convenient place
#
# Developed by Jereme Hancock
# https://github.com/jeremehancock/Posteria
#
# MIT License
#
# Copyright (c) 2024 Jereme Hancock
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in all
# copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
# SOFTWARE.

$auth_config = [
	'username' => getEnvWithFallback('AUTH_USERNAME', 'admin'),
	'password' => getEnvWithFallback('AUTH_PASSWORD', 'changeme'),
	'session_duration' => getIntEnvWithFallback('SESSION_DURATION', 3600) // 1 hour default
];

$plex_config = [
    'server_url' => getEnvWithFallback('PLEX_SERVER_URL', ''),
    'token' => getEnvWithFallback('PLEX_TOKEN', ''),
    'connect_timeout' => getIntEnvWithFallback('PLEX_CONNECT_TIMEOUT', 10),
    'request_timeout' => getIntEnvWithFallback('PLEX_REQUEST_TIMEOUT', 60),
    'import_batch_size' => getIntEnvWithFallback('PLEX_IMPORT_BATCH_SIZE', 25)
];

?>
