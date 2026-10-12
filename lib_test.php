<?php
// Self-check for lib.php: php -d zend.assertions=1 -d assert.exception=1 lib_test.php
if (PHP_SAPI !== 'cli') exit();
require_once('lib.php');

foreach (['http://127.0.0.1/', 'http://localhost/', 'http://169.254.169.254/latest/meta-data/',
          'http://10.0.0.1/', 'http://192.168.1.1/', 'http://[::1]/', 'http://2130706433/',
          'file:///etc/passwd', 'httpfoo', 'gopher://example.com/'] as $url) {
    assert(!is_public_url($url), "should block $url");
}
assert(is_public_url('https://example.com/'), 'should allow example.com (needs DNS)');

assert(resolve_location('https://a.com/x/y.html', '/z') == 'https://a.com/z');
assert(resolve_location('https://a.com/x/y.html', 'z') == 'https://a.com/x/z');
assert(resolve_location('https://a.com/x/y.html', '//b.com/z') == 'https://b.com/z');
assert(resolve_location('http://a.com:8080/', 'https://b.com/') == 'https://b.com/');

assert(h('"><script>') == '&quot;&gt;&lt;script&gt;');
echo "ok\n";
