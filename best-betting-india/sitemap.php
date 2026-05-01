<?php
$baseUrl = 'https://yaarwinapp.co';
$rootDir = __DIR__;
function xml_escape($value) { return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
$pages = array();
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    if (strtolower($file->getFilename()) !== 'index.html') continue;
    $fullPath = $file->getPathname();
    $relative = str_replace('\\', '/', substr($fullPath, strlen($rootDir)));
    $urlPath = dirname($relative);
    if ($urlPath === '/' || $urlPath === '.' || $urlPath === '') {
        $loc = $baseUrl . '/'; $priority = '1.0';
    } else {
        $cleanPath = trim(str_replace('\\', '/', $urlPath), '/');
        $loc = $baseUrl . '/' . $cleanPath . '/';
        $priority = ($cleanPath === 'blog') ? '0.9' : ((strpos($cleanPath, 'blog/') === 0) ? '0.8' : '0.9');
    }
    $pages[$loc] = array('loc'=>$loc,'lastmod'=>date('Y-m-d',$file->getMTime()),'changefreq'=>'weekly','priority'=>$priority);
}
ksort($pages);
header('Content-Type: application/xml; charset=UTF-8');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($pages as $page) {
    echo "  <url>\n";
    echo "    <loc>" . xml_escape($page['loc']) . "</loc>\n";
    echo "    <lastmod>" . xml_escape($page['lastmod']) . "</lastmod>\n";
    echo "    <changefreq>" . xml_escape($page['changefreq']) . "</changefreq>\n";
    echo "    <priority>" . xml_escape($page['priority']) . "</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
?>

<style id="nta21-register-button-color-final-audit">
:root{--primary:#1c9536!important;--primary2:#14752b!important;--primary-2:#14752b!important;--accent:#1c9536!important;--green:#1c9536!important;--rev29-teal:#1c9536!important;--rev29-teal2:#14752b!important;--rev29-gold:#1c9536!important;}
a.btn,.btn,.button,.button.primary,.btn-primary,.cta-primary,.yw-register-btn,.yw-login-btn,.hero .cta-row .button.primary,
.blog-content .button,.blog-content a.button,.article-cta .button,.post-cta .button,.register-now-box a,.sticky-register a,
.yw-mobile-sticky-cta a,a[href*="register"],a[href="/register/"],a[href*="/login"],input[type="submit"],button[type="submit"],
.rev32-site-header .button.primary,.rev32-site-header .auth-buttons a:last-child,.yw-final-actions .yw-register-btn,.cta .btn,.cta a,.hero a.btn,
.header .btn,.nav .btn,.auth-buttons a:last-child,.blog-card .btn,.article-card .btn{
  background:#1c9536!important;background-color:#1c9536!important;background-image:linear-gradient(180deg,#1c9536,#14752b)!important;
  border-color:rgba(28,149,54,.62)!important;color:#fff!important;box-shadow:0 12px 28px rgba(28,149,54,.26), inset 0 1px 0 rgba(255,255,255,.18)!important;}
a.btn:hover,.btn:hover,.button:hover,.button.primary:hover,.btn-primary:hover,.cta-primary:hover,.yw-register-btn:hover,.yw-login-btn:hover,
.blog-content .button:hover,.article-cta .button:hover,.post-cta .button:hover,a[href*="register"]:hover,a[href*="/login"]:hover,input[type="submit"]:hover,button[type="submit"]:hover{
  background:#14752b!important;background-color:#14752b!important;background-image:linear-gradient(180deg,#23a940,#14752b)!important;color:#fff!important;}
.yw-login-btn,.button.outline,.btn-outline,.rev32-site-header .button.outline,.rev32-site-header .auth-buttons a:first-child{background:rgba(255,255,255,.045)!important;background-image:none!important;border-color:rgba(28,149,54,.46)!important;color:#fff!important;box-shadow:none!important;}
.teal,.hero h1 .teal,.hero-kicker,.eyebrow,.trust-item b,.float-dice,.proof-icon,.payment-count,.section-header b,.keyword-pills a:hover,.keyword-pills span,.guide-toc a:hover,.blog-content a:hover,.footer a:hover,.faq summary:hover,.active,.is-active{color:#1c9536!important;}
.badge,.pill,.payment-logo,.glass-card,.card,.blog-card,.proof-card,.trust-item,.payment-strip,.payment-rail,.guide-band,.guide-mini,.faq-item,.player-copy,.keyword-pills a,.keyword-pills span,.guide-toc a,.hero-panel,.yw-final-header,.footer-bottom{border-color:rgba(28,149,54,.24)!important;}
.cta{background:linear-gradient(135deg,rgba(28,149,54,.16),rgba(28,149,54,.10))!important;border-color:rgba(28,149,54,.28)!important;}
</style>
