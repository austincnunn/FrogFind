<?php
require_once('lib.php');

$url = "";
$raw_image = NULL;

//get the image url
if (isset( $_GET['i'] ) ) {
    $url = $_GET[ 'i' ];
} else {
    exit();
}

//safe_fetch() refuses anything that isn't a public http(s) address
if (!$response = safe_fetch($url)) {
    exit();
}
//GD sniffs the real format (jpg, png, gif, webp...) from the bytes
if (!$raw_image = @imagecreatefromstring($response['body'])) {
    exit();
}

$raw_imagex = imagesx($raw_image);
$raw_imagey = imagesy($raw_image);

if ($raw_imagex >= $raw_imagey) {
	$dest_imagex = 300;
	$dest_imagey = intval(($raw_imagey / $raw_imagex) * $dest_imagex);
} else {
	$dest_imagey = 200;
	$dest_imagex = intval(($raw_imagex / $raw_imagey) * $dest_imagey);
}

$dest_image = imagecreatetruecolor($dest_imagex, $dest_imagey);

//always output JPEG: the most compatible photo format for vintage browsers (Netscape 1.1+)
//JPEG has no transparency, so paint a white background under transparent images
imagefill($dest_image, 0, 0, imagecolorallocate($dest_image, 255, 255, 255));

imagecopyresampled($dest_image, $raw_image, 0, 0, 0, 0, $dest_imagex, $dest_imagey, $raw_imagex, $raw_imagey);

header('Content-type: image/jpeg');
imagejpeg($dest_image,NULL,80); //80% quality

?>
