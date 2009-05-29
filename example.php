<?php
require_once 'msn/msn.php';
$msn = new MSN();
$msn->loopTime = 2;
$msn->output   = false;
$msn->functions['messageReceived']     = messageReceived;
$msn->functions['friendStatusChanged'] = friendStatusChanged;
$msn->signIn('username', 'password', 'display_name');

function messageReceived($message, $convo) {
    if (strpos($message[3], 'TypingUser') !== false) {
        $convo->sendMessage('You are typing!');
    } else {
        $convo->sendMessage('You said "' . $message[5] . '"');
    }
}

function friendStatusChanged($friend, $status, $newName = '') {
    echo $friend . '(' . $newName . ') changed their status to ' . $status . '<br />';
    flush();
}
?>