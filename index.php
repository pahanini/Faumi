<?php
$webroot = '/srv/http/faumi.com/www';
$channel = array('a' => 'Anime', 'b' => 'Random');
?>
<?php if ($_SERVER['REQUEST_URI'] === '/'): ?>
<?php header('Location: /null/', true, 302); ?>
<?php elseif (preg_match('/^\/('.implode('|', array_keys($channel)).')\/(\s*|res\/(\d+)\.html)?$/', $_SERVER['REQUEST_URI'], $match)): $forum = $match[1]; $id = isset($match[3])?$match[3]:NULL; ?>
<?php
$dbh = new SQLite3(dirname($webroot).'/'.$forum.'.db');
$dbh->busyTimeout(5000);
$dbh->query('PRAGMA synchronous=NORMAL;');
$dbh->query('PRAGMA journal_mode=WAL;');
$dbh->query('
CREATE TABLE IF NOT EXISTS "forum" (
  "post_id" INTEGER NOT NULL,
  "forum_id" VARCHAR(255) NOT NULL,
  "post_date" DATETIME DEFAULT NULL,
  "post_date_created" DATETIME DEFAULT CURRENT_TIMESTAMP,
  "post_date_modified" DATETIME DEFAULT NULL,
  "post_date_bumped" DATETIME DEFAULT NULL,
  "post_subject" VARCHAR(255) DEFAULT NULL,
  "post_body" TEXT DEFAULT NULL,
  "post_text" TEXT DEFAULT NULL,
  "post_password" TEXT DEFAULT NULL,
  "post_attach" VARCHAR(255) DEFAULT NULL,
  "post_author" INTEGER DEFAULT NULL,
  "post_author_name" VARCHAR(255) DEFAULT NULL,
  "post_author_email" VARCHAR(255) DEFAULT NULL,
  "post_author_jabberid" VARCHAR(255) DEFAULT NULL,
  "post_author_www" VARCHAR(255) DEFAULT NULL,
  "post_author_ip" VARCHAR(45) DEFAULT NULL,
  "post_agent" VARCHAR(255) DEFAULT NULL,
  "thread" VARCHAR(255) DEFAULT NULL,
  "multithread" VARCHAR(255) DEFAULT NULL
)
');
?>
<?php
if (isset($id)) {
$try = $dbh->prepare('SELECT * FROM forum WHERE post_id = :post_id');
$try->bindValue(':post_id', $id);
if ($row = $try->execute()->fetchArray()) extract($row); unset($row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

if (isset($_POST['delete'])) {
if (empty($_POST['password']) || is_array($_POST['delete']) === FALSE) { die('invalid request'); }
$delete_count = count($_POST['delete']);
$try = $dbh->prepare((empty($_POST['fileonly'])?'DELETE FROM forum':'UPDATE forum SET post_attach = NULL').' WHERE post_id IN ('.substr(str_repeat(', ?', $delete_count), 2).')'.($_POST['password'] === 'admin'?'':' AND post_password = :post_password'));
for ($i = 0; $i < $delete_count; $i++) $try->bindValue(($i + 1), $_POST['delete'][$i]);
if ($_POST['password'] !== 'admin') $try->bindValue(':post_password', $_POST['password']);
$try->execute();
header('Location: /'.$forum.'/', true, 303);
die();
}

$csrf = md5(serialize(array('IP' => $_SERVER['REMOTE_ADDR'], session_name() => session_id(), 'salt' => $webroot))); if (@$_POST['CSRF'] !== $csrf) die('YOU SHALL NOT PASS');
$req = $dbh->query('SELECT COUNT(*) FROM forum WHERE post_author_ip = "'.$_SERVER['REMOTE_ADDR'].'" AND post_date_created > datetime("now", "-5 minutes")')->fetchArray()['COUNT(*)']; if ($req >= 5) die('STOP FLOOD');

$text = str_replace(array("\r\n", "\r"), "\n", $_POST['message']);
if ($text === '') die('$text is NULL');
if (mb_strlen($text, 'utf-8') > 9000) die('len($text) over9000');
if (substr_count($text, "\n") > 90) die('count($text, "\n") over90<s>00</s>');

$subject = str_replace(array("\r\n", "\r"), "\n", $_POST['subject']);
if (mb_strlen($subject, 'utf-8') > 90) die('len($subject) over90<s>00</s>');

if (empty($_POST['nofile']) && ($files = count($_FILES['file']['tmp_name'])) > 0) {
if (isset($_FILES['file']['error']) === FALSE || is_array($_FILES['file']['error']) === FALSE) { die('invalid request'); }
$upload = NULL;

for ($i = 0; $i < $files; $i++) {
$file = $_FILES['file']['tmp_name'][$i];
$name = $_FILES['file']['name'][$i];
$size = $_FILES['file']['size'][$i];

if (empty($file)) { continue; }
if ($_FILES['file']['error'][$i] > 0) { die('try again'); }
if (is_uploaded_file($file) === FALSE) { die('upload error'); }
if ($size < 43 || $size > (9000 * 1024)) { die('file size limit exceeded'); }

if ($fh = fopen($file, 'rb')) {
$b6 = fread($fh, 6);
fclose($fh);
if (substr($b6, 0, 4) === "\xff\xd8\xff\xe0") $mime = 'image/jpeg';
elseif ($b6 === 'GIF87a' || $b6 === 'GIF89a') $mime = 'image/gif';
elseif ($b6 === "\x89PNG\x0d\x0a") $mime = 'image/png';
else die('unknown file');
}
else die('read error');

$name = time().substr(microtime(), 2, 3);
if ($mime === 'image/jpeg') {
if ((list($w, $h) = getimagesize($file)) === FALSE) die('read error');
$image = imagecreatefromjpeg($file);
$ext = '.jpg';
}
elseif ($mime === 'image/gif') {
if ((list($w, $h) = getimagesize($file)) === FALSE) die('read error');
$image = imagecreatefromgif($file);
$ext = '.gif';
}
elseif ($mime === 'image/png') {
if ((list($w, $h) = getimagesize($file)) === FALSE) die('read error');
$image = imagecreatefrompng($file);
$ext = '.png';
}
else die('unknown file');

if ($w > 320 || $h > 240) {
$ratio = min(320 / $w, 240 / $h);
$width = $w * $ratio;
$height = $h * $ratio;
$thumb = imagecreatetruecolor($width, $height);
if ($mime == 'image/gif' || $mime == 'image/png') {
imagecolortransparent($thumb, imagecolorallocatealpha($image, 0, 0, 0, 127));
imagealphablending($thumb, false);
imagesavealpha($thumb, true);
}
imagecopyresampled($thumb, $image, 0, 0, 0, 0, $width, $height, $w, $h);
if ($mime === 'image/jpeg') imagejpeg($thumb, $file.'s');
elseif ($mime === 'image/gif') imagegif($thumb, $file.'s');
elseif ($mime === 'image/png') imagepng($thumb, $file.'s');
}
imagedestroy($image);
imagedestroy($thumb);

if (@move_uploaded_file($file, $webroot.'/'.$forum.'/src/'.$name.$ext)) {
$upload .= $name.$ext.' ';
if (file_exists($file.'s')) rename($file.'s', $webroot.'/'.$forum.'/thumb/'.$name.$ext);
}
else die('write error');
}
}

$name = $_POST['name'];
$tripcode = NULL;
if (preg_match('/(#|!)(.*)/', $name, $match)) {
$cap = $match[2];
if (strpos($name, '#') === FALSE) $del = '!';
elseif (strpos($name, '!') === FALSE) $del = '#';
else $del = (strpos($name, '#') < strpos($name, '!'))?'#':'!';
if (preg_match('/(.*)('.$del.$del.')(.*)/', $cap, $match)) {
$cap = $match[1];
$caps = $match[3];
}
if ($cap != '') {
$cap = strtr($cap, '&amp;', '&');
$cap = strtr($cap, ',', ', ');
$salt = substr($cap.'H.', 1, 2);
$salt = preg_replace('/[^\.-z]/', '.', $salt);
$salt = strtr($salt, ':;<=>?@[\\]^_`', 'ABCDEFGabcdef');
$tripcode = substr(crypt($cap, $salt), -10);
}
if (isset($caps)) {
if ($cap != '') $tripcode .= '!';
$tripcode .= '!'.substr(md5($caps), 2, 10);
}
$name = preg_replace('/('.$del.')(.*)/', '', $name);
}

$password = $_POST['password'];

$dbh->query('BEGIN TRANSACTION;');
$last_row = $dbh->query('SELECT * FROM forum ORDER BY post_id DESC LIMIT 1')->fetchArray();
$id = ($last_row['post_id'] + 1);

$thread = (isset($thread)?$thread.'.':'').$id;
$multithread = (isset($multithread)?$multithread.'.':'').$id;
$ref = array('multithread' => array($id => array(1 => $multithread)), 'local_multithread' => array($id => array(1 => NULL)));

if (isset($post_id)) {
$try = $dbh->query('SELECT * FROM forum WHERE post_id = '.$post_id.' ORDER BY post_id DESC');
while ($row = $try->fetchArray()) {
$ref['multithread'][($post_id)][(@++$ref_n)] = $row['multithread'];
if (isset(array_flip(explode('.', @$ref['local_multithread'][($post_id)][($ref_n)]))[($id)]) === FALSE) $ref['local_multithread'][($post_id)][($ref_n)] = @$ref['local_multithread'][($post_id)][($ref_n)].'.'.$id;
}
}

$body = rtrim($text);
$body = str_replace(array('&', '<', '>'), array('&amp;', '&lt;', '&gt;'), $body);
$body = preg_replace_callback('/&gt;&gt;([0-9]+)/',
function ($match) {
global $dbh, $forum, $id, $last_row, $ref;
$try = $dbh->query('SELECT * FROM forum WHERE post_id = '.$match[1].' ORDER BY post_id DESC');
while ($row = $try->fetchArray()) {
$res = $row;
$ref['multithread'][($match[1])][(@++$ref_n)] = $row['multithread'];
if (isset(array_flip(explode('.', @$ref['local_multithread'][($match[1])][($ref_n)]))[($id)]) === FALSE) $ref['local_multithread'][($match[1])][($ref_n)] = @$ref['local_multithread'][($match[1])][($ref_n)].'.'.$id;
}
if (isset($res)) { return '<a href="/'.$forum.'/res/'.explode('.', $res['thread'])[0].'.html#'.$match[1].'">'.$match[0].'</a>'; }
return $match[0]; }, $body);
$body = preg_replace('/^(&gt;(.*))\n/m', '<i>\1</i>'."\n", $body);
$body = str_replace("\n\n", '</p><p>', '<p>'.$body.'</p>');

foreach ($ref['multithread'] as $ref_id => $ref_n) {
foreach ($ref_n as $n => $ref_multithread) {
$multithread = $ref_multithread.$ref['local_multithread'][($ref_id)][($n)];
if (@$last_multithread === $multithread) continue; $last_multithread = $multithread;

$try = $dbh->prepare('INSERT INTO forum (post_id, forum_id, thread, multithread, post_subject, post_body, post_text, post_password, post_attach, post_author, post_author_name, post_author_ip, post_agent) VALUES (:post_id, :forum_id, :thread, :multithread, :post_subject, :post_body, :post_text, :post_password, :post_attach, :post_author, :post_author_name, :post_author_ip, :post_agent)');
$try->bindValue(':post_id', $id);
$try->bindValue(':forum_id', $forum);
$try->bindValue(':post_subject', $subject);
$try->bindValue(':post_body', $body);
$try->bindValue(':post_text', $text);
$try->bindValue(':post_password', $password);
$try->bindValue(':post_attach', substr($upload, 0, -1));
$try->bindValue(':post_author', $tripcode);
$try->bindValue(':post_author_name', $name);
$try->bindValue(':post_author_ip', $_SERVER['REMOTE_ADDR']);
$try->bindValue(':post_agent', $_SERVER['HTTP_USER_AGENT']);
$try->bindValue(':thread', $thread);
$try->bindValue(':multithread', $multithread);
$try->execute();
}
}
$dbh->query('COMMIT;');

header('Location: /'.$forum.'/res/'.explode('.', $dbh->query('SELECT * FROM forum ORDER BY post_id DESC LIMIT 1')->fetchArray()['multithread'])[0].'.html', true, 303);
die();
}
?>
<?php if ($_SERVER['REQUEST_METHOD'] === 'GET'): ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?php echo $channel[($forum)]; ?></title>
<style>
p { text-indent: 2em; word-wrap: break-word; }
.wrap { margin: 0; padding: 1em; }
.clear { clear: both; }
.thread { overflow: auto; }
.thread img { float: left; margin: 0 1em 0 0; }
.post { float: left; clear: both; border: 1px #000 solid; margin: 1em 0 0 0; padding: 5px; }
.post img { float: left; margin: 0 1em 0 0; }
.centered_wrap { position: relative; float: left; left: 50%; }
.centered_text { position: relative; float: left; left: -50%; }
.re { margin: 0 0 0 2em; }
</style>
</head>
<body>
<div class="wrap">
[ <?php foreach ($channel as $k => $v): ?> <a href="/<?php echo $k; ?>/">/<?php echo $k; ?>/</a> <?php endforeach; ?> ]
<hr>
<center><h1>/<?php echo $forum; ?>/</h1><h2><?php echo $channel[($forum)]; ?></h2></center>
<hr>
<div class="centered_wrap">
<div class="centered_text">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="CSRF" value="<?php echo md5(serialize(array('IP' => $_SERVER['REMOTE_ADDR'], session_name() => session_id(), 'salt' => $webroot))); ?>">
<input type="hidden" name="forum" value="<?php echo $forum; ?>">
<table>
<tbody>
<tr><th>Name</th><td><input type="text" name="name"></td></tr>
<tr><th>eMail</th><td><input type="text" name="email" disabled></td></tr>
<tr><th>Subject</th><td><input type="text" name="subject" size="60"></td></tr>
<tr><th>FORUM</th><td><select name="forum"><?php foreach ($channel as $k => $v): ?><option value="<?php echo $k; ?>">/<?php echo $k; ?>/ <?php echo $v; ?></optionv> <?php endforeach; ?></select></td></tr>
<tr><th>Message</th><td><textarea name="message" rows="5" cols="80"></textarea></td></tr>
<tr><th>File</th><td><input type="file" name="file[]" size="40" multiple=""> [<input type="checkbox" name="nofile"> No file]</td></tr>
<tr><th>Password</th><td><input type="password" name="password" size="40"></td></tr>
<tr><th></th><td><input type="submit" value="Submit"></td></tr>
</tbody>
</table>
</form>
</div>
</div>
<div class="clear"></div>
<div class="centered_wrap"><div class="centered_text"><ul><li>Supported file types are: GIF, JPG, PNG.</li><li>Maximum file size allowed is 9000KB.</li><li>Images greater than 320x240 pixels will be thumbnailed.</li><li>This is a work-safe forum. Adult images are forbidden.</li></ul></div></div>
<div class="clear"></div>
<hr>
<form method="post">
<?php if ($id === NULL): ?>
<?php $try_posts = $dbh->query('SELECT * FROM forum WHERE multithread = post_id ORDER BY post_date_bumped DESC, post_id DESC'); ?>
<?php while ($row = $try_posts->fetchArray()): extract($row); unset($row); ?>
<div class="thread">
<a name="<?php echo $post_id; ?>"></a>
<input type="checkbox" name="delete[]" value="<?php echo $post_id; ?>"> <?php echo str_replace(array('&', '<', '>', "\n"), array('&amp;', '&lt;', '&gt;', ' '), $post_subject); ?> <b><?php echo (empty($post_author) && empty($post_author_name))?'anonymous':$post_author_name; ?></b> <u><?php echo $post_author?'!'.$post_author:''; ?></u> <?php echo $post_date_created; ?> #<?php echo $post_id; ?> [<a href="/<?php echo $forum; ?>/res/<?php echo $post_id; ?>.html">Reply</a>]
<?php if (isset($post_attach)): ?><?php foreach (explode(' ', $post_attach) as $i): ?><a href="/<?php echo $forum; ?>/src/<?php echo $i; ?>"><img src="/<?php echo $forum; ?>/thumb/<?php echo $i; ?>"></a><?php endforeach; ?><?php endif; ?>
<?php echo $post_body; ?>
<?php $try_thread = $dbh->query('SELECT * FROM forum WHERE multithread = "'.explode('.', $multithread)[0].'" OR multithread LIKE "'.explode('.', $multithread)[0].'.%" GROUP BY multithread ORDER BY multithread'); ?>
<?php while ($row = $try_thread->fetchArray()): extract($row); unset($row); ?>
<?php echo str_repeat('<div class="re">', substr_count($multithread, '.')); ?>Re: <a href="/<?php echo $forum; ?>/res/<?php echo $post_id; ?>.html"><?php echo str_replace(array('&', '<', '>', "\n"), array('&amp;', '&lt;', '&gt;', ' '), mb_substr($post_text, 0, 80, 'utf-8')).(mb_strlen($post_text, 'utf-8') > 80?' ...':''); ?></a><?php echo str_repeat('</div>', substr_count($multithread, '.')); ?>
<?php endwhile; ?>
</div>
<hr>
<?php endwhile; ?>
<?php elseif (isset($post_id)): ?>
<?php
$try = $dbh->prepare('SELECT * FROM forum WHERE multithread = :post_id OR multithread LIKE :post_id || ".%" OR multithread LIKE "%." || :post_id || ".%" OR multithread LIKE "%." || :post_id GROUP BY post_id ORDER BY post_id');
$try->bindValue(':post_id', $post_id);
$try_posts = $try->execute();
?>
<?php $try_thread = $dbh->query('SELECT * FROM forum WHERE multithread = "'.explode('.', $multithread)[0].'" OR multithread LIKE "'.explode('.', $multithread)[0].'.%" GROUP BY multithread ORDER BY multithread'); ?>
<?php while ($row = $try_thread->fetchArray()): extract($row); unset($row); ?>
<?php echo str_repeat('<div class="re">', substr_count($multithread, '.')); ?>Re: <a href="/<?php echo $forum; ?>/res/<?php echo $post_id; ?>.html"><?php echo str_replace(array('&', '<', '>', "\n"), array('&amp;', '&lt;', '&gt;', ' '), mb_substr($post_text, 0, 80, 'utf-8')).(mb_strlen($post_text, 'utf-8') > 80?' ...':''); ?></a><?php echo str_repeat('</div>', substr_count($multithread, '.')); ?>
<?php endwhile; ?>
<hr>
<?php while ($row = $try_posts->fetchArray()): extract($row); unset($row); ?>
<a name="<?php echo $post_id; ?>"></a>
<?php if ((@++$row_count === 1) && ($thread == $post_id)): ?>
<div class="thread">
<input type="checkbox" name="delete[]" value="<?php echo $post_id; ?>"> <?php echo str_replace(array('&', '<', '>', "\n"), array('&amp;', '&lt;', '&gt;', ' '), $post_subject); ?> <b><?php echo (empty($post_author) && empty($post_author_name))?'anonymous':$post_author_name; ?></b> <u><?php echo $post_author?'!'.$post_author:''; ?></u> <?php echo $post_date_created; ?> #<?php echo $post_id; ?> [<a href="/<?php echo $forum; ?>/res/<?php echo $post_id; ?>.html">Reply</a>]
<?php if (isset($post_attach)): ?><?php foreach (explode(' ', $post_attach) as $i): ?><a href="/<?php echo $forum; ?>/src/<?php echo $i; ?>"><img src="/<?php echo $forum; ?>/thumb/<?php echo $i; ?>"></a><?php endforeach; ?><?php endif; ?>
<?php echo $post_body; ?>
</div>
<?php else: ?>
<div class="post">
<input type="checkbox" name="delete[]" value="<?php echo $post_id; ?>"> <?php echo str_replace(array('&', '<', '>', "\n"), array('&amp;', '&lt;', '&gt;', ' '), $post_subject); ?> <b><?php echo (empty($post_author) && empty($post_author_name))?'anonymous':$post_author_name; ?></b> <u><?php echo $post_author?'!'.$post_author:''; ?></u> <?php echo $post_date_created; ?> #<?php echo $post_id; ?> [<a href="/<?php echo $forum; ?>/res/<?php echo $post_id; ?>.html">Reply</a>]
<?php if (isset($post_attach)): ?><?php foreach (explode(' ', $post_attach) as $i): ?><a href="/<?php echo $forum; ?>/src/<?php echo $i; ?>"><img src="/<?php echo $forum; ?>/thumb/<?php echo $i; ?>"></a><?php endforeach; ?><?php endif; ?>
<br>
<?php if ($row = $dbh->query('SELECT * FROM forum WHERE post_id = '.array_reverse(explode('.', $multithread))[1].'')->fetchArray()): ?>Re: <a href="/<?php echo $forum; ?>/res/<?php echo $row['post_id']; ?>.html"><?php echo str_replace(array('&', '<', '>', "\n"), array('&amp;', '&lt;', '&gt;', ' '), mb_substr($row['post_text'], 0, 80, 'utf-8')).(mb_strlen($row['post_text'], 'utf-8') > 80?' ...':''); ?></a><?php endif; ?>
<?php echo $post_body; ?>
</div>
<?php endif; ?>
<?php endwhile; ?>
<div class="clear"></div>
<hr>
<?php else: ?>
Thread ID not found.
<hr>
<?php endif; ?>
Password <input type="password" name="password"> [<input type="checkbox" name="fileonly"> File only] <input type="submit" value="Delete">
</form>
</div>
<!-- Yandex.Metrika counter --><script type="text/javascript">(function (d, w, c) { (w[c] = w[c] || []).push(function() { try { w.yaCounter30410527 = new Ya.Metrika({id:30410527, trackLinks:true, accurateTrackBounce:true}); } catch(e) { } }); var n = d.getElementsByTagName("script")[0], s = d.createElement("script"), f = function () { n.parentNode.insertBefore(s, n); }; s.type = "text/javascript"; s.async = true; s.src = (d.location.protocol == "https:" ? "https:" : "http:") + "//mc.yandex.ru/metrika/watch.js"; if (w.opera == "[object Opera]") { d.addEventListener("DOMContentLoaded", f, false); } else { f(); } })(document, window, "yandex_metrika_callbacks");</script><noscript><div><img src="//mc.yandex.ru/watch/30410527" style="position:absolute; left:-9999px;" alt="" /></div></noscript><!-- /Yandex.Metrika counter -->
</body>
</html>
<?php endif; ?>
<?php else: ?>
<?php header($_SERVER['SERVER_PROTOCOL'].' 404 Not Found'); die(); ?>
<?php endif; ?>
