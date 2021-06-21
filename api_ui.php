<?php

require_once 'engine/init.php';
include 'layout/overall/header.php';

require_once 'lua_api/sender.php';

$protocol_API = new Tibia_protocol_API();

$action = $_GET['action'];

foreach ($_GET as $k => $v) {
    if ($k !== 'action') {
        $header = $k;
        $value = $v;
    }
}

$header = ($header ? $header : 'lua');
$value = ($value ? $value : '');

$response = "";
$online = true;

$codes = array(
    'pong' => 100,
    'execute' => 101,
    'request' => 102,
);

try {
    $response = $protocol_API->send_request($codes['pong']);
    if ($response) {
        $response = "Server online!";
    }
} catch (exception $e) {
    $online = false;
}

if ($online && isset($action)) {
    if (isset($codes[$action])) {
        $response = $protocol_API->send_request($codes[$action], $action, $header, $value);
        if ($response) {
            $response = substr($response, 5 + strlen($action) + 3);
        }
    }
}

echo '<!-- Action -->
<div class="parent">
    <form action="" method="get" onsubmit="send()">
        <p>Action</p>
        <select id="action" name="action" onchange="switchLayout()">
          <option value="execute">Execute</option>
          <option value="request">HTTP request</option>
        </select>
        <input type="submit" value="Send">
        <br>
        <br>
        <div id="msg" class="left">
            <p>Send to server</p>
            <input type="text" id="header" value="'.$header.'" disabled="disabled" style="width: 325px">
            <br><br>
            <textarea id="hvalue" name="'.$header.'">'.$value.'</textarea>
            <br><br>
        </div>
    </form>
    <div id="resp" class="right">
        <p>Response from server</p>
        <input type="text" value="'.($action ? $action.' action returned:' : 'n/a').'" disabled="disabled" style="width: 325px">
        <br><br>
        <textarea disabled="disabled">'.($online ? $response : "Server is offline...").'</textarea>
        <br>
    </div>
    <br>
    <br>
</div>
<style type="text/css">
    textarea {
        width: 325px;
        height: 200px;
    }

    p {
        padding: 0 0 5px 0;
        margin-bottom: 0;
        font-weight: bold;
    }

    .parent {
        clear: both;
    }

    .left, .center, .right{
        float: left;
    }
</style>
<script>
function send() {
    document.getElementById("hvalue").name = document.getElementById("header").value;
}

function switchLayout() {
    if (document.getElementById("action").value === "execute") {
        document.getElementById("header").value = "lua";
        document.getElementById("header").disabled = "disabled";
    } else {
        document.getElementById("header").disabled = "";
    }
}
</script>';

include 'layout/overall/footer.php';

?>
