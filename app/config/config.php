<?php
declare(strict_types=1);

define('PHOTO_ROOT', '/mnt/sauv/photos');

define(
    'PHOTO_CACHE',
    '/var/www/photo/cache/thumbnails'
);

define('PHOTO_SERVICE', 'photo');

define('PHOTO_BASE_URL', '/photo');

define(
    'PHOTO_LOG',
    '/var/www/photo/logs/photo.log'
);

define('PHOTO_MAX_THUMBNAIL_SIZE', 350);

define(
    'PHOTO_IMAGE_EXTENSIONS',
    ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']
);

define(
    'PHOTO_VIDEO_EXTENSIONS',
    ['mp4', 'webm', 'ogv']
);

define(
    'PHOTO_LOGIN_URL',
    'https://photo.partitions.ovh/connexion.php'
);

define(
    'PHOTO_LOGOUT_URL',
    'https://photo.partitions.ovh/deconnexion.php'
);
