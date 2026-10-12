<?php
require_once('vendor/autoload.php');
require_once('lib.php');

$show_results = FALSE;
$error_text = "";
$final_result_html = "<hr>";

$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$has_next = FALSE;

if(isset( $_GET['q'])) { // if there's a search query, show the results for it
    $show_results = TRUE;
    // SearXNG runs next to FrogFind (see compose.yaml); its JSON API needs no scraping
    $search_url = (getenv('SEARXNG_URL') ?: 'http://searxng:8080') . '/search?format=json&q=' . urlencode($_GET['q']);

    // SearXNG pages hold ~80 merged results, so slice them into our smaller pages and only
    // fetch its next page when we run out (one past ours, to know if there's a next page)
    $results = []; // keyed by url, so repeats across SearXNG pages are dropped
    $failed = FALSE;
    $unresponsive = FALSE;
    for ($sx_page = 1; count($results) <= $page * $per_page && $sx_page <= 5; $sx_page++) {
        $response = searxng_search($search_url . '&pageno=' . $sx_page);
        if (!is_array($response)) {
            $failed = TRUE;
            break;
        }
        $unresponsive = $unresponsive || !empty($response['unresponsive_engines']);
        if (empty($response['results'])) {
            break;
        }
        foreach ($response['results'] as $result) {
            $results[$result['url']] = $results[$result['url']] ?? $result;
        }
    }
    $has_next = count($results) > $page * $per_page;
    $page_results = array_slice(array_values($results), ($page - 1) * $per_page, $per_page);

    if (!$page_results && $results) {
        $error_text .= "No more results :( <br>";
    } elseif (!$page_results && $failed) {
        $error_text .= "Failed to get results, sorry :( <br>";
    } elseif (!$page_results && $unresponsive) {
        $error_text .= "The search engines didn't answer (maybe rate-limited). Try again in a few minutes :( <br>";
    } elseif (!$page_results) {
        $error_text .= "No results found :( <br>";
    }

    foreach ($page_results as $result) {
        // result link, redirected through our proxy
        $result_link = '/read.php?a=' . urlencode($result['url']);
        $result_display_url = preg_replace('#^https?://(www\.)?#', '', $result['url']);

        $final_result_html .= "<br><a href='" . h($result_link) . "'><font size='4'><b>" . h(clean_str($result['title'])) . "</b></font><br><font color='#008000' size='2'>"
                            . h($result_display_url) . "</font></a><br>" . h(clean_str($result['content'] ?? '')) . "<br><br><hr>";
    }

    // page links at the bottom
    $page_url = '/?q=' . urlencode($_GET['q']) . '&page=';
    $page_links = [];
    if ($page > 1) {
        $page_links[] = "<a href='" . h($page_url . ($page - 1)) . "'>&lt; Previous page</a>";
    }
    $page_links[] = "Page " . $page;
    if ($has_next) {
        $page_links[] = "<a href='" . h($page_url . ($page + 1)) . "'>Next page &gt;</a>";
    }
    $final_result_html .= "<br><center>" . implode(" | ", $page_links) . "</center><br>";
}

// Fetch SearXNG JSON, cached for 10 minutes: engines reorder live results on every request,
// so without this, results shift and repeat between our pages. Also spares the engines' rate limits.
// Shared between users on purpose: the key is the exact query + page and results aren't personalized.
function searxng_search($url) {
    $cache_seconds = 600;
    $cache_dir = sys_get_temp_dir();
    $cache_file = $cache_dir . '/frogfind-search-' . md5($url) . '.json';

    // now and then (~1 in 20 searches), delete expired cache files
    if (mt_rand(1, 20) == 1) {
        foreach (glob($cache_dir . '/frogfind-search-*.json') as $old_file) {
            if (@filemtime($old_file) < time() - $cache_seconds) {
                @unlink($old_file);
            }
        }
    }

    if (@filemtime($cache_file) > time() - $cache_seconds && $cached = json_decode((string)@file_get_contents($cache_file), true)) {
        return $cached;
    }

    $response = json_decode((string)@file_get_contents($url), true);
    if (!empty($response['results'])) { // don't cache failures or empty answers
        // write a temp file, then rename it into place: rename is atomic, so a search
        // running at the same moment never reads a half-written file
        $temp_file = tempnam($cache_dir, 'frogfind-tmp-');
        file_put_contents($temp_file, json_encode($response));
        rename($temp_file, $cache_file);
    }
    return $response;
}

?>
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 2.0//EN">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">

<html>
<head>
	<title>FrogFind!</title>
</head>
<body>

<?php if($show_results) { // there's a search query in q, so show search results ?>

    <form action="/" method="get">
    <a href="/"><font size=6 color="#008000">Frog</font><font size=6 color="#000000">Find!</font></a> Leap again: <input type="text" size="30" name="q" value="<?php echo h($_GET['q']) ?>">
    <input type="submit" value="Ribbbit!">
    </form>
    <hr>
    <br>
    <center>Search Results for <b><?php echo h($_GET['q']) ?></b></center>
    <br>
    <?php if($error_text) { echo "<p><font color='red'>" . $error_text . "</font></p>"; } ?>
    <?php echo $final_result_html ?>
    
<?php } else { // no search query, so show new search ?>
    <br><br><center><h1><font size=7><font color="#008000">Frog</font>Find!</font></h1></center>
    <center><h3>The Search Engine for Vintage Computers</h3></center>
    <br><br>
    <center>
    <form action="/" method="get">
    Leap to: <input type="text" size="30" name="q"><br>
    <input type="submit" value="Ribbbit!">
    </center>
    <br><br><br>
    <small><center>Built by <b><a href="https://youtube.com/ActionRetro">Action Retro</a></b> on YouTube | <a href="about.php">Why build such a thing?</a></center><br>
    <small><center>Powered by SearXNG</center></small>
</form>
</form>

<?php } ?>

</body>
</html>