<?php
require_once('vendor/autoload.php');
require_once('lib.php');

$article_url = "";
$article_html = "";
$readable_article = "";
$error_text = "";

// List of Content-Types that we know we can (try to) parse.
// Anything else will get piped through directly, if possible.
$compatible_content_types = [
    "text/html",
    "text/plain"
];

// The maximum allowed filesize for proxy download passthroughs.
// Any file larger than this will instead show an error message, with
// a direct link to the file.
$proxy_download_max_filesize = 8000000; // ~ 8Mb

if( isset( $_GET['a'] ) ) {
    $article_url = $_GET["a"];
} else {
    echo "What do you think you're doing... >:(";
    exit();
}

if (!is_public_url($article_url)) {
    echo("That's not a web page :(");
    die();
}

// fetch one byte past the limit so we can tell when a file is too large
if (!$response = safe_fetch($article_url, $proxy_download_max_filesize + 1)) {
    $error_text .=  "Failed to get the article :( <br>";
} else {
    // "text/html; charset=utf-8" -> "text/html"
    $contentType = strtolower(trim(explode(';', $response['headers']['content-type'] ?? '')[0]));

    // Attempt to handle downloads or other mime-types by passing proxying them through.
    if ($contentType && !in_array($contentType, $compatible_content_types)) {
        // Check if the linked file isn't too large for us to proxy.
        if (strlen($response['body']) > $proxy_download_max_filesize) {
            echo 'Failed to proxy file download, it\'s too large. :( <br>';
            echo 'You can try downloading the file directly: ' . h($article_url);
            die();
        }

        $filename = basename(parse_url($response['url'], PHP_URL_PATH) ?? '');
        // keep the filename header-safe
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        // If no filename can be deduced from the URL, set a placeholder filename
        if (!$filename) {
            $filename = "download";
        }

        // Set the content headers based on the file we're proxying through.
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . strlen($response['body']));
        // Set the content-disposition to encourage the browser to download the file.
        header('Content-Disposition: attachment; filename="'. $filename . '"');
        header('X-Content-Type-Options: nosniff');

        echo $response['body'];
        die();
    }

    $article_html = $response['body'];
}

use fivefilters\Readability\Readability;
use fivefilters\Readability\Configuration;
use fivefilters\Readability\ParseException;

$readability = new Readability(new Configuration(
    keepInlineByline: true,
    fixRelativeURLs: true,
    originalURL: $response ? $response['url'] : $article_url,
));

$article_title = "";
$article_images = [];

if ($article_html) try { // nothing to parse if the fetch failed; its error is already set
    $article = $readability->parse($article_html);
    $article_title = (string)$article->title;
    $article_images = $article->images;
    if (!$article->hasContent()) {
        $error_text .= "Sorry! Couldn't find an article on that page :( <br>";
    }
    $readable_article = simplify_html((string)$article->content);
    $readable_article = clean_str($readable_article);

} catch (ParseException $e) {
    $error_text .= 'Sorry! ' . h($e->getMessage()) . '<br>';
}

// Rebuild the article from a short list of old-browser-friendly tags, with no attributes
// (onclick, style, javascript: links...) except <a href>, and web links routed through our proxy.
// Done with DOM rather than strip_tags(), which chokes on "<" inside attributes (e.g. Wikipedia's data-mw).
function simplify_html($html) {
    $allowed = ['a', 'ol', 'ul', 'li', 'br', 'p', 'small', 'font', 'b', 'i', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    $renamed = ['strong' => 'b', 'em' => 'i'];

    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    $wrapper = $doc->getElementsByTagName('div')->item(0);

    // drop comments: wasted bytes, and old IE runs <!--[if IE]> conditional comments
    foreach (iterator_to_array((new DOMXPath($doc))->query('//comment()')) as $comment) {
        $comment->parentNode->removeChild($comment);
    }

    // reverse document order handles children before their parents
    foreach (array_reverse(iterator_to_array($doc->getElementsByTagName('*'))) as $el) {
        if ($el === $wrapper) {
            continue;
        }
        $tag = $renamed[$el->nodeName] ?? $el->nodeName;
        $href = trim($el->getAttribute('href'));
        $new = null;

        if (in_array($tag, $allowed)) {
            $new = $doc->createElement($tag); // fresh element, so no attributes carried over
            if ($tag == 'a' && preg_match('#^https?://#i', $href)) {
                $new->setAttribute('href', '/read.php?a=' . urlencode($href));
            } elseif ($tag == 'a' && stripos($href, 'mailto:') === 0) {
                $new->setAttribute('href', $href);
            }
            $el->parentNode->insertBefore($new, $el);
        }

        // move the children out (into the replacement, or up a level to unwrap) and drop the original
        while ($el->firstChild) {
            if ($new) {
                $new->appendChild($el->firstChild);
            } else {
                $el->parentNode->insertBefore($el->firstChild, $el);
            }
        }
        $el->parentNode->removeChild($el);
    }

    $out = "";
    foreach ($wrapper->childNodes as $node) {
        $out .= $doc->saveHTML($node);
    }
    return $out;
}
?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 2.0//EN">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">

 <html>
 <head>
     <title><?php echo h($article_title);?></title>
 </head>
 <body>
    <p>
        <form action="/read.php" method="get">
        <a href="/">Back to <b><font color="#008000">Frog</font><font color="000000">Find!</font></a></b> | Browsing URL: <input type="text" size="38" name="a" value="<?php echo h($article_url) ?>">
        <input type="submit" value="Go!">
        </form>
    </p>
    <hr>
    <h1><?php echo clean_str(h($article_title));?></h1>
    <p> <?php
        $img_num = 0;
        $imgline_html = "View page images:";
        foreach ($article_images as $image_url):
            //formats GD can read; image_compressed.php converts them all to JPEG
            if (preg_match('/\.(jpe?g|png|gif|webp)/i', $image_url)) {
                $img_num++;
                $imgline_html .= " <a href='image.php?i=" . urlencode($image_url) . "'>[$img_num]</a> ";
            }
        endforeach;
        if($img_num>0) {
            echo  $imgline_html ;
        }
    ?></small></p>
    <?php if($error_text) { echo "<p><font color='red'>" . $error_text . "</font></p>"; } ?>
    <p><font size="4"><?php echo $readable_article;?></font></p>
 </body>
 </html>
