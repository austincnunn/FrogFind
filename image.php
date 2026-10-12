<?php
    require_once('lib.php');

    $url = "";

    //get the image url
    if (isset( $_GET['i'] ) ) {
        $url = $_GET[ 'i' ];
    } else {
        exit();
    }

    //formats GD can read; image_compressed.php converts them all to JPEG
    if (!preg_match('/\.(jpe?g|png|gif|webp)/i', $url)) {
        echo "Unsupported file type :(";
        exit();
    }

    //image needs to start with http
    if (!preg_match('#^https?://#i', $url)) {
        echo("Image failed :(");
        exit();
    }

    $back_url = $_SERVER['HTTP_REFERER'] ?? '/';

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 2.0//EN">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">


 <html>
 <head>
     <title>FrogFind Image Viewer</title>
 </head>
 <body>
    <small><a href="<?php echo h($back_url) ?>">&lt; Back to previous page</a></small>
    <p><small><b>Viewing image:</b> <?php echo h($url) ?></small></p>
    <img src="/image_compressed.php?i=<?php echo urlencode($url); ?>">
    <br><br>
    <small><a href="<?php echo h($back_url) ?>">&lt; Back to previous page</a></small>
 </body>
 </html>
