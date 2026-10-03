<?php
/**
 * Plugin Name: Lucas Chấm Công
 * Description: Nhân viên tự điền ca làm tại trang /cham-cong (đăng nhập bằng mã PIN). Tool tính lương đầu tháng đọc ca qua REST, đẩy phiếu lương lên để chủ shop duyệt; duyệt xong nhân viên mới xem được phiếu của mình.
 * Version: 1.2.1
 * Update URI: https://github.com/thanhluc102-png/lucas-cham-cong
 * Author: Lucas Combo
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('LCC_VER', '1.2.1');
define('LCC_REPO', 'thanhluc102-png/lucas-cham-cong');   // nơi plugin tự lấy bản cập nhật
// Quy tắc tự xác định ca từ giờ chấm công (đã chốt với chủ shop): trễ <= 15' vẫn đủ ca
define('LCC_GRACE_MIN', 15);
// Khoá cho tool tính lương: chỉ nằm trong lcc-config.php của bản cài đầu (không lên GitHub), lưu vào DB khi kích hoạt
if (file_exists(__DIR__ . '/lcc-config.php')) require_once __DIR__ . '/lcc-config.php';
define('LCC_TYPES', ['std', 'pm', 'full', 'half', 'off', 'leave']);   // xem SHIFT trong assets/app.js

function lcc_t($n) { global $wpdb; return $wpdb->prefix . 'lcc_' . $n; }

/* ------------------------------------------------------------------ cài đặt */
register_activation_hook(__FILE__, 'lcc_activate');
function lcc_activate() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE " . lcc_t('emp') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(40) NOT NULL,
  name varchar(120) NOT NULL,
  role varchar(20) NOT NULL DEFAULT 'staff',
  pin_hash varchar(255) NOT NULL DEFAULT '',
  active tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code)
) $c;");
    dbDelta("CREATE TABLE " . lcc_t('shift') . " (
  emp_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  type varchar(20) NOT NULL,
  extra_hours decimal(4,1) NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  src varchar(10) NOT NULL DEFAULT 'self',
  updated datetime NOT NULL,
  PRIMARY KEY  (emp_id,day)
) $c;");
    dbDelta("CREATE TABLE " . lcc_t('punch') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  emp_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  in_at datetime NOT NULL,
  out_at datetime DEFAULT NULL,
  ip varchar(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY emp_day (emp_id,day)
) $c;");
    dbDelta("CREATE TABLE " . lcc_t('adj') . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  emp_id bigint(20) unsigned NOT NULL,
  month char(7) NOT NULL,
  kind varchar(20) NOT NULL,
  amount bigint(20) NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY emp_month (emp_id,month)
) $c;");
    dbDelta("CREATE TABLE " . lcc_t('slip') . " (
  emp_id bigint(20) unsigned NOT NULL,
  month char(7) NOT NULL,
  data longtext NOT NULL,
  approved tinyint(1) NOT NULL DEFAULT 0,
  updated datetime NOT NULL,
  PRIMARY KEY  (emp_id,month)
) $c;");
    if (!get_option('lcc_api_key') && defined('LCC_INIT_KEY')) update_option('lcc_api_key', LCC_INIT_KEY, false);
    if (!get_page_by_path('cham-cong')) {
        wp_insert_post(['post_title' => 'Chấm công', 'post_name' => 'cham-cong', 'post_type' => 'page',
                        'post_status' => 'publish', 'post_content' => '[lucas_cham_cong]']);
    }
}

add_action('plugins_loaded', function () {
    if (get_option('lcc_db_ver') !== LCC_VER) { lcc_activate(); update_option('lcc_db_ver', LCC_VER, false); }
});

/* ------------------------------------------------------------------ tự cập nhật từ GitHub
 * Mỗi bản mới = 1 GitHub Release (tag vX.Y.Z) kèm file lucas-cham-cong.zip. WordPress thấy "Có bản cập nhật"
 * như plugin thường; bật "Tự động cập nhật" ở dòng plugin là web tự lên bản mới, không phải tải file tay. */
function lcc_latest_release($fresh = false) {
    $c = $fresh ? false : get_site_transient('lcc_gh_release');
    if ($c !== false) return $c ?: null;
    $r = wp_remote_get('https://api.github.com/repos/' . LCC_REPO . '/releases/latest', ['timeout' => 10,
        'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'lucas-cham-cong']]);
    $rel = null;
    if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) == 200) {
        $j = json_decode(wp_remote_retrieve_body($r), true);
        foreach ($j['assets'] ?? [] as $a) {
            if ($a['name'] === 'lucas-cham-cong.zip')
                $rel = ['ver' => ltrim($j['tag_name'], 'v'), 'zip' => $a['browser_download_url'],
                        'url' => $j['html_url'], 'notes' => (string) ($j['body'] ?? '')];
        }
    }
    set_site_transient('lcc_gh_release', $rel ?: 0, 6 * HOUR_IN_SECONDS);
    return $rel;
}
add_filter('pre_set_site_transient_update_plugins', function ($t) {
    if (!is_object($t) || empty($t->checked)) return $t;
    $f = plugin_basename(__FILE__);
    $rel = lcc_latest_release();
    $item = (object) ['id' => LCC_REPO, 'slug' => 'lucas-cham-cong', 'plugin' => $f, 'url' => 'https://github.com/' . LCC_REPO,
                      'new_version' => LCC_VER, 'package' => ''];
    if ($rel && version_compare($rel['ver'], LCC_VER, '>')) {
        $item->new_version = $rel['ver'];
        $item->package = $rel['zip'];
        $t->response[$f] = $item;
    } else {
        unset($t->response[$f]);
        $t->no_update[$f] = $item;          // có mục này WordPress mới hiện nút "Bật tự động cập nhật"
    }
    return $t;
});
// Tự tải gói cập nhật (theo redirect GitHub -> CDN). Hàm tải mặc định của WordPress từ chối URL khi máy chủ
// không phân giải được tên miền lúc kiểm tra an toàn -> báo "A valid URL was not provided".
add_filter('upgrader_pre_download', function ($reply, $package) {
    if ($reply !== false || strpos((string) $package, 'github.com/' . LCC_REPO . '/releases/download/') === false) return $reply;
    $tmp = wp_tempnam($package);
    $r = wp_remote_get($package, ['timeout' => 120, 'stream' => true, 'filename' => $tmp, 'redirection' => 5]);
    if (is_wp_error($r) || wp_remote_retrieve_response_code($r) != 200) {
        @unlink($tmp);
        return new WP_Error('lcc_download', 'Không tải được bản cập nhật từ GitHub: ' .
            (is_wp_error($r) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($r)));
    }
    return $tmp;
}, 10, 2);
add_filter('plugins_api', function ($res, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'lucas-cham-cong') return $res;
    $rel = lcc_latest_release();
    return (object) ['name' => 'Lucas Chấm Công', 'slug' => 'lucas-cham-cong', 'version' => $rel['ver'] ?? LCC_VER,
                     'author' => 'Lucas Combo', 'homepage' => 'https://github.com/' . LCC_REPO,
                     'sections' => ['changelog' => nl2br(esc_html($rel['notes'] ?? ''))]];
}, 10, 3);

/* ------------------------------------------------------------------ wifi tiệm + chấm công */
function lcc_ip() { return (string) ($_SERVER['REMOTE_ADDR'] ?? ''); }   // lucas.vn không qua proxy/CDN
function lcc_on_wifi() { return in_array(lcc_ip(), (array) get_option('lcc_shop_ips', []), true); }
// Luôn giờ Việt Nam, không phụ thuộc cài đặt múi giờ của WordPress (đổi nhầm là tính ca sai cả tháng)
function lcc_tz() { return new DateTimeZone('Asia/Ho_Chi_Minh'); }
function lcc_now() { return new DateTime('now', lcc_tz()); }

/** Tính lại loại ca của 1 ngày từ các lần vào/ra (chỉ đè ca tự điền/tự động, không đè ca admin sửa). */
function lcc_recompute_day($emp_id, $day) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT in_at,out_at FROM " . lcc_t('punch') .
                                              " WHERE emp_id=%d AND day=%s ORDER BY in_at", $emp_id, $day));
    if (!$rows) return;
    $cur = $wpdb->get_row($wpdb->prepare("SELECT src FROM " . lcc_t('shift') . " WHERE emp_id=%d AND day=%s", $emp_id, $day));
    if ($cur && $cur->src === 'admin') return;
    $tz = lcc_tz();
    $first = new DateTime($rows[0]->in_at, $tz);
    $open = false; $mins = 0; $last = null;
    foreach ($rows as $r) {
        if (!$r->out_at) { $open = true; continue; }
        $a = new DateTime($r->in_at, $tz); $b = new DateTime($r->out_at, $tz);
        $mins += ($b->getTimestamp() - $a->getTimestamp()) / 60;
        if (!$last || $b > $last) $last = $b;
    }
    $hm = function ($d) { return $d ? $d->format('H:i') : '?'; };
    $at = function ($hhmm) use ($day, $tz) { return new DateTime($day . ' ' . $hhmm, $tz); };
    $extra = 0;
    if ($open && !$last) {
        $type = 'half'; $note = 'Vào ' . $hm($first) . ' — CHƯA RA CA (tạm tính nửa buổi)';
    } else {
        $g = LCC_GRACE_MIN;
        if ($first <= $at('09:00')->modify("+$g minutes") && $last >= $at('20:00')->modify("-$g minutes")) $type = 'full';
        elseif ($mins >= 7 * 60) $type = $first < $at('11:00') ? 'std' : 'pm';
        elseif ($mins >= 150) $type = 'half';
        else $type = 'off';
        if ($last > $at('20:30')) $extra = floor(($last->getTimestamp() - $at('20:00')->getTimestamp()) / 1800) / 2;
        $start = $type === 'pm' ? '12:00' : '09:00';
        $late = max(0, round(($first->getTimestamp() - $at($start)->getTimestamp()) / 60));
        $note = 'Wifi ' . $hm($first) . '–' . $hm($last) . ' (' . round($mins / 60, 1) . 'h)' .
                ($late > $g ? ' · trễ ' . $late . "'" : '') . ($open ? ' · còn 1 lượt chưa ra ca' : '') .
                ($type === 'off' ? ' · dưới 2,5h không tính công' : '');
    }
    $wpdb->replace(lcc_t('shift'), ['emp_id' => $emp_id, 'day' => $day, 'type' => $type, 'extra_hours' => $extra,
                                   'note' => $note, 'src' => 'punch', 'updated' => current_time('mysql')]);
}

/* ------------------------------------------------------------------ trang nhân viên */
add_shortcode('lucas_cham_cong', function () {
    wp_enqueue_style('lcc', plugins_url('assets/app.css', __FILE__), [], LCC_VER);
    wp_enqueue_script('lcc', plugins_url('assets/app.js', __FILE__), [], LCC_VER, true);
    wp_localize_script('lcc', 'LCC', ['api' => esc_url_raw(rest_url('lcc/v1/'))]);
    return '<div id="lcc-app"><div class="lcc-loading">Đang tải…</div></div>';
});
// Trang chấm công không cho máy tìm kiếm / bộ nhớ đệm giữ lại
add_action('wp_head', function () {
    if (is_page('cham-cong')) echo '<meta name="robots" content="noindex,nofollow">';
});

/* ------------------------------------------------------------------ REST */
function lcc_emp_by_token(WP_REST_Request $r) {
    $tok = $r->get_header('x-lcc-token');
    if (!$tok) return null;
    $id = get_transient('lcc_s_' . hash('sha256', $tok));
    if (!$id) return null;
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT id,code,name,role FROM " . lcc_t('emp') . " WHERE id=%d AND active=1", $id));
}
function lcc_is_admin(WP_REST_Request $r) {
    $k = $r->get_header('x-lcc-key');
    return $k && hash_equals((string) get_option('lcc_api_key'), $k);
}
function lcc_month_locked($emp_id, $month) {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT approved FROM " . lcc_t('slip') . " WHERE emp_id=%d AND month=%s", $emp_id, $month));
}
function lcc_err($msg, $code = 400) { return new WP_REST_Response(['error' => $msg], $code); }

add_action('rest_api_init', function () {
    $ns = 'lcc/v1';
    $pub = ['permission_callback' => '__return_true'];

    // Danh sách tên để chọn khi đăng nhập (không lộ gì khác)
    register_rest_route($ns, '/staff', $pub + ['methods' => 'GET', 'callback' => function () {
        global $wpdb;
        return $wpdb->get_results("SELECT code,name FROM " . lcc_t('emp') . " WHERE active=1 ORDER BY name");
    }]);

    register_rest_route($ns, '/login', $pub + ['methods' => 'POST', 'callback' => function (WP_REST_Request $r) {
        global $wpdb;
        // Khoá theo (mạng + tên): cả shop chung wifi, 1 bạn gõ sai không được khoá luôn người khác
        $ipk = 'lcc_fail_' . md5(($_SERVER['REMOTE_ADDR'] ?? '') . '|' . sanitize_text_field($r['code']));
        if ((int) get_transient($ipk) >= 6) return lcc_err('Sai PIN quá nhiều lần, thử lại sau 15 phút.', 429);
        $e = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . lcc_t('emp') . " WHERE code=%s AND active=1",
                                           sanitize_text_field($r['code'])));
        if (!$e || !$e->pin_hash || !wp_check_password((string) $r['pin'], $e->pin_hash)) {
            set_transient($ipk, (int) get_transient($ipk) + 1, 15 * MINUTE_IN_SECONDS);
            return lcc_err('Sai tên hoặc mã PIN.', 401);
        }
        delete_transient($ipk);
        $tok = wp_generate_password(48, false);
        set_transient('lcc_s_' . hash('sha256', $tok), $e->id, 60 * DAY_IN_SECONDS);
        return ['token' => $tok, 'name' => $e->name, 'role' => $e->role];
    }]);

    register_rest_route($ns, '/me', $pub + ['methods' => 'GET', 'callback' => function (WP_REST_Request $r) {
        global $wpdb;
        $e = lcc_emp_by_token($r);
        if (!$e) return lcc_err('Phiên đăng nhập hết hạn, đăng nhập lại.', 401);
        $m = preg_match('/^\d{4}-\d{2}$/', (string) $r['month']) ? $r['month'] : lcc_now()->format('Y-m');
        $shifts = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(day,'%%Y-%%m-%%d') day,type,extra_hours+0 extra_hours,note,src FROM " . lcc_t('shift') .
            " WHERE emp_id=%d AND day LIKE %s ORDER BY day", $e->id, $m . '-%'));
        $slip = $wpdb->get_row($wpdb->prepare("SELECT data,approved FROM " . lcc_t('slip') .
                                              " WHERE emp_id=%d AND month=%s", $e->id, $m));
        $punch = $wpdb->get_results($wpdb->prepare("SELECT DATE_FORMAT(day,'%%Y-%%m-%%d') day,DATE_FORMAT(in_at,'%%H:%%i') in_t,DATE_FORMAT(out_at,'%%H:%%i') out_t FROM " .
            lcc_t('punch') . " WHERE emp_id=%d AND day LIKE %s ORDER BY in_at", $e->id, $m . '-%'));
        $open = $wpdb->get_var($wpdb->prepare("SELECT DATE_FORMAT(in_at,'%%H:%%i') FROM " . lcc_t('punch') .
            " WHERE emp_id=%d AND out_at IS NULL AND in_at > %s", $e->id, lcc_now()->modify('-16 hours')->format('Y-m-d H:i:s')));
        return ['emp' => ['name' => $e->name, 'role' => $e->role], 'month' => $m, 'shifts' => $shifts,
                'punches' => $punch, 'open_since' => $open, 'on_wifi' => lcc_on_wifi(), 'wifi_set' => (bool) get_option('lcc_shop_ips'),
                'locked' => $slip && $slip->approved, 'payslip' => ($slip && $slip->approved) ? json_decode($slip->data) : null];
    }]);

    register_rest_route($ns, '/shift', $pub + ['methods' => 'POST', 'callback' => function (WP_REST_Request $r) {
        global $wpdb;
        $e = lcc_emp_by_token($r);
        if (!$e) return lcc_err('Phiên đăng nhập hết hạn, đăng nhập lại.', 401);
        $day = (string) $r['day'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return lcc_err('Ngày không hợp lệ.');
        $today = lcc_now()->format('Y-m-d');
        if ($day > date('Y-m-d', strtotime($today . ' +45 days'))) return lcc_err('Chỉ điền trước tối đa 45 ngày.');
        if ($day < date('Y-m-01', strtotime($today . ' -1 month'))) return lcc_err('Không sửa được tháng cũ.');
        if (lcc_month_locked($e->id, substr($day, 0, 7))) return lcc_err('Tháng này đã chốt lương, không sửa được nữa.');
        $type = (string) $r['type'];
        $cur = $wpdb->get_row($wpdb->prepare("SELECT src FROM " . lcc_t('shift') . " WHERE emp_id=%d AND day=%s", $e->id, $day));
        if ($cur && in_array($cur->src, ['punch', 'admin'], true))
            return lcc_err('Ngày này đã có chấm công wifi / quản lý đã chốt — nhờ quản lý sửa nếu sai.');
        if (!in_array($type, ['off', 'leave', 'clear'], true))
            return lcc_err('Ngày đi làm phải bấm Vào ca / Ra ca bằng wifi tiệm. Tự điền chỉ được Nghỉ hoặc Nghỉ phép.');
        if ($type === 'clear') {
            $wpdb->delete(lcc_t('shift'), ['emp_id' => $e->id, 'day' => $day]);
            return ['ok' => true];
        }
        if (!in_array($type, LCC_TYPES, true)) return lcc_err('Loại ca không hợp lệ.');
        $extra = max(0, min(12, round((float) $r['extra_hours'] * 2) / 2));
        $wpdb->replace(lcc_t('shift'), ['emp_id' => $e->id, 'day' => $day, 'type' => $type, 'extra_hours' => 0,
                                       'note' => mb_substr(sanitize_text_field((string) $r['note']), 0, 250),
                                       'src' => 'self', 'updated' => current_time('mysql')]);
        return ['ok' => true];
    }]);

    register_rest_route($ns, '/punch', $pub + ['methods' => 'POST', 'callback' => function (WP_REST_Request $r) {
        global $wpdb;
        $e = lcc_emp_by_token($r);
        if (!$e) return lcc_err('Phiên đăng nhập hết hạn, đăng nhập lại.', 401);
        if (!get_option('lcc_shop_ips')) return lcc_err('Quản lý chưa cài wifi tiệm cho chấm công.');
        if (!lcc_on_wifi()) {
            set_transient('lcc_badwifi_' . md5(lcc_ip()), lcc_ip(), DAY_IN_SECONDS);   // để quản lý thấy nếu wifi tiệm đổi IP
            return lcc_err('Bạn không ở wifi tiệm (đang dùng 4G/5G hoặc mạng khác). Bật wifi tiệm rồi bấm lại.', 403);
        }
        $now = lcc_now(); $nows = $now->format('Y-m-d H:i:s');
        $open = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . lcc_t('punch') . " WHERE emp_id=%d AND out_at IS NULL AND in_at > %s ORDER BY in_at DESC",
                                              $e->id, (clone $now)->modify('-16 hours')->format('Y-m-d H:i:s')));
        if ($r['action'] === 'in') {
            if ($open) return lcc_err('Bạn đã vào ca lúc ' . substr($open->in_at, 11, 5) . ', chưa ra ca.');
            $day = $now->format('Y-m-d');
            if (lcc_month_locked($e->id, substr($day, 0, 7))) return lcc_err('Tháng này đã chốt lương.');
            $wpdb->insert(lcc_t('punch'), ['emp_id' => $e->id, 'day' => $day, 'in_at' => $nows, 'ip' => lcc_ip()]);
            lcc_recompute_day($e->id, $day);
            return ['ok' => true, 'msg' => 'Đã vào ca lúc ' . $now->format('H:i')];
        }
        if ($r['action'] === 'out') {
            if (!$open) return lcc_err('Bạn chưa vào ca.');
            $wpdb->update(lcc_t('punch'), ['out_at' => $nows], ['id' => $open->id]);
            lcc_recompute_day($e->id, $open->day);
            return ['ok' => true, 'msg' => 'Đã ra ca lúc ' . $now->format('H:i')];
        }
        return lcc_err('action = in | out');
    }]);

    /* --- dành cho tool tính lương (khoá API) --- */
    $adm = ['permission_callback' => 'lcc_is_admin'];

    register_rest_route($ns, '/admin/month', $adm + ['methods' => 'GET', 'callback' => function (WP_REST_Request $r) {
        global $wpdb;
        $m = (string) $r['month'];
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) return lcc_err('month=YYYY-MM');
        return [
            'employees' => $wpdb->get_results("SELECT id,code,name,role,active,(pin_hash<>'') has_pin FROM " . lcc_t('emp')),
            'punches' => $wpdb->get_results($wpdb->prepare("SELECT e.code,DATE_FORMAT(p.day,'%%Y-%%m-%%d') day,p.in_at,p.out_at FROM " .
                lcc_t('punch') . " p JOIN " . lcc_t('emp') . " e ON e.id=p.emp_id WHERE p.day LIKE %s ORDER BY p.in_at", $m . '-%')),
            'shifts' => $wpdb->get_results($wpdb->prepare("SELECT e.code,DATE_FORMAT(s.day,'%%Y-%%m-%%d') day,s.type,s.extra_hours+0 extra_hours,s.note,s.src FROM " .
                lcc_t('shift') . " s JOIN " . lcc_t('emp') . " e ON e.id=s.emp_id WHERE s.day LIKE %s ORDER BY s.day", $m . '-%')),
            'adjustments' => $wpdb->get_results($wpdb->prepare("SELECT e.code,a.kind,a.amount,a.note FROM " .
                lcc_t('adj') . " a JOIN " . lcc_t('emp') . " e ON e.id=a.emp_id WHERE a.month=%s", $m)),
            'slips' => $wpdb->get_results($wpdb->prepare("SELECT e.code,s.approved FROM " . lcc_t('slip') . " s JOIN " .
                lcc_t('emp') . " e ON e.id=s.emp_id WHERE s.month=%s", $m)),
        ];
    }]);

    register_rest_route($ns, '/admin/employee', $adm + ['methods' => 'POST', 'callback' => function (WP_REST_Request $r) {
        global $wpdb;
        $code = sanitize_key($r['code']);
        if (!$code || !$r['name']) return lcc_err('Cần code và name.');
        $row = ['code' => $code, 'name' => sanitize_text_field($r['name']),
                'role' => in_array($r['role'], ['staff', 'intern'], true) ? $r['role'] : 'staff',
                'active' => isset($r['active']) ? (int) (bool) $r['active'] : 1];
        if ($r['pin']) $row['pin_hash'] = wp_hash_password((string) $r['pin']);
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . lcc_t('emp') . " WHERE code=%s", $code));
        $id ? $wpdb->update(lcc_t('emp'), $row, ['id' => $id]) : $wpdb->insert(lcc_t('emp'), $row);
        return ['ok' => true];
    }]);

    register_rest_route($ns, '/admin/slips', $adm + ['methods' => 'POST', 'callback' => function (WP_REST_Request $r) {
        global $wpdb;
        $m = (string) $r['month'];
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) return lcc_err('month=YYYY-MM');
        $n = 0;
        foreach ((array) $r['slips'] as $s) {
            $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . lcc_t('emp') . " WHERE code=%s", $s['code']));
            if (!$id || lcc_month_locked($id, $m)) continue;              // đã duyệt thì không ghi đè
            $wpdb->replace(lcc_t('slip'), ['emp_id' => $id, 'month' => $m, 'data' => wp_json_encode($s['data']),
                                          'approved' => 0, 'updated' => current_time('mysql')]);
            $n++;
        }
        return ['ok' => true, 'n' => $n];
    }]);
});

/* ------------------------------------------------------------------ wp-admin */
add_action('admin_menu', function () {
    add_menu_page('Chấm công', 'Chấm công', 'manage_options', 'lcc', 'lcc_admin_page', 'dashicons-calendar-alt', 58);
});

add_action('admin_post_lcc_save', function () {
    if (!current_user_can('manage_options')) wp_die('Không có quyền');
    check_admin_referer('lcc_save');
    global $wpdb;
    $m = preg_match('/^\d{4}-\d{2}$/', $_POST['month'] ?? '') ? $_POST['month'] : lcc_now()->format('Y-m');
    $act = $_POST['act'] ?? '';
    if ($act === 'emp') {
        $code = sanitize_key($_POST['code'] ?? '');
        $row = ['code' => $code, 'name' => sanitize_text_field($_POST['name'] ?? ''),
                'role' => ($_POST['role'] ?? '') === 'intern' ? 'intern' : 'staff', 'active' => empty($_POST['inactive']) ? 1 : 0];
        if (!empty($_POST['pin'])) $row['pin_hash'] = wp_hash_password(sanitize_text_field($_POST['pin']));
        if ($code && $row['name']) {
            $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . lcc_t('emp') . " WHERE code=%s", $code));
            $id ? $wpdb->update(lcc_t('emp'), $row, ['id' => $id]) : $wpdb->insert(lcc_t('emp'), $row);
        }
    } elseif ($act === 'pin' && !empty($_POST['pin'])) {
        $wpdb->update(lcc_t('emp'), ['pin_hash' => wp_hash_password(sanitize_text_field($_POST['pin']))], ['id' => (int) $_POST['id']]);
    } elseif ($act === 'toggle') {
        $wpdb->query($wpdb->prepare("UPDATE " . lcc_t('emp') . " SET active=1-active WHERE id=%d", (int) $_POST['id']));
    } elseif ($act === 'check_update') {
        delete_site_transient('lcc_gh_release');
        delete_site_transient('update_plugins');
        wp_update_plugins();
        wp_redirect(admin_url('plugins.php?plugin_status=upgrade'));
        exit;
    } elseif ($act === 'wifi_add') {
        $ips = (array) get_option('lcc_shop_ips', []);
        $ip = !empty($_POST['ip']) ? sanitize_text_field($_POST['ip']) : lcc_ip();
        if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $ips, true)) $ips[] = $ip;
        update_option('lcc_shop_ips', array_values($ips), false);
    } elseif ($act === 'wifi_del') {
        update_option('lcc_shop_ips', array_values(array_diff((array) get_option('lcc_shop_ips', []), [$_POST['ip'] ?? ''])), false);
    } elseif ($act === 'day') {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['day'] ?? '') ? $_POST['day'] : '';
        $type = $_POST['type'] ?? '';
        if ($day && $type === 'auto') {                  // trả về tính theo chấm công wifi
            $wpdb->delete(lcc_t('shift'), ['emp_id' => (int) $_POST['emp_id'], 'day' => $day]);
            lcc_recompute_day((int) $_POST['emp_id'], $day);
        } elseif ($day && in_array($type, LCC_TYPES, true)) {
            $wpdb->replace(lcc_t('shift'), ['emp_id' => (int) $_POST['emp_id'], 'day' => $day, 'type' => $type,
                'extra_hours' => max(0, min(12, (float) ($_POST['extra'] ?? 0))),
                'note' => 'Quản lý sửa: ' . sanitize_text_field($_POST['note'] ?? ''), 'src' => 'admin', 'updated' => current_time('mysql')]);
        }
    } elseif ($act === 'adj_add') {
        $wpdb->insert(lcc_t('adj'), ['emp_id' => (int) $_POST['emp_id'], 'month' => $m,
            'kind' => in_array($_POST['kind'], ['bonus', 'advance', 'deduct'], true) ? $_POST['kind'] : 'bonus',
            'amount' => (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0'),
            'note' => sanitize_text_field($_POST['note'] ?? '')]);
    } elseif ($act === 'adj_del') {
        $wpdb->delete(lcc_t('adj'), ['id' => (int) $_POST['id']]);
    } elseif ($act === 'approve') {
        $wpdb->update(lcc_t('slip'), ['approved' => 1], ['month' => $m]);
    } elseif ($act === 'unapprove') {
        $wpdb->update(lcc_t('slip'), ['approved' => 0], ['month' => $m]);
    }
    wp_redirect(admin_url('admin.php?page=lcc&month=' . $m . '&done=1'));
    exit;
});

function lcc_money($n) { return number_format((float) $n, 0, ',', '.') . '₫'; }

function lcc_admin_page() {
    global $wpdb;
    $m = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m', strtotime(lcc_now()->format('Y-m-01') . ' -1 month'));
    $emps = $wpdb->get_results("SELECT * FROM " . lcc_t('emp') . " ORDER BY active DESC, role, name");
    $adj = $wpdb->get_results($wpdb->prepare("SELECT a.*,e.name FROM " . lcc_t('adj') . " a JOIN " . lcc_t('emp') .
                                             " e ON e.id=a.emp_id WHERE a.month=%s ORDER BY e.name", $m));
    $slips = $wpdb->get_results($wpdb->prepare("SELECT s.*,e.name FROM " . lcc_t('slip') . " s JOIN " . lcc_t('emp') .
                                               " e ON e.id=s.emp_id WHERE s.month=%s ORDER BY e.name", $m));
    $cnt = $wpdb->get_results($wpdb->prepare("SELECT emp_id,COUNT(*) n FROM " . lcc_t('shift') . " WHERE day LIKE %s GROUP BY emp_id", $m . '-%'), OBJECT_K);
    $kinds = ['bonus' => 'Thưởng', 'advance' => 'Đã ứng', 'deduct' => 'Khấu trừ (mua hàng, phạt…)'];
    $form = function ($act, $inner, $btn, $cls = 'button') use ($m) {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' .
            wp_nonce_field('lcc_save', '_wpnonce', true, false) . '<input type="hidden" name="action" value="lcc_save">' .
            '<input type="hidden" name="act" value="' . $act . '"><input type="hidden" name="month" value="' . esc_attr($m) . '">' .
            $inner . ' <button class="' . $cls . '">' . $btn . '</button></form>';
    };
    echo '<div class="wrap"><h1>Chấm công &amp; lương</h1>';
    echo '<form method="get"><input type="hidden" name="page" value="lcc">Tháng <input type="month" name="month" value="' . esc_attr($m) . '"> <button class="button">Xem</button></form>';
    echo '<p>Trang nhân viên điền ca: <a href="' . esc_url(home_url('/cham-cong/')) . '" target="_blank">' . esc_html(home_url('/cham-cong/')) . '</a></p>';

    echo '<h2>1. Phiếu lương tháng ' . esc_html($m) . '</h2>';
    if (!$slips) echo '<p><i>Chưa có — tool tính lương sẽ đẩy lên đầu tháng sau.</i></p>';
    else {
        $all = !in_array('0', array_map(fn($s) => (string) $s->approved, $slips), true);
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Nhân viên</th><th>Thực nhận</th><th>Còn chuyển</th><th>Trạng thái</th></tr></thead><tbody>';
        foreach ($slips as $s) {
            $d = json_decode($s->data, true);
            echo '<tr><td>' . esc_html($s->name) . '</td><td>' . lcc_money($d['net'] ?? 0) . '</td><td><b>' . lcc_money($d['remain'] ?? 0) .
                 '</b></td><td>' . ($s->approved ? '✅ đã duyệt' : '⏳ chờ duyệt') . '</td></tr>';
        }
        echo '</tbody></table><p>';
        echo $all ? $form('unapprove', '', 'Bỏ duyệt (cho tool tính lại)') :
                    $form('approve', '', '✓ Duyệt tất cả — nhân viên sẽ thấy phiếu lương của mình', 'button button-primary');
        echo '</p>';
    }

    echo '<h2>2. Thưởng / tạm ứng / khấu trừ tháng ' . esc_html($m) . '</h2><p>Nhập trước khi tool chạy (ngày 1 hằng tháng). Thưởng content, ứng lương, mua hàng trừ lương… đều nhập ở đây.</p>';
    echo '<table class="widefat striped" style="max-width:900px"><tbody>';
    foreach ($adj as $a) echo '<tr><td>' . esc_html($a->name) . '</td><td>' . esc_html($kinds[$a->kind] ?? $a->kind) . '</td><td>' .
        lcc_money($a->amount) . '</td><td>' . esc_html($a->note) . '</td><td>' . $form('adj_del', '<input type="hidden" name="id" value="' . (int) $a->id . '">', 'Xoá') . '</td></tr>';
    echo '</tbody></table><p>';
    $sel = '<select name="emp_id">';
    foreach ($emps as $e) if ($e->active) $sel .= '<option value="' . (int) $e->id . '">' . esc_html($e->name) . '</option>';
    $sel .= '</select> <select name="kind">';
    foreach ($kinds as $k => $v) $sel .= '<option value="' . $k . '">' . $v . '</option>';
    $sel .= '</select> <input name="amount" placeholder="Số tiền" size="12"> <input name="note" placeholder="Ghi chú (vd: content TikTok Shop)" size="34">';
    echo $form('adj_add', $sel, 'Thêm', 'button button-primary') . '</p>';

    // --- wifi tiệm
    $ips = (array) get_option('lcc_shop_ips', []);
    echo '<h2>Wifi tiệm (chấm công)</h2><p>Mạng bạn đang dùng: <code>' . esc_html(lcc_ip()) . '</code> ' .
         (in_array(lcc_ip(), $ips, true) ? '✅ là wifi tiệm' : '') . '</p><p>Wifi tiệm đã đặt: ' .
         ($ips ? implode(' ', array_map(fn($i) => '<code>' . esc_html($i) . '</code> ' . $form('wifi_del', '<input type="hidden" name="ip" value="' . esc_attr($i) . '">', 'Xoá'), $ips)) : '<b style="color:#b32d2e">chưa có — nhân viên chưa chấm công được</b>') . '</p><p>' .
         $form('wifi_add', '', '📶 Đặt wifi tiệm = mạng tôi đang dùng', 'button button-primary') . ' <span style="color:#666">(bấm khi đang ở tiệm, bắt wifi tiệm; nhà mạng đổi IP thì bấm lại)</span></p>';
    $bad = $wpdb->get_col("SELECT option_value FROM $wpdb->options WHERE option_name LIKE '_transient_lcc_badwifi_%' LIMIT 8");
    if ($bad) echo '<p style="color:#b32d2e">Trong 24h có người bấm chấm công từ mạng lạ: ' . esc_html(implode(', ', $bad)) .
                   ' — nếu đó là wifi tiệm (nhà mạng vừa đổi IP) thì thêm: ' . $form('wifi_add', '<input name="ip" placeholder="IP" size="16">', 'Thêm IP') . '</p>';

    // --- sửa ca khi quên chấm công
    $tsel = '<select name="type"><option value="auto">↺ Tính lại theo chấm công wifi</option>';
    foreach (['std' => 'Ca 9h–18h', 'pm' => 'Ca 12h–20h', 'full' => 'Full 9h–20h', 'half' => 'Nửa buổi', 'leave' => 'Nghỉ phép', 'off' => 'Nghỉ'] as $k => $v)
        $tsel .= '<option value="' . $k . '">' . $v . '</option>';
    echo '<h2>Sửa ca (quên chấm công, sai giờ…)</h2><p>' . $form('day', str_replace('name="emp_id"', 'name="emp_id"', explode(' <select name="kind">', $sel)[0]) .
         ' <input type="date" name="day" required> ' . $tsel . '</select> <input name="extra" placeholder="giờ thêm" size="6"> <input name="note" placeholder="lý do" size="24">', 'Lưu', 'button button-primary') . '</p>';
    $late = $wpdb->get_results($wpdb->prepare("SELECT e.name,DATE_FORMAT(s.day,'%%d/%%m') d,s.note FROM " . lcc_t('shift') . " s JOIN " . lcc_t('emp') .
                                              " e ON e.id=s.emp_id WHERE s.day LIKE %s AND (s.note LIKE %s OR s.note LIKE %s) ORDER BY e.name,s.day", $m . '-%', '%trễ%', '%CHƯA RA CA%'));
    if ($late) {
        echo '<h3>Đi trễ &gt; ' . LCC_GRACE_MIN . "' / quên ra ca tháng " . esc_html($m) . '</h3><table class="widefat striped" style="max-width:900px"><tbody>';
        foreach ($late as $l) echo '<tr><td>' . esc_html($l->name) . '</td><td>' . esc_html($l->d) . '</td><td>' . esc_html($l->note) . '</td></tr>';
        echo '</tbody></table>';
    }

    echo '<h2>3. Nhân viên</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Mã</th><th>Tên</th><th>Loại</th><th>Ngày đã điền tháng ' . esc_html($m) .
         '</th><th>PIN</th><th></th></tr></thead><tbody>';
    foreach ($emps as $e) {
        echo '<tr' . ($e->active ? '' : ' style="opacity:.5"') . '><td>' . esc_html($e->code) . '</td><td>' . esc_html($e->name) . '</td><td>' .
             ($e->role === 'intern' ? 'Thực tập' : 'Nhân viên') . '</td><td>' . (int) ($cnt[$e->id]->n ?? 0) . '</td><td>' . ($e->pin_hash ? 'đã đặt' : '<b style="color:#b32d2e">chưa có</b>') . '</td><td>' .
             $form('pin', '<input type="hidden" name="id" value="' . (int) $e->id . '"><input name="pin" placeholder="PIN mới" size="8">', 'Đổi PIN') . ' ' .
             $form('toggle', '<input type="hidden" name="id" value="' . (int) $e->id . '">', $e->active ? 'Ẩn (nghỉ việc)' : 'Hiện lại') . '</td></tr>';
    }
    echo '</tbody></table><p>Thêm nhân viên: ' . $form('emp', '<input name="code" placeholder="mã (vd: chau)" size="10"> <input name="name" placeholder="Tên hiển thị" size="18"> <select name="role"><option value="staff">Nhân viên</option><option value="intern">Thực tập</option></select> <input name="pin" placeholder="PIN" size="8">', 'Thêm', 'button button-primary') . '</p>';

    $rel = lcc_latest_release();
    echo '<h2>Phiên bản</h2><p>Đang dùng <b>' . LCC_VER . '</b>' . ($rel ? ' · bản mới nhất trên GitHub: <b>' . esc_html($rel['ver']) . '</b>' : '') .
         ' ' . $form('check_update', '', 'Kiểm tra cập nhật ngay') . '</p>';
    echo '<h2>4. Khoá API cho tool tính lương</h2><p><code>' . esc_html(get_option('lcc_api_key')) . '</code> — chỉ dùng cho tool trên máy Mac, không chia sẻ.</p></div>';
}
