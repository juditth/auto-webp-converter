<?php
// Run with: php tests/upload-failures.php (requires GD with WebP).
define('ABSPATH', __DIR__ . '/');
function add_action(...$a) {} function add_filter(...$a) {}
function plugin_basename($p) {return basename($p);} function plugin_dir_path($p) {return __DIR__.'/missing/';}
function absint($n) {return abs((int)$n);}
function get_option($k,$d=false) {return $GLOBALS['options'][$k]??$d;}
function wp_image_editor_supports($a) {return $GLOBALS['mode'] !== 'unsupported';}
class WP_Error {function get_error_message() {return 'Simulated failure';}}
function is_wp_error($v) {return $v instanceof WP_Error;}
function wp_get_image_editor($p) {return $GLOBALS['mode']==='load-error'?new WP_Error():new TestEditor();}
function wp_unique_filename($d,$n) {return $n;} function trailingslashit($v) {return rtrim($v,'/').'/';}
function wp_delete_file($p) {if (is_file($p)) unlink($p);}
class TestEditor {
    public $size=['width'=>8548,'height'=>5699];
    function get_size() {return $GLOBALS['mode']==='missing-size'?[]:$this->size;}
    function resize($w,$h,$c) {
        if ($GLOBALS['mode']==='resize-error') return new WP_Error();
        if ($GLOBALS['mode']!=='unchanged') $this->size=['width'=>$w,'height'=>(int)($w*2/3)];
        return true;
    }
    function set_quality($q) {return $GLOBALS['mode']==='quality-error'?new WP_Error():true;}
    function save($p,$m) {
        if ($GLOBALS['mode']==='save-error') {file_put_contents($p,'partial'); return new WP_Error();}
        if ($GLOBALS['mode']==='invalid-output') {file_put_contents($p,'invalid'); return ['path'=>$p];}
        $image=imagecreatetruecolor($GLOBALS['mode']==='oversized-output'?2400:23,16);
        imagewebp($image,$p); imagedestroy($image);
        return ['path'=>$p];
    }
}
require dirname(__DIR__).'/auto-webp-converter.php';
$plugin=new Auto_WebP_Converter();
$dir=sys_get_temp_dir().'/awc-test-'.bin2hex(random_bytes(8));
mkdir($dir);
try {
    foreach (['unsupported','load-error','missing-size','resize-error','unchanged','quality-error','save-error','invalid-output','oversized-output','success','invalid-settings'] as $mode) {
        $GLOBALS['mode']=$mode;
        $GLOBALS['options']=$mode==='invalid-settings'?['awc_max_width'=>0,'awc_max_height'=>0]:[];
        $path=$dir.'/test.jpg'; $output=$dir.'/test.webp';
        file_put_contents($path,'isolated test upload');
        $result=$plugin->handle_upload(['file'=>$path,'url'=>'https://example.test/uploads/test.jpg','type'=>'image/jpeg']);
        $success=in_array($mode,['success','invalid-settings'],true);
        clearstatcache();
        if ($success) {
            if (($result['type']??'')!=='image/webp'||!is_file($output)||is_file($path)) throw new Exception($mode);
        } elseif (empty($result['error'])||isset($result['file'])||is_file($path)||is_file($output)) {
            throw new Exception($mode);
        }
        wp_delete_file($output);
        echo 'PASS '.$mode.PHP_EOL;
    }
    $prior=['error'=>'Existing upload error'];
    if ($plugin->handle_upload($prior)!==$prior) throw new Exception('Existing error changed');
    echo 'PASS existing upload error'.PHP_EOL;
} finally {
    foreach (glob($dir.'/*') as $file) unlink($file);
    rmdir($dir);
}
