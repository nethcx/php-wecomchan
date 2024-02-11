<?php
// config
// ======================================
define('SENDKEY', 'set_a_sendkey');
define('WECOM_CID', '企业微信公司ID');
define('WECOM_SECRET', '企业微信应用Secret');
define('WECOM_AID', '企业微信应用ID');
define('WECOM_TOUID', '@all');

// 以下配置需要有 redis 服务和 phpredis 扩展
define('REDIS_ON', false);
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', '6379');
define('REDIS_EXPIRED', '7000');
define('REDIS_KEY', 'wecom_access_token');

// code
// ======================================

if (strlen(@$_REQUEST['sendkey'])  < 1 || @$_REQUEST['sendkey'] != SENDKEY) {
    die('bad params');
}

header("Content-Type: application/json; charset=UTF-8");

if (isset($_REQUEST['type']) && $_REQUEST['type'] === 'news') {
    if (!isset($_REQUEST['title']) || !isset($_REQUEST['msg']) || !isset($_REQUEST['picurl'])) {
        die('invalid parameters, title, msg, and picurl are required for news type');
    }
    echo send_to_wecom_msgpic($_REQUEST['title'], $_REQUEST['msg'], $_REQUEST['picurl']);
} elseif (isset($_REQUEST['type']) && $_REQUEST['type'] === 'text') {
    if (!isset($_REQUEST['text'])) {
        die('invalid parameters, text is required for text type');
    }
    echo send_to_wecom($_REQUEST['text']);
} else {
    die('invalid parameters, type must be provided and must be either "news" or "text"');
}

function redis()
{
    if (!isset($GLOBALS['REDIS_INSTANCE']) || !$GLOBALS['REDIS_INSTANCE']) {
        $GLOBALS['REDIS_INSTANCE'] = new Redis();
        $GLOBALS['REDIS_INSTANCE']->connect(REDIS_HOST, REDIS_PORT);
    }

    return $GLOBALS['REDIS_INSTANCE'];
}

function send_to_wecom($text)
{
    return send_to_wecom_internal($text, "text");
}

function send_to_wecom_msgpic($title, $msg, $picurl)
{
    $text = $msg;
    $title = $_REQUEST['title'];
    $picurl = $_REQUEST['picurl'];

    return send_to_wecom_internal($text, "news", $title, $picurl);
}

function send_to_wecom_internal($text, $msgtype, $title = null, $picurl = null)
{
    global $wecom_cid, $wecom_secret, $wecom_aid, $wecom_touid;

    $access_token = false;
    // 如果启用redis作为缓存
    if (REDIS_ON) {
        $access_token = redis()->get(REDIS_KEY);
    }

    if (!$access_token) {
        $info = @json_decode(file_get_contents("https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid=".urlencode(WECOM_CID)."&corpsecret=".urlencode(WECOM_SECRET)), true);

        if ($info && isset($info['access_token']) && strlen($info['access_token']) > 0) {
            $access_token = $info['access_token'];
        }
    }

    if ($access_token) {
        $url = 'https://qyapi.weixin.qq.com/cgi-bin/message/send?access_token='.urlencode($access_token);
        $data = new \stdClass();
        $data->touser = WECOM_TOUID;
        $data->agentid = WECOM_AID;
        $data->msgtype = $msgtype;
        if ($msgtype === "text") {
            $data->text = ["content"=> $text];
        } elseif ($msgtype === "news") {
            $data->news = [
                "articles" => [
                    [
                        "title" => $title,
                        "description" => $text,
                        "url" => "",
                        "picurl" => $picurl
                    ]
                ]
            ];
        }
        $data_json = json_encode($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);

        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        if ($response !== false && REDIS_ON) {
            redis()->set(REDIS_KEY, $access_token, ['nx', 'ex'=>REDIS_EXPIRED]);
        }
        return $response;
    }

    return false;
}
?>
